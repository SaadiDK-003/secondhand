<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Resources\Pages\ListActivity;
use App\Filament\Resources\Pages\ListKeywords;
use App\Filament\Resources\Pages\ListUsers;
use App\Models\ActivityLog;
use App\Models\ProhibitedKeyword;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminTablesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->withTwoFactor()->create(['is_admin' => true, 'name' => 'Review Administrator']);
        $this->actingAs($admin)->withSession(['admin_2fa' => $admin->id.':'.hash('sha256', $admin->two_factor_secret)]);

        return $admin;
    }

    public function test_keyword_statuses_actions_and_toggles_are_readable_and_audited(): void
    {
        $admin = $this->admin();
        $active = ProhibitedKeyword::create(['phrase' => 'restricted phrase', 'is_active' => true]);
        $disabled = ProhibitedKeyword::create(['phrase' => 'disabled phrase', 'is_active' => false]);

        $table = Livewire::test(ListKeywords::class)
            ->assertSee('Actions')->assertSee('Disable')->assertSee('Enable')
            ->assertTableColumnStateSet('is_active', 'Active', $active)
            ->assertTableColumnStateSet('is_active', 'Disabled', $disabled);

        $table->filterTable('is_active', true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$disabled])
            ->filterTable('is_active', false)->assertCanSeeTableRecords([$disabled])->assertCanNotSeeTableRecords([$active])
            ->removeTableFilters();

        $table->callAction(TestAction::make('toggle')->table($active))->assertHasNoActionErrors();
        $this->assertFalse($active->fresh()->is_active);
        $log = ActivityLog::sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('restricted phrase', $log->details['phrase']);
        $this->assertFalse($log->details['active']);

        $table->callAction(TestAction::make('toggle')->table($active))->assertHasNoActionErrors();
        $this->assertTrue($active->fresh()->is_active);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_account_filter_combines_with_name_and_email_search(): void
    {
        $admin = $this->admin();
        $active = User::factory()->create(['name' => 'Shared Name', 'email' => 'active@example.test']);
        $suspended = User::factory()->create(['name' => 'Shared Name', 'email' => 'inactive@example.test', 'suspended_at' => now()]);

        Livewire::test(ListUsers::class)
            ->assertSee('Account status')->assertSee('Actions')
            ->filterTable('status', 'active')->searchTable('Shared Name')
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$suspended, $admin])
            ->filterTable('status', 'suspended')
            ->assertCanSeeTableRecords([$suspended])->assertCanNotSeeTableRecords([$active, $admin])
            ->searchTable('inactive@example.test')->assertCanSeeTableRecords([$suspended])
            ->searchTable('active@example.test')->filterTable('status', 'active')
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$suspended])
            ->searchTable('')->removeTableFilters()->assertCanSeeTableRecords([$active, $suspended, $admin]);
    }

    public function test_only_applicable_account_actions_are_visible_and_restore_updates_status(): void
    {
        $admin = $this->admin();
        $active = User::factory()->create();
        $suspended = User::factory()->create(['suspended_at' => now()]);

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('status', 'Active', $active)
            ->assertTableColumnStateSet('status', 'Suspended', $suspended)
            ->assertActionVisible(TestAction::make('suspend')->table($active))
            ->assertActionHidden(TestAction::make('restore')->table($active))
            ->assertActionHidden(TestAction::make('suspend')->table($suspended))
            ->assertActionVisible(TestAction::make('restore')->table($suspended))
            ->assertActionHidden(TestAction::make('suspend')->table($admin))
            ->assertActionHidden(TestAction::make('restore')->table($admin))
            ->callAction(TestAction::make('restore')->table($suspended), data: ['reason' => 'Appeal accepted'])
            ->assertHasNoActionErrors()
            ->assertTableColumnStateSet('status', 'Active', $suspended)
            ->assertActionVisible(TestAction::make('suspend')->table($suspended))
            ->assertActionHidden(TestAction::make('restore')->table($suspended));

        $this->assertNull($suspended->fresh()->suspended_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.restore', 'actor_id' => $admin->id, 'target_id' => $suspended->id]);
    }

    public function test_activity_log_uses_names_plain_language_and_safe_escaped_text(): void
    {
        $admin = $this->admin();
        $details = ['phrase' => '<img src=x onerror=alert(1)>', 'active' => false];
        $keyword = ActivityLog::create(['actor_id' => $admin->id, 'action' => 'keyword.update', 'target_type' => 'keyword', 'target_id' => 23, 'details' => $details]);
        $storedDetails = $keyword->fresh()->details;
        $listing = ActivityLog::create(['actor_id' => $admin->id, 'action' => 'listing.approve', 'target_type' => 'listing', 'target_id' => 24, 'details' => ['reason' => 'Photos reviewed', 'from' => 'pending', 'to' => 'published']]);
        $console = ActivityLog::create(['action' => 'admin.grant.cli', 'target_type' => 'user', 'target_id' => $admin->id, 'details' => ['source' => 'console']]);

        Livewire::test(ListActivity::class)
            ->assertSee('Review Administrator')->assertSee('Server command')
            ->assertSee('Keyword disabled')->assertSee('Listing approved')->assertSee('Admin access granted')
            ->assertSee('Keyword #23')->assertSee('Pending → Published')->assertSee('Reason: Photos reviewed')
            ->assertSee('Administrator permissions granted using a server command.')
            ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;')
            ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
            ->filterTable('action', 'keyword.update')
            ->assertCanSeeTableRecords([$keyword])->assertCanNotSeeTableRecords([$listing, $console])
            ->removeTableFilters()->searchTable('Listing approved')
            ->assertCanSeeTableRecords([$listing])->assertCanNotSeeTableRecords([$keyword, $console])
            ->searchTable('Review Administrator')
            ->assertCanSeeTableRecords([$keyword, $listing])->assertCanNotSeeTableRecords([$console])
            ->searchTable('Keyword disabled')
            ->assertCanSeeTableRecords([$keyword])->assertCanNotSeeTableRecords([$listing, $console])
            ->searchTable('Keyword enabled')->assertCanNotSeeTableRecords([$keyword, $listing, $console])
            ->searchTable('Review Administrator')->filterTable('action', 'listing.approve')
            ->assertCanSeeTableRecords([$listing])->assertCanNotSeeTableRecords([$keyword, $console]);

        $this->assertSame($storedDetails, $keyword->fresh()->details);
        $this->assertSame('keyword.update', $keyword->fresh()->action);
    }
}
