<?php

namespace App\Filament\Widgets;

use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class ModerationStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return Gate::allows('moderate');
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        Gate::authorize('moderate');

        return [
            Stat::make('Pending review', Listing::where('status', 'pending')->count())->description('Ads waiting for your decision')->descriptionColor('gray')->icon('heroicon-o-clipboard-document-check')->color('warning')->url(url('/admin/listings?filters[status][value]=pending')),
            Stat::make('Open reports', Report::where('status', 'open')->count())->description('Help keep the marketplace safe')->descriptionColor('gray')->icon('heroicon-o-flag')->color('danger')->url(url('/admin/reports?filters[status][value]=open')),
            Stat::make('Live listings', Listing::published()->count())->description('Items available to buyers')->descriptionColor('gray')->icon('heroicon-o-shopping-bag')->color('primary')->url(url('/admin/listings')),
            Stat::make('Members', User::count())->description('Registered marketplace accounts')->descriptionColor('gray')->icon('heroicon-o-users')->color('success')->url(url('/admin/users')),
        ];
    }
}
