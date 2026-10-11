<x-filament-widgets::widget>
    <section class="sh-health" wire:poll.60s.visible>
        <div class="sh-health-heading">
            <div>
                <p class="sh-eyebrow">SERVICE MONITOR</p>
                <h2>Site health</h2>
                <p>{{ $health['healthy'] }} of {{ $health['total'] }} checks healthy · Last checked {{ \Illuminate\Support\Carbon::createFromTimestamp($health['checked_at'], 'UTC')->format('H:i:s') }} UTC</p>
            </div>
            <div class="sh-health-tools">
                <span class="sh-status sh-status-{{ $health['status'] }}">{{ ['healthy' => 'All checks healthy', 'warning' => 'Needs attention', 'failed' => 'Service issue detected'][$health['status']] }}</span>
                <x-filament::button color="gray" icon="heroicon-o-arrow-path" wire:click="$refresh" wire:loading.attr="disabled" size="sm">Refresh view</x-filament::button>
            </div>
        </div>
        <div class="sh-health-grid" aria-live="polite">
            @foreach ($health['checks'] as $check)
                <article class="sh-health-card" wire:key="health-{{ \Illuminate\Support\Str::slug($check['name']) }}">
                    <div class="sh-health-card-heading">
                        <h3>{{ $check['name'] }}</h3>
                        <span class="sh-status sh-status-{{ $check['status'] }}">{{ ['healthy' => 'Healthy', 'warning' => 'Attention', 'failed' => 'Failed'][$check['status']] }}</span>
                    </div>
                    <p>{{ $check['detail'] }}</p>
                    <span class="sh-health-latency">Check took {{ $check['latency'] }} ms</span>
                </article>
            @endforeach
        </div>
        <footer class="sh-health-footer">
            <p>Checks are cached for 60 seconds and refresh while this panel is visible. SMTP connectivity does not confirm inbox delivery. Off-server backups, restore tests and external uptime alerts still need separate verification.</p>
            <a href="{{ url('/horizon') }}" class="sh-inline-link">Open queue monitor <span aria-hidden="true">→</span></a>
        </footer>
    </section>
</x-filament-widgets::widget>
