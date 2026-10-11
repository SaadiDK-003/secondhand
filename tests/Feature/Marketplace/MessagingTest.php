<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Actions\DeleteAccount;
use App\Enums\ListingStatus;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private function listing(): Listing
    {
        return Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
    }

    private function start(User $buyer, Listing $listing): Conversation
    {
        $this->actingAs($buyer)->post('/listings/'.$listing->id.'/messages', ['body' => 'Is this available?'])->assertRedirect();

        return Conversation::where('buyer_id', $buyer->id)->where('listing_id', $listing->id)->firstOrFail();
    }

    public function test_login_is_required_for_inbox_start_send_and_block(): void
    {
        $listing = $this->listing();
        foreach (['/messages', '/messages/status', '/blocked-users'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/listings/'.$listing->id.'/messages', ['body' => 'Hello'])->assertRedirect('/login');
        $this->put('/blocked-users/'.$listing->user_id)->assertRedirect('/login');
    }

    public function test_start_reuses_conversation_and_ignores_spoofed_ownership(): void
    {
        $listing = $this->listing();
        $buyer = User::factory()->create();
        $c = $this->start($buyer, $listing);
        $this->post('/listings/'.$listing->id.'/messages', ['body' => 'Can I view it?', 'sender_id' => $listing->user_id, 'seller_id' => $buyer->id])->assertRedirect('/messages/'.$c->id);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('messages', ['conversation_id' => $c->id, 'sender_id' => $buyer->id, 'body' => 'Can I view it?']);
        $this->actingAs($listing->user)->post('/messages/'.$c->id, ['body' => 'Yes'])->assertRedirect();
        $this->get('/messages')->assertInertia(fn (Assert $p) => $p->has('conversations.data', 1)->where('conversations.data.0.other_name', $buyer->name)->missing('conversations.data.0.email'));
    }

    public function test_outsiders_cannot_read_send_or_list_others_conversations(): void
    {
        $c = $this->start(User::factory()->create(), $this->listing());
        $this->actingAs(User::factory()->create())->get('/messages/'.$c->id)->assertForbidden();
        $this->post('/messages/'.$c->id, ['body' => 'Intrusion'])->assertForbidden();
        $this->get('/messages')->assertInertia(fn (Assert $p) => $p->has('conversations.data', 0));
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_cannot_start_for_self_private_future_or_expired_ads(): void
    {
        $listing = $this->listing();
        $this->actingAs($listing->user)->post('/listings/'.$listing->id.'/messages', ['body' => 'Hello'])->assertForbidden();
        $this->actingAs(User::factory()->create());
        foreach ([['status' => ListingStatus::Draft], ['status' => ListingStatus::Published, 'published_at' => now()->addHour()], ['published_at' => now()->subHour(), 'expires_at' => now()->subMinute()]] as $state) {
            $listing->forceFill($state)->save();
            $this->post('/listings/'.$listing->id.'/messages', ['body' => 'Hello'])->assertNotFound();
        }
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_empty_whitespace_and_oversized_messages_are_rejected_atomically(): void
    {
        $listing = $this->listing();
        $this->actingAs(User::factory()->create());
        foreach (['', '  ', str_repeat('x', 2001), ['not text']] as $body) {
            $this->postJson('/listings/'.$listing->id.'/messages', ['body' => $body])->assertUnprocessable();
        }
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_block_is_idempotent_stops_both_directions_and_only_owner_can_unblock(): void
    {
        $listing = $this->listing();
        $buyer = User::factory()->create();
        $c = $this->start($buyer, $listing);
        $this->put('/blocked-users/'.$listing->user_id, ['user_id' => $listing->user_id])->assertRedirect();
        $this->put('/blocked-users/'.$listing->user_id)->assertRedirect();
        $this->assertDatabaseCount('blocked_users', 1);
        $this->postJson('/messages/'.$c->id, ['body' => 'Blocked'])->assertUnprocessable();
        $this->postJson('/listings/'.$listing->id.'/messages', ['body' => 'New thread?'])->assertUnprocessable();
        $this->get('/messages/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('blocked', true)->where('blockedByMe', true)->has('messages.data', 1));
        $this->get('/blocked-users')->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->missing('users.data.0.email')->missing('users.data.0.mobile_number'));
        $this->actingAs($listing->user)->delete('/blocked-users/'.$buyer->id)->assertRedirect();
        $this->postJson('/messages/'.$c->id, ['body' => 'Still blocked'])->assertUnprocessable();
        $this->actingAs($buyer)->delete('/blocked-users/'.$listing->user_id)->assertRedirect();
        $this->post('/messages/'.$c->id, ['body' => 'Unblocked'])->assertRedirect();
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_blocking_applies_across_listings_and_self_block_is_rejected(): void
    {
        $listing = $this->listing();
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->put('/blocked-users/'.$buyer->id)->assertUnprocessable();
        $this->put('/blocked-users/'.$listing->user_id)->assertRedirect();
        $other = $this->listing();
        $other->forceFill(['user_id' => $listing->user_id])->save();
        $this->postJson('/listings/'.$other->id.'/messages', ['body' => 'Bypass'])->assertUnprocessable();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_history_survives_withdrawal_and_listing_deletion_without_private_ad_data(): void
    {
        $listing = $this->listing();
        $buyer = User::factory()->create();
        $c = $this->start($buyer, $listing);
        $listing->forceFill(['status' => ListingStatus::Draft, 'title' => 'PRIVATE EDIT'])->save();
        $this->get('/messages/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('conversation.listing_url', null)->where('conversation.title', $c->listing_title)->missing('conversation.other.email')->missing('conversation.other.mobile_number'));
        $listing->delete();
        $this->post('/messages/'.$c->id, ['body' => 'Was it sold?'])->assertRedirect();
        $this->assertDatabaseHas('conversations', ['id' => $c->id, 'listing_id' => null]);
    }

    public function test_messages_are_paginated_latest_first_and_content_remains_plain_text(): void
    {
        $c = $this->start(User::factory()->create(), $this->listing());
        for ($i = 0; $i < 31; $i++) {
            $c->messages()->create(['sender_id' => $c->buyer_id, 'body' => 'Message '.$i]);
        }
        $this->post('/messages/'.$c->id, ['body' => '<script>alert(1)</script>'])->assertRedirect();
        $this->get('/messages/'.$c->id)->assertInertia(fn (Assert $p) => $p->has('messages.data', 30)->where('messages.data.0.body', '<script>alert(1)</script>')->where('messages.data.0.mine', true));
        $this->get('/messages/'.$c->id.'?page=2')->assertInertia(fn (Assert $p) => $p->has('messages.data', 3));
    }

    public function test_account_deletion_cleans_conversations_messages_and_blocks(): void
    {
        $listing = $this->listing();
        $buyer = User::factory()->create();
        $this->start($buyer, $listing);
        $this->put('/blocked-users/'.$listing->user_id)->assertRedirect();
        app(DeleteAccount::class)->handle($buyer);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('blocked_users', 0);
    }

    public function test_message_sending_is_rate_limited(): void
    {
        $c = $this->start(User::factory()->create(), $this->listing());
        for ($i = 0; $i < 30; $i++) {
            $this->post('/messages/'.$c->id, ['body' => 'Hello'])->assertRedirect();
        }
        $this->post('/messages/'.$c->id, ['body' => 'Too many'])->assertTooManyRequests();
    }

    public function test_unread_counts_and_notifications_work_for_both_participants(): void
    {
        $listing = $this->listing();
        $buyer = User::factory()->create();
        $seller = $listing->user;
        $c = $this->start($buyer, $listing);
        $message = $c->messages()->firstOrFail();

        $this->getJson('/messages/status')->assertExactJson(['unread_count' => 0, 'latest' => null]);
        $this->actingAs($seller)->getJson('/messages/status')
            ->assertExactJson(['unread_count' => 1, 'latest' => ['id' => $message->id, 'conversation_id' => $c->id, 'sender_name' => $buyer->name]])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/messages')->assertInertia(fn (Assert $p) => $p
            ->where('conversations.data.0.unread_count', 1)->where('messaging.unread_count', 1));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('messaging.unread_count', 1));
        $this->get('/messages/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('messaging.unread_count', 0));
        $this->assertDatabaseMissing('messages', ['id' => $message->id, 'read_at' => null]);
        $this->getJson('/messages/status')->assertExactJson(['unread_count' => 0, 'latest' => null]);

        $this->post('/messages/'.$c->id, ['body' => 'Yes, it is available.'])->assertRedirect();
        $reply = $c->messages()->latest('id')->firstOrFail();
        // Opening your own reply cannot clear the other participant's unread count.
        $this->get('/messages/'.$c->id)->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $reply->id, 'read_at' => null]);
        $this->actingAs($buyer)->getJson('/messages/status')->assertExactJson([
            'unread_count' => 1, 'latest' => ['id' => $reply->id, 'conversation_id' => $c->id, 'sender_name' => $seller->name],
        ]);
        $this->get('/messages/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('messaging.unread_count', 0));
        $this->assertDatabaseMissing('messages', ['id' => $reply->id, 'read_at' => null]);

        $this->actingAs($seller)->post('/messages/'.$c->id, ['body' => 'You can view it tomorrow.'])->assertRedirect();
        $this->actingAs($buyer)->getJson('/messages/status')->assertJsonPath('unread_count', 1);
    }

    public function test_unread_status_is_private_and_cannot_be_spoofed_or_marked_by_outsiders(): void
    {
        $listing = $this->listing();
        $c = $this->start(User::factory()->create(), $listing);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson('/messages/status?user_id='.$listing->user_id)
            ->assertExactJson(['unread_count' => 0, 'latest' => null]);
        $this->get('/messages/'.$c->id)->assertForbidden();
        $this->assertDatabaseHas('messages', ['conversation_id' => $c->id, 'read_at' => null]);
        $this->actingAs($listing->user)->getJson('/messages/status')
            ->assertJsonPath('unread_count', 1)->assertJsonMissingPath('latest.body')
            ->assertJsonMissingPath('latest.email')->assertJsonMissingPath('latest.mobile_number');
    }

    public function test_only_displayed_incoming_messages_are_marked_read_with_paginated_history(): void
    {
        $listing = $this->listing();
        $c = $this->start(User::factory()->create(), $listing);
        foreach (range(1, 31) as $i) {
            $c->messages()->create(['sender_id' => $c->buyer_id, 'body' => 'Message '.$i]);
        }
        $this->actingAs($listing->user)->getJson('/messages/status')->assertJsonPath('unread_count', 32);
        $this->get('/messages/'.$c->id)->assertInertia(fn (Assert $p) => $p
            ->has('messages.data', 30)->where('messaging.unread_count', 2));
        $this->get('/messages')->assertInertia(fn (Assert $p) => $p->where('conversations.data.0.unread_count', 2));
        $this->get('/messages/'.$c->id.'?page=2')->assertInertia(fn (Assert $p) => $p
            ->has('messages.data', 2)->where('messaging.unread_count', 0));
    }

    public function test_one_account_gets_counts_across_buyer_and_seller_conversations(): void
    {
        $user = User::factory()->create();
        $ownListing = $this->listing();
        $ownListing->forceFill(['user_id' => $user->id])->save();
        $selling = $this->start(User::factory()->create(), $ownListing);
        $otherListing = $this->listing();
        $buying = $this->start($user, $otherListing);
        $this->actingAs($otherListing->user)->post('/messages/'.$buying->id, ['body' => 'A seller reply'])->assertRedirect();

        $this->actingAs($user)->getJson('/messages/status')->assertJsonPath('unread_count', 2);
        $this->get('/messages')->assertInertia(fn (Assert $p) => $p
            ->has('conversations.data', 2)->where('conversations.data.0.unread_count', 1)
            ->where('conversations.data.1.unread_count', 1));
        $this->get('/messages/'.$selling->id)->assertOk();
        $this->getJson('/messages/status')->assertJsonPath('unread_count', 1);
    }
}
