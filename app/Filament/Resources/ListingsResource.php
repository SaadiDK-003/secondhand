<?php

namespace App\Filament\Resources;

use App\Actions\ModerateMarketplace;
use App\Enums\ListingStatus;
use App\Filament\Resources\Pages\ListListings;
use App\Models\Listing;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<Listing> */
class ListingsResource extends \Filament\Resources\Resource
{
    protected static ?string $model = Listing::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?int $navigationSort = 1;

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

        $pending = Listing::where('status', ListingStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Listings awaiting review';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('id')->sortable(), TextColumn::make('title')->searchable()->wrap(), TextColumn::make('user.name')->label('Seller')->searchable(), TextColumn::make('status')->badge(), TextColumn::make('moderation_flags')->listWithLineBreaks()->wrap(), TextColumn::make('moderation_note')->wrap()->limit(100),
        ])->filters([SelectFilter::make('status')->options(['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected', 'blocked' => 'Blocked', 'draft' => 'Draft'])])->recordActions([
            Action::make('preview')->url(fn (Listing $record) => route('admin.listing-preview', $record))->openUrlInNewTab(),
            ...array_map(fn (string $decision) => Action::make($decision)->visible(fn (Listing $record): bool => match ($decision) {
                'approve' => in_array($record->status, [ListingStatus::Pending, ListingStatus::Blocked], true),
                'reject' => $record->status === ListingStatus::Pending,
                'block' => in_array($record->status, [ListingStatus::Pending, ListingStatus::Published, ListingStatus::Rejected], true),
            })->requiresConfirmation()->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(2000)])->action(function (Listing $record, array $data) use ($decision): void {
                app(ModerateMarketplace::class)->listing(auth()->user(), $record, $decision, $data['reason']);
            }), ['approve', 'reject', 'block']),
        ]);
    }

    /** @return array<string,PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListListings::route('/')];
    }
}
