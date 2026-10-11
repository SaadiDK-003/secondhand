<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Pages\ListActivity;
use App\Models\ActivityLog;
use App\Support\ActivityLogPresentation;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** @extends \Filament\Resources\Resource<ActivityLog> */
class ActivityResource extends \Filament\Resources\Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Activity log';

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
        return $table->stackedOnMobile()->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('When (UTC)')->dateTime('M j, Y, H:i:s')->timezone('UTC')->sortable(),
            TextColumn::make('actor.name')->label('Administrator')->searchable()
                ->placeholder(fn (ActivityLog $record): string => $record->action === 'admin.grant.cli' ? 'Server command' : 'System / deleted account'),
            TextColumn::make('action')->label('Action')->formatStateUsing(fn (ActivityLog $record): string => ActivityLogPresentation::action($record))
                ->searchable(query: function (Builder $query, string $search): Builder {
                    $actions = array_keys(array_filter(ActivityLogPresentation::actions(), fn (string $label): bool => str_contains(Str::lower($label), Str::lower($search))));

                    $query->whereIn('action', $actions)->orWhere('action', 'like', '%'.$search.'%');
                    foreach (['Keyword enabled' => true, 'Keyword disabled' => false] as $label => $active) {
                        if (str_contains(Str::lower($label), Str::lower($search))) {
                            $query->orWhere(fn (Builder $query): Builder => $query->where('action', 'keyword.update')->where('details->active', $active));
                        }
                    }

                    return $query;
                }),
            TextColumn::make('target')->label('Affected item')->getStateUsing(fn (ActivityLog $record): string => ActivityLogPresentation::target($record)),
            TextColumn::make('details')->label('Summary')->getStateUsing(fn (ActivityLog $record): string => ActivityLogPresentation::summary($record))->wrap(),
        ])->filters([
            SelectFilter::make('action')->label('Action type')->options(ActivityLogPresentation::actions()),
        ]);
    }

    /** @return array<string,PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListActivity::route('/')];
    }
}
