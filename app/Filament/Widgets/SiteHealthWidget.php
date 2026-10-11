<?php

namespace App\Filament\Widgets;

use App\Services\SiteHealth;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

class SiteHealthWidget extends Widget
{
    protected string $view = 'filament.widgets.site-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Gate::allows('moderate');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('moderate');

        return ['health' => app(SiteHealth::class)->snapshot()];
    }
}
