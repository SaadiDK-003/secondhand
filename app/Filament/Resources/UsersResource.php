<?php

namespace App\Filament\Resources;

use App\Actions\ModerateMarketplace;
use App\Filament\Resources\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<User> */
class UsersResource extends \Filament\Resources\Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 3;

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
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('name')->searchable()->description(fn (User $record): ?string => $record->is_admin ? 'Administrator' : null),
            TextColumn::make('email')->searchable()->wrap(),
            TextColumn::make('status')->label('Account status')
                ->getStateUsing(fn (User $record): string => $record->suspended_at === null ? 'Active' : 'Suspended')
                ->badge()->color(fn (User $record): string => $record->suspended_at === null ? 'success' : 'danger')
                ->description(fn (User $record): ?string => $record->suspended_at === null ? null : $record->suspended_at->utc()->format('M j, Y, H:i').' UTC'),
        ])->filters([
            SelectFilter::make('status')->label('Account status')->placeholder('All users')
                ->options(['active' => 'Active', 'suspended' => 'Suspended (inactive)'])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'active' => $query->whereNull('suspended_at'),
                    'suspended' => $query->whereNotNull('suspended_at'),
                    default => $query,
                }),
        ], layout: FiltersLayout::AboveContent)->deferFilters(false)->recordActionsColumnLabel('Actions')->recordActions([
            ...array_map(fn (string $decision) => Action::make($decision)
                ->visible(fn (User $record): bool => ! $record->is_admin && ($decision === 'suspend' ? $record->suspended_at === null : $record->suspended_at !== null))
                ->color($decision === 'suspend' ? 'danger' : 'success')
                ->requiresConfirmation()->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(2000)])->action(function (User $record, array $data) use ($decision): void {
                    app(ModerateMarketplace::class)->suspend(auth()->user(), $record, $decision === 'suspend', $data['reason']);
                }), ['suspend', 'restore']),
        ]);
    }

    /** @return array<string,PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/')];
    }
}
