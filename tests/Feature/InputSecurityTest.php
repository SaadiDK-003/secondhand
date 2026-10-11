<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Filament\Resources\Pages\ListListings;
use App\Models\Category;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\User;
use App\Services\ModerationChecks;
use App\Services\SearchListings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Ssr\SsrState;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InputSecurityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('malformedListingFields')]
    public function test_malformed_listing_fields_return_validation_errors_without_writes(string $field, mixed $value): void
    {
        Storage::fake('listing_images');
        Queue::fake();
        $city = City::factory()->create();
        $payload = [
            'category_id' => Category::factory()->create()->id,
            'title' => 'A good used sofa',
            'description' => 'A used sofa in good working condition.',
            'price' => '5000.00',
            'condition' => 'good',
            'is_negotiable' => false,
            'province_id' => $city->province_id,
            'city_id' => $city->id,
            'images' => [UploadedFile::fake()->image('photo.jpg', 20, 20)],
        ];
        $this->actingAs(User::factory()->create())->postJson('/listings', [...$payload, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors(match ($field) {
                'images', 'remove_image_ids' => $field.'.0',
                default => $field,
            });
        $this->assertDatabaseCount('listings', 0);
        $this->assertSame([], Storage::disk('listing_images')->allFiles());
        Queue::assertNothingPushed();
    }

    public static function malformedListingFields(): array
    {
        return [
            ['category_id', ['1']], ['province_id', ['1']], ['city_id', ['1']], ['area_id', ['1']],
            ['province_id', "1' OR 1=1 --"], ['city_id', 'not-an-id'], ['remove_image_ids', [['1']]],
            ['title', ['wrong']], ['description', ['wrong']], ['price', ['1']], ['condition', ['good']],
            ['attributes', ['unknown' => 'value']], ['images', ['http://169.254.169.254/latest/meta-data/']],
            ['title', "Used\0sofa"], ['description', "A good used item with\0invalid text"],
        ];
    }

    public function test_malformed_search_filters_are_rejected_before_search(): void
    {
        $this->mock(SearchListings::class)->shouldNotReceive('search');
        foreach (['q', 'category_id', 'province_id', 'city_id', 'area_id', 'condition', 'sort', 'min_price', 'max_price', 'page'] as $field) {
            $this->getJson('/search?'.http_build_query([$field => ['1']]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['sort' => 'price; DROP TABLE users', 'category_id' => '1 OR 1=1', 'min_price' => '0 OR 1=1'] as $field => $value) {
            $this->getJson('/search?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_login_and_registration_reject_array_credentials(): void
    {
        $this->postJson('/login', ['email' => ['wrong'], 'password' => ['wrong']])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertGuest();
        $this->postJson('/register', ['name' => ['wrong'], 'email' => ['wrong'], 'mobile_number' => ['wrong'], 'password' => ['wrong']])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/register', ['name' => ['wrong'], 'email' => 'safe@example.invalid', 'mobile_number' => ['wrong'], 'password' => ['wrong']])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'mobile_number', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_input_limits_and_remember_checkbox_preserve_normal_authentication(): void
    {
        $user = User::factory()->create();
        foreach (['email' => str_repeat('x', 256), 'password' => str_repeat('x', 1025), 'remember' => ['1']] as $field => $value) {
            $this->postJson('/login', ['email' => $user->email, 'password' => 'password', $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertGuest();
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_confirmation_and_two_factor_inputs_are_validated_before_upstream_actions(): void
    {
        $this->postJson('/forgot-password', ['email' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/reset-password', ['email' => ['wrong'], 'token' => ['wrong'], 'password' => ['wrong'], 'password_confirmation' => ['wrong']])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'token', 'password', 'password_confirmation']);
        $this->getJson('/reset-password/test?'.http_build_query(['email' => ['wrong']]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/two-factor-challenge', ['code' => ['wrong'], 'recovery_code' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
        $this->assertGuest();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/user/confirm-password', ['password' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_account_messaging_reporting_and_password_fields_reject_wrong_shapes(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $this->actingAs($user)->postJson('/listings/'.$listing->id.'/messages', ['body' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/listings/'.$listing->id.'/report', ['reason' => 'other', 'details' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->patchJson('/settings/profile', ['name' => ['wrong'], 'email' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
        $this->putJson('/settings/password', ['current_password' => ['wrong'], 'password' => ['wrong'], 'password_confirmation' => ['wrong']])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'password']);
        $this->postJson('/settings/profile/photo', ['photo' => 'http://127.0.0.1/private'])->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('reports', 0);
        $this->assertSame($user->name, $user->fresh()->name);
    }

    public function test_sql_looking_text_is_stored_as_data_and_cannot_set_privileged_fields(): void
    {
        Http::preventStrayRequests();
        $buyer = User::factory()->create();
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $body = "Nice item'; DROP TABLE users; -- http://169.254.169.254/latest/meta-data/";
        $this->actingAs($buyer)->postJson('/listings/'.$listing->id.'/messages', ['body' => $body, 'sender_id' => $listing->user_id])->assertRedirect();
        $this->assertDatabaseHas('messages', ['sender_id' => $buyer->id, 'body' => $body]);
        $this->patchJson('/settings/profile', ['name' => "O'Brien'; SELECT * FROM users; --", 'email' => $buyer->email, 'is_admin' => true, 'suspended_at' => now()->toIso8601String()])->assertRedirect();
        $this->assertSame("O'Brien'; SELECT * FROM users; --", $buyer->fresh()->name);
        $this->assertFalse($buyer->fresh()->is_admin);
        $this->assertNull($buyer->fresh()->suspended_at);
        $this->assertDatabaseCount('users', 2);
        Http::assertNothingSent();
    }

    public function test_raw_duplicate_title_query_treats_sql_syntax_as_literal_text(): void
    {
        $owner = User::factory()->create();
        Listing::factory()->create(['user_id' => $owner->id, 'status' => ListingStatus::Pending, 'title' => 'A completely different item']);
        $listing = Listing::factory()->create(['user_id' => $owner->id, 'title' => "Used sofa' OR 1=1 --"]);
        $this->assertNotContains('Possible duplicate title from this seller', app(ModerationChecks::class)->flags($listing));
        Listing::factory()->create(['user_id' => $owner->id, 'status' => ListingStatus::Pending, 'title' => $listing->title]);
        $this->assertContains('Possible duplicate title from this seller', app(ModerationChecks::class)->flags($listing));
        $this->assertDatabaseCount('listings', 3);
    }

    public function test_admin_table_search_and_user_content_remain_safe(): void
    {
        $admin = User::factory()->withTwoFactor()->create(['is_admin' => true]);
        $this->actingAs($admin)->withSession(['admin_2fa' => $admin->id.':'.hash('sha256', $admin->two_factor_secret)]);
        $title = '<img src=x onerror="window.securityProbe=1">';
        $listing = Listing::factory()->create(['title' => $title]);
        $table = Livewire::test(ListListings::class)->assertCanSeeTableRecords([$listing]);
        $this->assertStringNotContainsString($title, $table->html());
        $table->searchTable("' OR 1=1 --")->assertCanNotSeeTableRecords([$listing]);
        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_null_bytes_are_rejected_before_database_writes_and_password_hashing(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $this->postJson('/login', ['email' => $user->email, 'password' => "password\0suffix"])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/register', ['name' => "Bad\0name", 'email' => 'new@example.invalid', 'mobile_number' => '03001234567', 'password' => "password\0suffix", 'password_confirmation' => "password\0suffix"])->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
        $this->actingAs($user)->patchJson('/settings/profile', ['name' => "Bad\0name", 'email' => $user->email])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/listings/'.$listing->id.'/messages', ['body' => "Bad\0message"])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson('/listings/'.$listing->id.'/report', ['reason' => 'other', 'details' => "Bad\0details"])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('reports', 0);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_bcrypt_password_limits_count_bytes_and_prevent_silent_truncation(): void
    {
        foreach ([str_repeat('a', 73), str_repeat('é', 37)] as $password) {
            $this->postJson('/register', ['name' => 'Buyer', 'email' => 'new@example.invalid', 'mobile_number' => '03001234567', 'password' => $password, 'password_confirmation' => $password])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
        $user = User::factory()->create(['password' => str_repeat('a', 72)]);
        $this->postJson('/login', ['email' => $user->email, 'password' => str_repeat('a', 72).'different-suffix'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => str_repeat('a', 72)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_pagination_rejects_wrong_shapes_and_excessive_offsets(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $buyer = User::factory()->create();
        $conversation = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $listing->user_id, 'listing_title' => $listing->title]);
        $this->actingAs($buyer);
        foreach (['/my-listings', '/favorites', '/blocked-users', '/messages', '/messages/'.$conversation->id, '/sellers/'.$listing->user_id, '/category/'.$listing->category->slug] as $url) {
            foreach ([['1'], -1, 1001, '9223372036854775807'] as $page) {
                $this->getJson($url.'?'.http_build_query(['page' => $page]))->assertUnprocessable()->assertJsonValidationErrors('page');
            }
        }
        $this->actingAs(User::factory()->create())->getJson('/messages/'.$conversation->id.'?page=1001')->assertForbidden();
    }

    public function test_live_ssr_escapes_untrusted_listing_profile_attribute_and_message_text(): void
    {
        if (! getenv('SSR_TESTS')) {
            $this->markTestSkipped('Set SSR_TESTS=1 with the SSR service running.');
        }
        config(['inertia.ssr.enabled' => true, 'inertia.ssr.throw_on_error' => true]);
        // Force the same compiled templates through SSR for this test, including normally client-only chat.
        Inertia::disableSsr(false);
        $payload = '<img src=x onerror="window.securityProbe=1">{{7*7}}</script><script>window.securityProbe=2</script>';
        $seller = User::factory()->create(['name' => $payload]);
        $listing = Listing::factory()->create(['user_id' => $seller->id, 'title' => $payload, 'description' => $payload, 'status' => ListingStatus::Published, 'published_at' => now()->subHour()]);
        $attribute = $listing->category->attributes()->create(['name' => 'Brand', 'slug' => 'brand', 'type' => 'text']);
        $listing->attributeValues()->create(['category_id' => $listing->category_id, 'category_attribute_id' => $attribute->id, 'value' => $payload]);
        $buyer = User::factory()->create();
        $conversation = Conversation::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'listing_title' => $payload]);
        $conversation->messages()->create(['sender_id' => $seller->id, 'body' => $payload]);
        foreach (['/ad/'.$listing->slug, '/sellers/'.$seller->id, '/messages/'.$conversation->id] as $url) {
            $this->app->forgetInstance(SsrState::class);
            $html = $this->actingAs($buyer)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-server-rendered="true"', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<script>window.securityProbe', $html);
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $this->assertSame(0, $xpath->query('//*[@onerror]')->length);
            foreach ($xpath->query('//script[not(@type="application/json") and not(@type="application/ld+json")]') as $script) {
                $this->assertStringNotContainsString('securityProbe', $script->textContent);
            }
            $this->assertStringContainsString('{{7*7}}', $html);
        }
    }
}
