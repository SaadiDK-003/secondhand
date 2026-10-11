<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_local_login_shows_working_demo_credentials_and_accounts_can_sign_in(): void
    {
        $this->app->instance('env', 'local');
        foreach (config('demo.accounts') as $account) {
            User::factory()->create([
                'email' => $account['email'],
                'password' => config('demo.password'),
            ]);
        }

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('demoCredentials.password', config('demo.password'))
            ->has('demoCredentials.accounts', 2)
            ->where('demoCredentials.accounts.0.email', 'demo-buyer@example.invalid')
            ->where('demoCredentials.accounts.1.email', 'demo-seller@example.invalid'));

        foreach (config('demo.accounts') as $account) {
            $this->withSession(['_token' => 'local-demo-login-token'])->post(route('login.store'), [
                'email' => $account['email'],
                'password' => config('demo.password'),
                '_token' => 'local-demo-login-token',
            ])->assertRedirect(route('dashboard', absolute: false));

            $user = User::where('email', $account['email'])->firstOrFail();
            $this->assertAuthenticatedAs($user);
            $this->assertFalse($user->is_admin);
            $this->post(route('logout'), ['_token' => session()->token()]);
            $this->assertGuest();
        }
    }

    /** @return array<string, array{string}> */
    public static function nonLocalEnvironments(): array
    {
        return ['production' => ['production'], 'staging' => ['staging'], 'testing' => ['testing']];
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_demo_credentials_are_never_sent_outside_local(string $environment): void
    {
        $this->app->instance('env', $environment);
        User::factory()->create([
            'email' => config('demo.accounts.0.email'),
            'password' => config('demo.password'),
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee(config('demo.password'))
            ->assertDontSee(config('demo.accounts.0.email'))
            ->assertInertia(fn (Assert $page) => $page->missing('demoCredentials'));
    }

    public function test_local_login_omits_unavailable_demo_accounts(): void
    {
        $this->app->instance('env', 'local');
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demoCredentials', null));

        $buyer = User::factory()->create([
            'email' => config('demo.accounts.0.email'),
            'password' => 'changed-password',
        ]);
        $seller = User::factory()->create([
            'email' => config('demo.accounts.1.email'),
            'password' => config('demo.password'),
            'is_admin' => true,
        ]);
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demoCredentials', null));

        $buyer->forceFill(['password' => config('demo.password'), 'suspended_at' => now()])->save();
        $seller->forceFill(['is_admin' => false, 'two_factor_secret' => encrypt('test-secret')])->save();
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('demoCredentials', null));
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_demo_seeding_is_blocked_outside_local(string $environment): void
    {
        $this->app->instance('env', $environment);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo accounts are restricted to the local preview database.');
        $this->app->make(DemoAccountSeeder::class)->run();
    }

    public function test_demo_seeding_is_blocked_outside_the_preview_database(): void
    {
        $this->app->instance('env', 'local');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo accounts are restricted to the local preview database.');
        $this->app->make(DemoAccountSeeder::class)->run();
    }

    #[DataProvider('nonLocalEnvironments')]
    public function test_demo_accounts_cannot_sign_in_or_reuse_a_session_outside_local(string $environment): void
    {
        $this->app->instance('env', $environment);
        foreach (config('demo.accounts') as $account) {
            $user = User::factory()->create([
                'email' => $account['email'],
                'password' => config('demo.password'),
            ]);
            $this->withSession(['_token' => 'non-local-demo-token'])->post(route('login.store'), [
                'email' => $user->email,
                'password' => config('demo.password'),
                '_token' => 'non-local-demo-token',
            ])->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->actingAs($user)->get('/messages')->assertForbidden();
            $this->assertGuest();
        }
    }
}
