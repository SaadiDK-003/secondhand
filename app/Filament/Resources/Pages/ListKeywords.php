<?php

namespace App\Filament\Resources\Pages;

use App\Actions\ModerateMarketplace;
use App\Filament\Resources\KeywordsResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListKeywords extends ListRecords
{
    protected static string $resource = KeywordsResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('addKeyword')->schema([TextInput::make('phrase')->required()->minLength(2)->maxLength(100)])->action(function (array $data): void {
            app(ModerateMarketplace::class)->keyword(auth()->user(), $data['phrase'], true);
        })];
    }
}
