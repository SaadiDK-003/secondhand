<?php

namespace Tests\Feature\Marketplace;

use App\Actions\ModerateMarketplace;
use App\Actions\SubmitListing;
use App\Enums\ListingStatus;
use App\Filament\Resources\Pages\ListListings;
use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use App\Services\ModerationChecks;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->actingAs($user)->withSession(['admin_2fa' => $user->id.':'.hash('sha256', $user->two_factor_secret)]);

        return $user;
    }

    private function pending(): Listing
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Pending]);
        $image = $listing->images()->create(['disk' => 'listing_images', 'directory' => 'test-moderation-'.$listing->id, 'original_path' => 'test.jpg', 'sort_order' => 0]);
        $image->forceFill(['status' => 'ready'])->save();

        return $listing;
    }

    public function test_panel_rejects_guest_non_admin_and_admin_without_verified_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user)->get('/admin')->assertRedirect('/settings/security');
        $user = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->actingAs($user)->get('/admin')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_panel_and_all_resource_pages_render_with_verified_session(): void
    {
        $this->admin();
        foreach (['/admin', '/admin/listings', '/admin/users', '/admin/reports', '/admin/keywords', '/admin/activities'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_real_fortify_two_factor_login_grants_admin_session(): void
    {
        $user = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk();
    }

    public function test_inertia_admin_navigation_performs_full_page_handoff(): void
    {
        $this->admin();
        $this->get('/admin', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', url('/admin'));
    }

    public function test_admin_mutations_reject_regular_user_and_stale_two_factor_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        try {
            app(ModerateMarketplace::class)->listing($user, $this->pending(), 'approve', 'Reviewed');
            $this->fail('Unauthorized');
        } catch (AuthorizationException) {
        }
        $admin = $this->admin();
        $admin->forceFill(['two_factor_secret' => encrypt('CHANGED')])->save();
        $this->assertFalse($admin->hasAdminSession());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_approve_reject_and_block_are_atomic_and_audited(): void
    {
        $admin = $this->admin();
        $listing = $this->pending();
        $action = app(ModerateMarketplace::class);
        $action->listing($admin, $listing, 'approve', 'Photos and item reviewed');
        $this->assertTrue(Listing::published()->whereKey($listing->id)->exists());
        $this->assertNotNull($listing->fresh()->expires_at);
        $action->listing($admin, $listing, 'block', 'Prohibited item confirmed');
        $this->assertFalse(Listing::published()->whereKey($listing->id)->exists());
        $rejected = $this->pending();
        $action->listing($admin, $rejected, 'reject', 'Please replace the unclear photos');
        $this->assertSame(ListingStatus::Rejected, $rejected->fresh()->status);
        $this->assertDatabaseCount('activity_logs', 3);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $admin->id, 'action' => 'listing.block', 'target_id' => $listing->id]);
    }

    public function test_approval_requires_correct_state_ready_photos_and_active_seller(): void
    {
        $admin = $this->admin();
        $action = app(ModerateMarketplace::class);
        $draft = Listing::factory()->create();
        $noPhotos = Listing::factory()->create(['status' => ListingStatus::Pending]);
        $suspended = $this->pending();
        $suspended->user->forceFill(['suspended_at' => now()])->save();
        foreach ([$draft, $noPhotos, $suspended] as $listing) {
            try {
                $action->listing($admin, $listing, 'approve', 'Reviewed');
                $this->fail('Invalid approval');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_filament_listing_action_calls_authorized_service(): void
    {
        $this->admin();
        $listing = $this->pending();
        Livewire::test(ListListings::class)->callAction(TestAction::make('approve')->table($listing), data: ['reason' => 'Verified in review'])->assertHasNoActionErrors();
        $this->assertSame(ListingStatus::Published, $listing->fresh()->status);
    }

    public function test_suspension_hides_ads_ends_active_sessions_and_prevents_login(): void
    {
        $admin = $this->admin();
        $listing = $this->pending();
        app(ModerateMarketplace::class)->listing($admin, $listing, 'approve', 'Reviewed');
        $seller = $listing->user;
        app(ModerateMarketplace::class)->suspend($admin, $seller, true, 'Repeated scams');
        $this->get('/ad/'.$listing->slug)->assertNotFound();
        $this->get('/')->assertInertia(fn (Assert $p) => $p->has('listings', 0));
        $this->actingAs($seller->fresh())->get('/my-listings')->assertForbidden();
        $this->assertGuest();
        $this->post('/login', ['email' => $seller->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_restore_and_admin_suspension_protection(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['suspended_at' => now()]);
        app(ModerateMarketplace::class)->suspend($admin, $user, false, 'Appeal accepted');
        $this->assertNull($user->fresh()->suspended_at);
        $this->expectException(ValidationException::class);
        app(ModerateMarketplace::class)->suspend($admin, $admin, true, 'Mistake');
    }

    public function test_reports_require_auth_validate_target_and_are_idempotent(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $this->post('/listings/'.$listing->id.'/report', ['reason' => 'scam'])->assertRedirect('/login');
        $reporter = User::factory()->create();
        $this->actingAs($reporter);
        $this->post('/listings/'.$listing->id.'/report', ['reason' => 'scam', 'reporter_id' => $listing->user_id, 'status' => 'resolved'])->assertRedirect();
        $this->post('/listings/'.$listing->id.'/report', ['reason' => 'scam'])->assertRedirect();
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseHas('reports', ['reporter_id' => $reporter->id, 'status' => 'open']);
        $this->postJson('/users/'.$reporter->id.'/report', ['reason' => 'scam'])->assertForbidden();
        $this->postJson('/users/'.$listing->user_id.'/report', ['reason' => 'fake'])->assertUnprocessable();
        $this->post('/users/'.$listing->user_id.'/report', ['reason' => 'harassment', 'details' => 'Abusive messages'])->assertRedirect();
        $this->assertDatabaseCount('reports', 2);
    }

    public function test_report_resolution_is_audited_and_cannot_be_rewritten(): void
    {
        $admin = $this->admin();
        $report = Report::create(['reporter_id' => $admin->id, 'target_type' => 'user', 'target_id' => 999, 'reason' => 'scam']);
        app(ModerateMarketplace::class)->resolve($admin, $report, 'resolved', 'Account investigated');
        $this->assertDatabaseHas('activity_logs', ['action' => 'report.resolved', 'target_id' => $report->id]);
        $this->expectException(ValidationException::class);
        app(ModerateMarketplace::class)->resolve($admin, $report, 'dismissed', 'Rewrite');
    }

    public function test_keywords_urls_and_duplicates_flag_submission_for_human_review(): void
    {
        $admin = $this->admin();
        app(ModerateMarketplace::class)->keyword($admin, 'prohibited phrase', true);
        $listing = $this->pending();
        $listing->forceFill(['status' => ListingStatus::Draft, 'title' => 'Prohibited phrase offer', 'description' => 'See https://example.test'])->save();
        Listing::factory()->create(['user_id' => $listing->user_id, 'title' => $listing->title, 'status' => ListingStatus::Pending]);
        app(SubmitListing::class)->handle($listing->user, $listing);
        $this->assertCount(3, $listing->fresh()->moderation_flags);
        $this->assertSame(ListingStatus::Pending, $listing->fresh()->status);
        app(ModerateMarketplace::class)->keyword($admin, 'prohibited phrase', false);
        $this->assertCount(2, app(ModerationChecks::class)->flags($listing));
    }

    public function test_admin_flags_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_admin' => true, 'suspended_at' => now()])->save();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertNull($user->fresh()->suspended_at);
    }
}
