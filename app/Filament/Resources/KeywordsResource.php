<?php

namespace App\Filament\Resources;

use App\Actions\ModerateMarketplace;
use App\Filament\Resources\Pages\ListKeywords;
use App\Models\ProhibitedKeyword;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<ProhibitedKeyword> */
class KeywordsResource extends \Filament\Resources\Resource
{
    protected static ?string $model = ProhibitedKeyword::class;

    public static function canViewAny(): bool
    {
        return Gate::allows('moderate');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('phrase')->searchable(), TextColumn::make('is_active')->label('Active')])->recordActions([
            Action::make('toggle')->requiresConfirmation()->action(function (ProhibitedKeyword $record): void {
                app(ModerateMarketplace::class)->keyword(auth()->user(), $record->phrase, ! $record->is_active);
            }),
        ]);
    }

    /** @return array<string,PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListKeywords::route('/')];
    }
}
