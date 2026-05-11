<?php

namespace Modules\Payment\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;
use Modules\Payment\App\Filament\Resources\OrderResource\Tables\OrderTable;
use Modules\Payment\App\Filament\Resources\OrderResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Payment\Entities\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationIcon(): string
    {
        return __('payment::order.resource.navigation_icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('payment::order.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('payment::order.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment::order.resource.plural_model_label');
    }


    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_order') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_order') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('update_order') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('delete_order') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('delete_any_order') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allCategoryIds = $user->allowedCategoryIds();

        if (empty($allCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('category_id', $allCategoryIds);
    }

    public static function form(Form $form): Form
    {
        return OrderForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return OrderTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrder::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
