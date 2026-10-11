<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_cover_public_private_and_error_responses(): void
    {
        foreach (['/', '/login', '/page-that-does-not-exist'] as $url) {
            $this->get($url)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'");
        }
        $this->actingAs(User::factory()->create())->get('/messages')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_authentication_pages_cannot_be_cached_publicly(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/reset-password/example-token?email=demo@example.invalid'] as $url) {
            $this->get($url)->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_csrf_is_required_for_message_writes_and_valid_requests_still_work(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $this->actingAs(User::factory()->create());
        // Enable the real middleware behavior; Laravel normally skips CSRF checks during tests.
        $this->app->instance('env', 'local');
        $this->postJson('/listings/'.$listing->id.'/messages', ['body' => 'Missing token'])->assertStatus(419);
        $this->assertDatabaseCount('messages', 0);

        $this->withSession(['_token' => 'csrf-check-token'])->post('/listings/'.$listing->id.'/messages', [
            'body' => 'Valid message', '_token' => 'csrf-check-token',
        ])->assertRedirect();
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_unexpected_appearance_cookie_cannot_enter_inline_script(): void
    {
        $this->withUnencryptedCookie('appearance', 'unexpected-theme-value')->get('/login')
            ->assertOk()->assertDontSee('unexpected-theme-value');
    }
}
