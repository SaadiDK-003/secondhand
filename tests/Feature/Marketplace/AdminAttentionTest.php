<?php

namespace Tests\Feature\Marketplace;

use App\Actions\ModerateMarketplace;
use App\Enums\ListingStatus;
use App\Filament\Resources\ListingsResource;
use App\Filament\Resources\ReportsResource;
use App\Filament\Widgets\ModerationStats;
use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->actingAs($admin)->withSession(['admin_2fa' => $admin->id.':'.hash('sha256', $admin->two_factor_secret)]);

        return $admin;
    }

    public function test_zero_counts_leave_cards_neutral_and_hide_sidebar_badges(): void
    {
        $this->admin();
        Livewire::test(ModerationStats::class)
            ->assertSee('All reviews are up to date')->assertSee('No open reports')
            ->assertDontSeeHtml('sh-stat-warning')->assertDontSeeHtml('sh-stat-danger');
        $this->assertNull(ListingsResource::getNavigationBadge());
        $this->assertNull(ReportsResource::getNavigationBadge());
    }

    public function test_attention_counts_only_include_pending_listings_and_open_reports_and_clear_after_review(): void
    {
        $admin = $this->admin();
        $pending = Listing::factory()->create(['status' => ListingStatus::Pending]);
        Listing::factory()->create(['status' => ListingStatus::Rejected]);
        $report = Report::create(['reporter_id' => $admin->id, 'target_type' => 'listing', 'target_id' => $pending->id, 'reason' => 'scam', 'status' => 'open']);
        Report::create(['reporter_id' => $admin->id, 'target_type' => 'user', 'target_id' => $admin->id, 'reason' => 'spam', 'status' => 'resolved']);

        $widget = Livewire::test(ModerationStats::class)
            ->assertSeeHtml('sh-stat-warning')->assertSeeHtml('sh-stat-danger')
            ->assertSee('Ads waiting for your decision')->assertSee('Reports need your attention')
            ->assertSeeHtml(url('/admin/listings?filters[status][value]=pending'))
            ->assertSeeHtml(url('/admin/reports?filters[status][value]=open'));
        $this->assertSame('1', ListingsResource::getNavigationBadge());
        $this->assertSame('warning', ListingsResource::getNavigationBadgeColor());
        $this->assertSame('1', ReportsResource::getNavigationBadge());
        $this->assertSame('danger', ReportsResource::getNavigationBadgeColor());
        $this->get('/admin')->assertOk()->assertSee('Listings awaiting review')->assertSee('Open reports awaiting attention');

        app(ModerateMarketplace::class)->listing($admin, $pending, 'reject', 'Photos need replacing');
        $widget->call('$refresh')->assertDontSeeHtml('sh-stat-warning')->assertSeeHtml('sh-stat-danger');
        $this->assertNull(ListingsResource::getNavigationBadge());
        $this->assertSame('1', ReportsResource::getNavigationBadge());

        app(ModerateMarketplace::class)->resolve($admin, $report, 'resolved', 'Report investigated');
        $widget->call('$refresh')->assertDontSeeHtml('sh-stat-warning')->assertDontSeeHtml('sh-stat-danger')->assertSee('No open reports');
        $this->assertNull(ReportsResource::getNavigationBadge());
    }

    public function test_attention_counts_are_hidden_from_guests_and_regular_members(): void
    {
        Listing::factory()->create(['status' => ListingStatus::Pending]);
        $this->assertNull(ListingsResource::getNavigationBadge());
        $this->assertNull(ReportsResource::getNavigationBadge());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(ModerationStats::canView());
        $this->assertNull(ListingsResource::getNavigationBadge());
        $this->assertNull(ReportsResource::getNavigationBadge());
    }

    public function test_attention_widget_rechecks_admin_permission_on_refresh(): void
    {
        $admin = $this->admin();
        $widget = Livewire::test(ModerationStats::class);
        $admin->forceFill(['is_admin' => false])->save();
        $widget->call('$refresh')->assertForbidden();
    }
}
