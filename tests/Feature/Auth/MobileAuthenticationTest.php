<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_mobile_and_hashes_password(): void
    {
        $this->post(route('register.store'), $this->registration())
            ->assertSessionHasNoErrors()->assertRedirect('/dashboard');

        $user = User::where('email', 'buyer@example.com')->firstOrFail();
        $this->assertSame('+923001234567', $user->mobile_number);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertArrayNotHasKey('mobile_number', $user->toArray());
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_duplicate_normalized_mobile(): void
    {
        User::factory()->create(['mobile_number' => '+923001234567']);
        $this->post(route('register.store'), $this->registration())
            ->assertSessionHasErrors('mobile_number');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_rejects_invalid_mobile_and_password_confirmation(): void
    {
        $this->post(route('register.store'), $this->registration([
            'mobile_number' => '+14155552671',
            'password_confirmation' => 'different',
        ]))->assertSessionHasErrors(['mobile_number', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_mobile_is_required(): void
    {
        $this->post(route('register.store'), $this->registration(['mobile_number' => '']))
            ->assertSessionHasErrors('mobile_number');
    }

    public function test_user_can_login_with_local_mobile_number(): void
    {
        $user = User::factory()->create(['mobile_number' => '+923001234567']);
        $this->post(route('login.store'), ['email' => '0300 1234567', 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_mobile_login_rejects_wrong_password(): void
    {
        User::factory()->create(['mobile_number' => '+923001234567']);
        $this->post(route('login.store'), ['email' => '+923001234567', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_mobile_format_variations_share_a_login_rate_limit(): void
    {
        User::factory()->create(['mobile_number' => '+923001234567']);
        foreach (['03001234567', '0300 1234567', '+923001234567', '923001234567', '0300-1234567'] as $mobile) {
            $this->post(route('login.store'), ['email' => $mobile, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }
        $this->post(route('login.store'), ['email' => '+923001234567', 'password' => 'password'])
            ->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('register.store'), [])->assertSessionHasErrors('email');
        }
        $this->post(route('register.store'), [])->assertTooManyRequests();
    }

    public function test_profile_update_cannot_change_another_users_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->patch(route('profile.update'), [
            'id' => $other->id,
            'name' => 'Updated owner',
            'email' => $owner->email,
            'mobile_number' => $other->mobile_number,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Updated owner', $owner->fresh()->name);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertSame($owner->mobile_number, $owner->fresh()->mobile_number);
    }

    /** @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'mobile_number' => '0300 1234567',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }
}
