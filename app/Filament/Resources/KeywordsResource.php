<?php

namespace App\Filament\Resources;

use App\Actions\ModerateMarketplace;
use App\Filament\Resources\Pages\ListKeywords;
use App\Models\ProhibitedKeyword;
use Filament\Actions\Action;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<ProhibitedKeyword> */
class KeywordsResource extends \Filament\Resources\Resource
{
    protected static ?string $model = ProhibitedKeyword::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 4;

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
        return $table->stackedOnMobile()->columns([
            TextColumn::make('phrase')->searchable()->wrap(),
            TextColumn::make('is_active')->label('Status')
                ->getStateUsing(fn (ProhibitedKeyword $record): string => $record->is_active ? 'Active' : 'Disabled')
                ->badge()->color(fn (ProhibitedKeyword $record): string => $record->is_active ? 'success' : 'gray'),
        ])->filters([
            TernaryFilter::make('is_active')->label('Keyword status')->placeholder('All keywords')->trueLabel('Active')->falseLabel('Disabled'),
        ])->recordActionsColumnLabel('Actions')->recordActions([
            Action::make('toggle')
                ->label(fn (ProhibitedKeyword $record): string => $record->is_active ? 'Disable' : 'Enable')
                ->color(fn (ProhibitedKeyword $record): string => $record->is_active ? 'warning' : 'success')
                ->modalHeading(fn (ProhibitedKeyword $record): string => $record->is_active ? 'Disable keyword' : 'Enable keyword')
                ->modalDescription(fn (ProhibitedKeyword $record): string => $record->is_active
                    ? 'This phrase will no longer flag new listings for review.'
                    : 'New listings containing this phrase will be flagged for review.')
                ->requiresConfirmation()->action(function (ProhibitedKeyword $record): void {
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
