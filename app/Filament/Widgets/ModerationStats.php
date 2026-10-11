<?php

namespace App\Filament\Widgets;

use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModerationStats extends StatsOverviewWidget
{
    /** @return array<Stat> */
    protected function getStats(): array
    {
        return [Stat::make('Pending ads', Listing::where('status', 'pending')->count()), Stat::make('Open reports', Report::where('status', 'open')->count()), Stat::make('Suspended accounts', User::whereNotNull('suspended_at')->count())];
    }
}
