<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_dashboard_or_its_api(): void
    {
        $this->get('/horizon')->assertRedirect('/login');
        $this->getJson('/horizon/api/stats')->assertUnauthorized();
    }

    public function test_regular_users_and_admins_without_confirmed_session_cannot_open_horizon(): void
    {
        foreach ([User::factory()->create(), User::factory()->create(['is_admin' => true]),
            User::factory()->withTwoFactor()->create(['is_admin' => true])] as $user) {
            $this->actingAs($user)->get('/horizon')->assertForbidden();
            $this->getJson('/horizon/api/stats')->assertForbidden();
        }
    }

    public function test_verified_admin_can_open_dashboard_and_response_is_private(): void
    {
        $user = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->actingAs($user)->withSession(['admin_2fa' => $user->id.':'.hash('sha256', $user->two_factor_secret)]);
        $this->get('/horizon')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_suspended_admin_and_stale_two_factor_session_cannot_open_dashboard(): void
    {
        $user = User::factory()->withTwoFactor()->create(['is_admin' => true, 'suspended_at' => now()]);
        $this->actingAs($user)->withSession(['admin_2fa' => $user->id.':'.hash('sha256', $user->two_factor_secret)]);
        $this->get('/horizon')->assertForbidden();
        $this->assertGuest();
        $user->forceFill(['suspended_at' => null])->save();
        $this->actingAs($user)->withSession(['admin_2fa' => 'stale']);
        $this->get('/horizon')->assertForbidden();
    }

    public function test_local_environment_does_not_bypass_horizon_authorization(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->get('/horizon')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    }
}
