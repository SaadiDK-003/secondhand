<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $title = 'Overview';

    public static function canAccess(): bool
    {
        return Gate::allows('moderate');
    }

    public function getSubheading(): ?string
    {
        return 'Your marketplace, moderation and services at a glance.';
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [Action::make('health')->label('Site health')->icon('heroicon-o-heart')->url(SiteHealth::getUrl())];
    }
}
