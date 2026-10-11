<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

class AdminOverview extends Widget
{
    protected string $view = 'filament.widgets.admin-overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return Gate::allows('moderate');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        Gate::authorize('moderate');

        return ['name' => auth()->user()?->name];
    }
}
