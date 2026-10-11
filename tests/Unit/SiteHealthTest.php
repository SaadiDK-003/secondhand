<?php

namespace Tests\Unit;

use App\Services\SiteHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

class SiteHealthTest extends TestCase
{
    private function services(string $workerStatus = 'running', int $failedJobs = 0, bool $databaseFails = false, bool $httpFails = false): void
    {
        config(['queue.default' => 'redis', 'scout.driver' => 'meilisearch',
            'scout.meilisearch.host' => 'http://search.test', 'scout.meilisearch.key' => 'private-test-key',
            'inertia.ssr.enabled' => true, 'inertia.ssr.url' => 'http://ssr.test',
            'listings.images_disk' => 'listing_images']);
        $query = DB::shouldReceive('select')->once()->with('select 1');
        if ($databaseFails) {
            $query->andThrow(new \RuntimeException('password=NEVER-EXPOSE-THIS /private/server/path'));
        } else {
            $query->andReturn([]);
        }
        Redis::shouldReceive('connection->ping')->once()->andReturn('PONG');
        Http::fake(['http://search.test/stats' => Http::response(['databaseSize' => 0], $httpFails ? 401 : 200),
            'http://ssr.test/health' => Http::response(['status' => $httpFails ? 'down' : 'OK'])]);
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->once()->andReturn([(object) ['status' => $workerStatus]]);
        $this->app->instance(MasterSupervisorRepository::class, $masters);
        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('countPending')->once()->andReturn(2);
        $jobs->shouldReceive('countFailed')->once()->andReturn($failedJobs);
        $this->app->instance(JobRepository::class, $jobs);
        Cache::put(SiteHealth::HEARTBEAT_KEY, now()->timestamp, 600);
        Storage::fake('listing_images');
        Storage::disk('listing_images')->put('existing.webp', 'preserve');
        $transport = Mockery::mock(SmtpTransport::class);
        $stream = new SocketStream;
        $transport->shouldReceive('getStream')->once()->andReturn($stream);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('stop')->once();
        Mail::shouldReceive('mailer->getSymfonyTransport')->once()->andReturn($transport);
        Mail::shouldReceive('send')->never();
    }

    public function test_checks_use_authenticated_connections_preserve_images_and_reuse_snapshot(): void
    {
        $this->services();
        $service = app(SiteHealth::class);
        $snapshot = $service->snapshot();
        $checks = collect($snapshot['checks'])->keyBy('name');
        foreach (['Database', 'Redis', 'Search', 'Queue workers', 'Scheduler', 'Private images', 'Email connection', 'Page rendering', 'Image processing'] as $name) {
            $this->assertSame('healthy', $checks[$name]['status'], $name);
        }
        $this->assertSame($snapshot, $service->snapshot());
        $this->assertSame(['existing.webp'], Storage::disk('listing_images')->allFiles());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'http://search.test/stats' && $request->hasHeader('Authorization', 'Bearer private-test-key'));
    }

    public function test_failed_service_is_redacted_and_does_not_hide_other_results(): void
    {
        $this->services(databaseFails: true);
        $snapshot = app(SiteHealth::class)->snapshot();
        $this->assertSame('failed', $snapshot['status']);
        $this->assertCount(12, $snapshot['checks']);
        $this->assertStringNotContainsString('NEVER-EXPOSE', json_encode($snapshot));
        $this->assertStringNotContainsString('/private/server', json_encode($snapshot));
        $this->assertSame('healthy', collect($snapshot['checks'])->keyBy('name')['Private images']['status']);
    }

    public function test_overdue_scheduler_and_paused_workers_are_not_reported_as_healthy(): void
    {
        $this->services(workerStatus: 'paused', failedJobs: 1);
        Cache::put(SiteHealth::HEARTBEAT_KEY, now()->subMinutes(4)->timestamp, 600);
        $checks = collect(app(SiteHealth::class)->snapshot()['checks'])->keyBy('name');
        $this->assertSame('failed', $checks['Scheduler']['status']);
        $this->assertSame('warning', $checks['Queue workers']['status']);
        $this->assertSame('warning', $checks['Queue activity']['status']);
    }

    public function test_unauthenticated_search_and_unhealthy_ssr_responses_are_failures(): void
    {
        $this->services(httpFails: true);
        $checks = collect(app(SiteHealth::class)->snapshot()['checks'])->keyBy('name');
        $this->assertSame('failed', $checks['Search']['status']);
        $this->assertSame('failed', $checks['Page rendering']['status']);
    }

    public function test_public_image_configuration_is_rejected_without_touching_existing_files(): void
    {
        $this->services();
        config(['filesystems.disks.listing_images.visibility' => 'public']);
        $checks = collect(app(SiteHealth::class)->snapshot()['checks'])->keyBy('name');
        $this->assertSame('failed', $checks['Private images']['status']);
        $this->assertSame(['existing.webp'], Storage::disk('listing_images')->allFiles());
    }

    public function test_minute_cache_expires_and_checks_run_again(): void
    {
        $this->services();
        $first = app(SiteHealth::class)->snapshot();
        $this->travel(61)->seconds();
        $this->services();
        $second = app(SiteHealth::class)->snapshot();
        $this->assertGreaterThan($first['checked_at'], $second['checked_at']);
    }

    public function test_the_scheduled_heartbeat_records_a_real_scheduler_run(): void
    {
        Cache::forget(SiteHealth::HEARTBEAT_KEY);
        $heartbeat = collect(Schedule::events())->first(fn ($event) => $event->description === 'site-health-heartbeat');
        $this->assertNotNull($heartbeat);
        $this->assertSame('* * * * *', $heartbeat->expression);
        $heartbeat->run($this->app);
        $this->assertSame(now()->getTimestamp(), Cache::get(SiteHealth::HEARTBEAT_KEY));
    }
}
