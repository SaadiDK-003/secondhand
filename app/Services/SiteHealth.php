<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PrivateImageStorage;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

/**
 * @phpstan-type Check array{name: string, status: string, detail: string, latency: int}
 * @phpstan-type Snapshot array{checked_at: int, status: string, healthy: int, total: int, checks: list<Check>}
 */
class SiteHealth
{
    public const HEARTBEAT_KEY = 'site-health:scheduler-heartbeat';

    private const SNAPSHOT_KEY = 'site-health:snapshot:v1';

    /** @return Snapshot */
    public function snapshot(): array
    {
        // Share a one-minute snapshot across admins, including when Redis is down.
        // File storage is private; tests use their isolated in-memory cache instead.
        $cache = Cache::store(app()->environment('testing') ? 'array' : 'file');
        try {
            /** @var Snapshot|null $cached */
            $cached = $cache->get(self::SNAPSHOT_KEY);
            if ($cached !== null) {
                return $cached;
            }
        } catch (Throwable) {
            // A cache failure must not conceal failures in the other services.
        }

        $checks = [
            $this->check('Database', function (): array {
                DB::select('select 1');

                return $this->ok('PostgreSQL is accepting queries.');
            }),
            $this->check('Redis', function (): array {
                $reply = Redis::connection()->ping();
                if (! in_array($reply, [true, 'PONG', '+PONG'], true)) {
                    return $this->failed('Redis did not respond to its connection check.');
                }

                return $this->ok('Session, cache and queue backend is reachable.');
            }),
            $this->check('Search', function (): array {
                if (config('scout.driver') !== 'meilisearch') {
                    return $this->warning('Meilisearch is not enabled in this environment.');
                }
                $response = Http::connectTimeout(2)->timeout(3)
                    ->withToken((string) config('scout.meilisearch.key'))
                    ->get(rtrim((string) config('scout.meilisearch.host'), '/').'/stats');

                return $response->successful() ? $this->ok('Meilisearch is reachable with authenticated access.') : $this->failed('Search connection or authentication failed.');
            }),
            $this->check('Queue workers', function (): array {
                if (config('queue.default') !== 'redis') {
                    return $this->warning('Horizon needs the Redis queue connection.');
                }
                $masters = app(MasterSupervisorRepository::class)->all();
                if ($masters === []) {
                    return $this->failed('No Horizon heartbeat. Check the worker service.');
                }
                foreach ($masters as $master) {
                    if ($master->status !== 'running') {
                        return $this->warning('Horizon workers are paused or restarting.');
                    }
                }

                return $this->ok('Horizon workers are reporting an active heartbeat.');
            }),
            $this->check('Queue activity', function (): array {
                if (config('queue.default') !== 'redis') {
                    return $this->warning('Queue metrics are available with Horizon in production.');
                }
                $jobs = app(JobRepository::class);
                $pending = $jobs->countPending();
                $failed = $jobs->countFailed();
                $detail = $pending.' pending · '.$failed.' retained failed jobs. Open Horizon for details.';

                return $failed > 0 || $pending > 100 ? $this->warning($detail) : $this->ok($detail);
            }),
            $this->check('Scheduler', function (): array {
                $last = Cache::get(self::HEARTBEAT_KEY);
                if (! is_numeric($last)) {
                    return $this->warning('No heartbeat yet. Allow one minute after deployment.');
                }
                $age = now()->getTimestamp() - (int) $last;
                if ($age < 0 || $age > 150) {
                    return $this->failed('The scheduler heartbeat is overdue. Check its timer.');
                }

                return $this->ok('The scheduled task ran within the last '.max(1, $age).' seconds.');
            }),
            $this->check('Page rendering', function (): array {
                if (! config('inertia.ssr.enabled')) {
                    return $this->warning('Server rendering is disabled in this environment.');
                }
                $response = Http::connectTimeout(2)->timeout(3)->get(rtrim((string) config('inertia.ssr.url'), '/').'/health');

                return $response->successful() && $response->json('status') === 'OK'
                    ? $this->ok('The Inertia SSR service is responding.')
                    : $this->failed('Server rendering is unavailable. Check the SSR service.');
            }),
            $this->check('Private images', function (): array {
                $diskName = config('listings.images_disk');
                if (! PrivateImageStorage::isConfigured()) {
                    return $this->failed('A supported private image disk is required.');
                }
                $disk = Storage::disk($diskName);
                $path = 'health-checks/'.Str::uuid().'.txt';
                try {
                    if (! $disk->put($path, 'secondhand-health', ['visibility' => 'private']) || $disk->get($path) !== 'secondhand-health') {
                        return $this->failed('Private image storage could not complete a write/read check.');
                    }
                } finally {
                    if (! $disk->delete($path)) {
                        throw new \RuntimeException('Storage probe cleanup failed.');
                    }
                }

                return $this->ok('Private storage passed its write, read and cleanup check.');
            }),
            $this->check('Email connection', function (): array {
                $transport = Mail::mailer()->getSymfonyTransport();
                if (! $transport instanceof SmtpTransport) {
                    return $this->warning('SMTP is not enabled. Email delivery is not being checked.');
                }
                $stream = $transport->getStream();
                if ($stream instanceof SocketStream) {
                    $stream->setTimeout(3);
                }
                try {
                    $transport->start();
                } finally {
                    $transport->stop();
                }

                return $this->ok('SMTP connected and authenticated. No email was sent.');
            }),
            $this->check('Server disk', function (): array {
                $free = @disk_free_space(storage_path());
                $total = @disk_total_space(storage_path());
                if ($free === false || $total === false || $total <= 0) {
                    return $this->warning('Disk capacity could not be read on this server.');
                }
                $percent = (int) round($free / $total * 100);
                $detail = $percent.'% free on the application storage volume.';

                return $percent < 5 ? $this->failed($detail) : ($percent < 10 ? $this->warning($detail) : $this->ok($detail));
            }),
            $this->check('Image processing', function (): array {
                $gd = function_exists('gd_info') ? gd_info() : [];

                return ! empty($gd['WebP Support']) && ! empty($gd['JPEG Support']) && ! empty($gd['PNG Support']) && extension_loaded('exif')
                    ? $this->ok('JPEG, PNG and WebP image encoders are available.')
                    : $this->failed('GD image encoders or EXIF support are missing.');
            }),
            $this->check('Secure configuration', function (): array {
                if (! app()->environment('production')) {
                    return $this->warning('Development environment. Production safeguards are not active.');
                }
                $secure = ! config('app.debug') && str_starts_with((string) config('app.url'), 'https://')
                    && config('session.secure') && config('session.http_only')
                    && config('session.driver') === 'redis' && config('cache.default') === 'redis'
                    && config('queue.default') === 'redis';

                return $secure ? $this->ok('HTTPS URL, protected cookies, debug off and Redis services configured.') : $this->failed('Production security settings need attention. Review the deployment configuration.');
            }),
        ];

        $statuses = array_column($checks, 'status');
        $snapshot = [
            'checked_at' => now()->getTimestamp(),
            'status' => in_array('failed', $statuses, true) ? 'failed' : (in_array('warning', $statuses, true) ? 'warning' : 'healthy'),
            'healthy' => count(array_filter($statuses, fn (string $status): bool => $status === 'healthy')),
            'total' => count($checks),
            'checks' => $checks,
        ];
        try {
            $cache->put(self::SNAPSHOT_KEY, $snapshot, 60);
        } catch (Throwable) {
            // The snapshot can still be displayed without a writable cache.
        }

        return $snapshot;
    }

    /**
     * @param  Closure(): array{status: string, detail: string}  $probe
     * @return Check
     */
    private function check(string $name, Closure $probe): array
    {
        $start = hrtime(true);
        try {
            $result = $probe();
        } catch (Throwable) {
            // Connection exceptions can contain passwords, URLs and filesystem paths.
            $result = $this->failed('The service check failed. Inspect the protected server logs.');
        }

        return ['name' => $name, ...$result, 'latency' => (int) round((hrtime(true) - $start) / 1_000_000)];
    }

    /** @return array{status: string, detail: string} */
    private function ok(string $detail): array
    {
        return ['status' => 'healthy', 'detail' => $detail];
    }

    /** @return array{status: string, detail: string} */
    private function warning(string $detail): array
    {
        return ['status' => 'warning', 'detail' => $detail];
    }

    /** @return array{status: string, detail: string} */
    private function failed(string $detail): array
    {
        return ['status' => 'failed', 'detail' => $detail];
    }
}
