<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Tests\TestCase;

class DeploymentHealthTest extends TestCase
{
    private function mockHealthyServices(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);
        Redis::shouldReceive('connection->ping')->once()->andReturn(true);
        config(['scout.meilisearch.host' => 'http://search.test', 'scout.meilisearch.key' => 'test-private-key',
            'inertia.ssr.enabled' => true, 'inertia.ssr.url' => 'http://ssr.test']);
        Http::fake(['http://search.test/stats' => Http::response(['databaseSize' => 0]),
            'http://ssr.test/health' => Http::response(['status' => 'OK'])]);
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('stop')->once();
        Mail::shouldReceive('mailer->getSymfonyTransport')->once()->andReturn($transport);
    }

    public function test_connectivity_check_does_not_send_email_or_write_storage_by_default(): void
    {
        $this->mockHealthyServices();
        Storage::shouldReceive('disk')->never();
        $this->artisan('deploy:health')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->url() === 'http://search.test/stats'
            && $request->hasHeader('Authorization', 'Bearer test-private-key'));
    }

    public function test_private_storage_probe_cleans_up_only_its_random_object(): void
    {
        $this->mockHealthyServices();
        config(['listings.images_disk' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('existing-user-image.webp', 'preserve');
        $this->artisan('deploy:health --storage')->assertSuccessful();
        Storage::disk('s3')->assertExists('existing-user-image.webp');
        $this->assertSame(['existing-user-image.webp'], Storage::disk('s3')->allFiles());
    }

    public function test_pre_migration_check_does_not_require_ssr_to_be_started(): void
    {
        $this->mockHealthyServices();
        Http::fake(['http://search.test/stats' => Http::response([]),
            'http://ssr.test/health' => Http::response([], 503)]);
        Storage::shouldReceive('disk')->never();
        $this->artisan('deploy:health --skip-ssr')->assertSuccessful();
        Http::assertNotSent(fn ($request) => $request->url() === 'http://ssr.test/health');
    }

    public function test_failures_do_not_print_credentials(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \RuntimeException('password=DO-NOT-PRINT'));
        Redis::shouldReceive('connection->ping')->once()->andReturn(false);
        Http::fake(['*' => Http::response([], 503)]);
        Mail::shouldReceive('mailer->getSymfonyTransport')->andThrow(new \RuntimeException('smtp=DO-NOT-PRINT'));
        $this->artisan('deploy:health')->expectsOutput('PostgreSQL: FAILED (inspect protected application/service logs)')
            ->doesntExpectOutputToContain('DO-NOT-PRINT')->assertFailed();
    }
}
