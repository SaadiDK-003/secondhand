<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\UsersResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UsersResource::class;
}
