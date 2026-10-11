<?php

namespace Tests\Feature\Marketplace;

use App\Filament\Widgets\SiteHealthWidget;
use App\Models\User;
use App\Services\SiteHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class SiteHealthAccessTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->actingAs($user)->withSession(['admin_2fa' => $user->id.':'.hash('sha256', $user->two_factor_secret)]);

        return $user;
    }

    public function test_health_page_requires_an_active_admin_with_a_confirmed_two_factor_session(): void
    {
        $this->mock(SiteHealth::class)->shouldNotReceive('snapshot');
        $this->get('/admin/site-health')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/site-health')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/site-health')->assertRedirect('/settings/security');
        $this->actingAs(User::factory()->withTwoFactor()->create(['is_admin' => true]))->get('/admin/site-health')->assertRedirect('/login');
    }

    public function test_health_page_and_admin_navigation_render_for_authorized_admins(): void
    {
        $this->admin();
        $this->get('/admin/site-health')->assertOk()->assertSee('View marketplace')->assertSee('Site health');
        $this->get('/admin')->assertOk()->assertSee('Welcome back')->assertSee('View marketplace');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', true));
        $this->actingAs(User::factory()->create()->fresh())->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));
    }

    public function test_livewire_health_refresh_rechecks_permission_after_admin_access_is_revoked(): void
    {
        $admin = $this->admin();
        $this->mock(SiteHealth::class)->shouldReceive('snapshot')->once()->andReturn([
            'checked_at' => now()->timestamp, 'status' => 'healthy', 'healthy' => 0, 'total' => 0, 'checks' => [],
        ]);
        $widget = Livewire::test(SiteHealthWidget::class)->assertSee('Site health');
        $admin->forceFill(['is_admin' => false])->save();
        $widget->call('$refresh')->assertForbidden();
    }
}
