<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Pages\ListActivity;
use App\Models\ActivityLog;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<ActivityLog> */
class ActivityResource extends \Filament\Resources\Resource
{
    protected static ?string $model = ActivityLog::class;

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
        return $table->defaultSort('id', 'desc')->columns([TextColumn::make('created_at')->dateTime(), TextColumn::make('actor_id')->placeholder('Console'), TextColumn::make('action')->searchable(), TextColumn::make('target_type'), TextColumn::make('target_id'), TextColumn::make('details')->getStateUsing(fn (ActivityLog $record) => json_encode($record->details, JSON_UNESCAPED_UNICODE))->wrap()]);
    }

    /** @return array<string,PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListActivity::route('/')];
    }
}
