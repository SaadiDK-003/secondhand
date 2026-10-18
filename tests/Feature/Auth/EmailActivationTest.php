<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ActivateEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_registration_queues_a_hashed_expiring_code_and_blocks_account_features(): void
    {
        $this->freezeTime();
        $user = $this->registerPending();
        $notification = $this->notification($user);

        $this->assertNull($user->email_verified_at);
        $this->assertSame(now()->addHour()->timestamp, $user->registration_expires_at->timestamp);
        $this->assertSame(now()->addHour()->timestamp, $user->email_activation_expires_at->timestamp);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $notification->code);
        $this->assertTrue(Hash::check($notification->code, $user->email_activation_code_hash));
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertTrue($notification->afterCommit);
        $this->assertArrayNotHasKey('email_activation_code_hash', $user->toArray());
        $this->get('/email/verify')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/VerifyEmail')->where('email', $user->email)
            ->missing('auth.user.email_activation_code_hash'));
        foreach (['/dashboard', '/sell', '/messages', '/settings/profile', '/admin', '/horizon'] as $path) {
            $this->get($path)->assertRedirect('/email/verify');
        }
        $this->post('/listings', [])->assertRedirect('/email/verify');
        $this->assertDatabaseCount('listings', 0);
        $this->getJson('/messages/status')->assertForbidden();
    }

    public function test_correct_code_activates_only_the_authenticated_account_and_clears_secrets(): void
    {
        $user = $this->registerPending();
        $other = User::factory()->unverified()->create();
        Event::fake([Verified::class]);
        $this->post('/email/verify', ['code' => $this->notification($user)->code, 'user_id' => $other->id])
            ->assertRedirect('/dashboard');
        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($other->fresh()->email_verified_at);
        foreach (['registration_expires_at', 'email_activation_code_hash', 'email_activation_expires_at', 'email_activation_sent_at'] as $attribute) {
            $this->assertNull($user->$attribute);
        }
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->get('/dashboard')->assertOk();
        $this->post('/email/verify', ['code' => '111111'])->assertRedirect('/dashboard');
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_wrong_and_malformed_codes_do_not_activate_an_account(): void
    {
        $user = $this->registerPending();
        foreach (['000000', ['123456'], '<script>alert(1)</script>', '1234567', "123456\n"] as $code) {
            $this->post('/email/verify', ['code' => $code])->assertSessionHasErrors('code');
        }
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_code_attempts_are_rate_limited_per_account_even_if_ip_changes(): void
    {
        $user = $this->registerPending();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])->post('/email/verify', ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100'])
            ->post('/email/verify', ['code' => $this->notification($user)->code])->assertTooManyRequests();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_cooldown_is_persisted_and_old_codes_are_invalidated(): void
    {
        $this->freezeTime();
        $user = $this->registerPending();
        $first = $this->notification($user);
        $deadline = $user->registration_expires_at;
        $firstHash = $user->email_activation_code_hash;
        Cache::flush();
        $this->travel(299)->seconds();
        $this->post('/email/verification-notification')->assertSessionHasErrors('resend');
        $this->assertSame($firstHash, $user->fresh()->email_activation_code_hash);
        Notification::assertSentToTimes($user, ActivateEmail::class, 1);
        $this->travel(1)->seconds();
        $this->post('/email/verification-notification')->assertRedirect('/email/verify');
        Notification::assertSentToTimes($user, ActivateEmail::class, 2);
        $latest = Notification::sent($user, ActivateEmail::class)->last();
        $this->assertTrue($user->fresh()->registration_expires_at->equalTo($deadline));
        $this->assertTrue($user->email_activation_expires_at->equalTo($deadline));
        $this->assertFalse($first->shouldSend($user, 'mail'));
        $this->assertTrue($latest->shouldSend($user, 'mail'));
        $this->post('/email/verify', ['code' => $first->code])->assertSessionHasErrors('code');
        $this->post('/email/verify', ['code' => $latest->code])->assertRedirect('/dashboard');
        $this->assertFalse($latest->shouldSend($user, 'mail'));
    }

    public function test_registration_expires_at_exactly_one_hour_even_after_resending(): void
    {
        $this->freezeTime();
        $user = $this->registerPending();
        $this->travel(55)->minutes();
        $this->post('/email/verification-notification')->assertRedirect('/email/verify');
        $code = Notification::sent($user, ActivateEmail::class)->last()->code;
        $this->travel(5)->minutes();
        $this->post('/email/verify', ['code' => $code])->assertRedirect('/register');
        $this->assertGuest();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse(Notification::sent($user, ActivateEmail::class)->last()->shouldSend($user, 'mail'));
        $this->artisan('accounts:prune-unverified')->expectsOutput('Deleted 1 expired, unverified registrations.')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_cleanup_deletes_only_expired_new_unverified_registrations_and_auth_artifacts(): void
    {
        $expired = User::factory()->unverified()->create(['registration_expires_at' => now()->subSecond()]);
        $fresh = User::factory()->unverified()->create(['registration_expires_at' => now()->addMinute()]);
        $legacy = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
        $verified = User::factory()->create(['registration_expires_at' => now()->subMinute()]);
        DB::table('sessions')->insert(['id' => 'expired-session', 'user_id' => $expired->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $expired->email, 'token' => 'test-reset-token', 'created_at' => now()]);
        $this->artisan('accounts:prune-unverified')->assertSuccessful();
        $this->artisan('accounts:prune-unverified')->expectsOutput('Deleted 0 expired, unverified registrations.')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $expired->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $expired->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $expired->email]);
        foreach ([$fresh, $legacy, $verified] as $preserved) {
            $this->assertDatabaseHas('users', ['id' => $preserved->id]);
        }
    }

    public function test_legacy_accounts_can_request_a_code_but_are_never_pruned(): void
    {
        $user = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/email/verify');
        $this->post('/email/verification-notification')->assertRedirect('/email/verify');
        $this->assertNull($user->fresh()->registration_expires_at);
        $this->assertTrue($user->email_activation_expires_at->equalTo($user->email_activation_sent_at->addHour()));
        $this->travel(1)->hours();
        $this->post('/email/verify', ['code' => $this->notification($user)->code])->assertSessionHasErrors('code');
        $this->artisan('accounts:prune-unverified')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->post('/email/verification-notification')->assertRedirect('/email/verify');
        $this->post('/email/verify', ['code' => Notification::sent($user, ActivateEmail::class)->last()->code])->assertRedirect('/dashboard');
    }

    public function test_email_change_requires_new_verification_without_an_account_deletion_deadline(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => 'new-address@example.test'])
            ->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNull($user->registration_expires_at);
        $this->get('/dashboard')->assertRedirect('/email/verify');
        $this->post('/email/verification-notification')->assertRedirect('/email/verify');
        $this->assertSame('new-address@example.test', $this->notification($user)->email);
        $this->post('/email/verify', ['code' => $this->notification($user)->code])->assertRedirect('/dashboard');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_guests_cannot_verify_or_resend_for_arbitrary_accounts(): void
    {
        $user = User::factory()->unverified()->create();
        foreach (['/email/verify', '/email/verification-notification'] as $path) {
            $this->post($path, ['code' => '123456', 'email' => $user->email, 'id' => $user->id])->assertRedirect('/login');
        }
        Notification::assertNothingSent();
    }

    public function test_a_verified_account_cannot_request_additional_activation_emails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect('/email/verify');
        $this->get('/email/verify')->assertRedirect('/dashboard');
        Notification::assertNothingSent();
    }

    private function registerPending(): User
    {
        $this->post('/register', [
            'name' => 'Pending Buyer', 'email' => 'pending@example.test', 'mobile_number' => '0300 1234567',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect('/email/verify');

        return User::query()->where('email', 'pending@example.test')->firstOrFail();
    }

    private function notification(User $user): ActivateEmail
    {
        Notification::assertSentTo($user, ActivateEmail::class);

        return Notification::sent($user, ActivateEmail::class)->last();
    }
}
