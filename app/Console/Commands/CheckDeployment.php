<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PrivateImageStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class CheckDeployment extends Command
{
    protected $signature = 'deploy:health {--storage : Write/read/delete a temporary private storage object}
        {--skip-ssr : Check data/storage/mail before the new SSR service starts}';

    protected $description = 'Check deployment connectivity without printing secrets or sending email';

    public function handle(): int
    {
        $checks = [
            'PostgreSQL' => fn () => DB::select('select 1'),
            'Redis' => fn () => Redis::connection()->ping() ?: throw new \RuntimeException('Redis unavailable'),
            'Meilisearch' => fn () => Http::timeout(5)->withToken(config('scout.meilisearch.key'))
                ->get(rtrim(config('scout.meilisearch.host'), '/').'/stats')->throw(),
            'SSR' => fn () => $this->callSilent('inertia:check-ssr') === self::SUCCESS
                ?: throw new \RuntimeException('SSR unavailable'),
            'SMTP connection/authentication' => function (): void {
                $transport = Mail::mailer()->getSymfonyTransport();
                if (! $transport instanceof SmtpTransport) {
                    throw new \RuntimeException('An SMTP transport is required');
                }
                try {
                    $transport->start();
                } finally {
                    $transport->stop();
                }
            },
        ];
        if ($this->option('skip-ssr')) {
            unset($checks['SSR']);
        }
        if ($this->option('storage')) {
            $checks['Private image storage'] = function (): void {
                if (! PrivateImageStorage::isConfigured()) {
                    throw new \RuntimeException('Private image storage is required');
                }
                $disk = Storage::disk(config('listings.images_disk'));
                $path = 'deployment-checks/'.Str::uuid().'.txt';
                $value = Str::random(32);
                try {
                    if (! $disk->put($path, $value, ['visibility' => 'private']) || $disk->get($path) !== $value) {
                        throw new \RuntimeException('Storage roundtrip failed');
                    }
                } finally {
                    if (! $disk->delete($path)) {
                        throw new \RuntimeException('Temporary object cleanup failed');
                    }
                }
            };
        }
        $failed = false;
        foreach ($checks as $name => $check) {
            try {
                $check();
                $this->info($name.': OK');
            } catch (Throwable) {
                $failed = true;
                $this->error($name.': FAILED (inspect protected application/service logs)');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
