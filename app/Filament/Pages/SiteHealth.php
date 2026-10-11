<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;

class SiteHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Site health';

    protected string $view = 'filament.pages.site-health';

    public static function canAccess(): bool
    {
        return Gate::allows('moderate');
    }

    public function getSubheading(): ?string
    {
        return 'Live checks for the services your buyers and sellers rely on.';
    }
}
