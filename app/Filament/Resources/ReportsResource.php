<?php

namespace App\Filament\Resources;

use App\Actions\ModerateMarketplace;
use App\Filament\Resources\Pages\ListReports;
use App\Models\Listing;
use App\Models\Report;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<Report> */
class ReportsResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Report::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Gate::allows('moderate');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $reports = Report::where('status', 'open')->count();

        return $reports > 0 ? (string) $reports : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Open reports awaiting attention';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([TextColumn::make('id'), TextColumn::make('reporter_id'), TextColumn::make('target_type'), TextColumn::make('target_id'), TextColumn::make('reason'), TextColumn::make('details')->wrap(), TextColumn::make('status')->badge(), TextColumn::make('resolution')->wrap()])->filters([SelectFilter::make('status')->options(['open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'])])->recordActions([
            Action::make('reviewListing')->visible(fn (Report $record) => $record->target_type === 'listing' && Listing::whereKey($record->target_id)->exists())->url(fn (Report $record) => route('admin.listing-preview', $record->target_id))->openUrlInNewTab(),
            ...array_map(fn (string $status) => Action::make($status)->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(2000)])->action(function (Report $record, array $data) use ($status): void {
                app(ModerateMarketplace::class)->resolve(auth()->user(), $record, $status, $data['reason']);
            }), ['resolved', 'dismissed']),
        ]);
    }

    /** @return array<string,PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
