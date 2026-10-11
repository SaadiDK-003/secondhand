<?php

namespace Tests\Feature\Marketplace;

use App\Models\Conversation;
use App\Models\User;
use App\Services\SellerResponseSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SellerResponseSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_rate_counts_conversations_and_excludes_new_old_and_other_sellers(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        foreach (range(1, 5) as $index) {
            $this->conversation($seller, $buyer, now()->subDays(2), $index <= 3);
        }
        $this->conversation($seller, $buyer, now()->subHour(), true);
        $this->conversation($seller, $buyer, now()->subDays(91), true);
        $this->conversation(User::factory()->create(), $buyer, now()->subDays(2), true);
        $summary = app(SellerResponseSummary::class)->forSeller($seller);
        $this->assertSame(['sample_size' => 5, 'replied_percent' => 60], $summary);
    }

    public function test_small_samples_are_hidden_and_cached_data_expires(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $service = app(SellerResponseSummary::class);
        foreach (range(1, 4) as $index) {
            $this->conversation($seller, $buyer, now()->subDays(2), true);
        }
        $this->assertNull($service->forSeller($seller));
        $last = $this->conversation($seller, $buyer, now()->subDays(2), false);
        Cache::forget('seller-response.v1.'.$seller->id);
        $this->assertSame(80, $service->forSeller($seller)['replied_percent']);
        $last->messages()->create(['sender_id' => $seller->id, 'body' => 'Private reply', 'created_at' => now()]);
        $this->assertSame(80, $service->forSeller($seller)['replied_percent']);
        $this->travel(6)->minutes();
        $this->assertSame(100, $service->forSeller($seller)['replied_percent']);
    }

    private function conversation(User $seller, User $buyer, $started, bool $reply): Conversation
    {
        $conversation = Conversation::create(['seller_id' => $seller->id, 'buyer_id' => $buyer->id, 'listing_title' => 'Private item', 'created_at' => $started]);
        $conversation->messages()->create(['sender_id' => $buyer->id, 'body' => 'Private inquiry', 'created_at' => $started]);
        if ($reply) {
            foreach (range(1, 2) as $index) {
                $conversation->messages()->create(['sender_id' => $seller->id, 'body' => 'Private reply', 'created_at' => $started->copy()->addHour()]);
            }
        }

        return $conversation;
    }
}
