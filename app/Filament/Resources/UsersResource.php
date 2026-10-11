<?php

namespace App\Filament\Resources;

use App\Actions\ModerateMarketplace;
use App\Filament\Resources\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\PageRegistration;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
        return $table->columns([TextColumn::make('id')->sortable(), TextColumn::make('name')->searchable(), TextColumn::make('email')->searchable(), TextColumn::make('suspended_at')->dateTime()->placeholder('Active')])->recordActions([
            ...array_map(fn (string $decision) => Action::make($decision)->requiresConfirmation()->schema([Textarea::make('reason')->required()->minLength(3)->maxLength(2000)])->action(function (User $record, array $data) use ($decision): void {
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
