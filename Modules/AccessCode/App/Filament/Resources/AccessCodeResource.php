<?php

declare(strict_types=1);

namespace Modules\AccessCode\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Forms\AccessCodeForm;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\AccessCodeTable;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\AccessCode\Entities\AccessCode;

class AccessCodeResource extends Resource
{
    protected static ?string $model = AccessCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pass Cổng';
    }

    public static function getModelLabel(): string
    {
        return 'Pass Cổng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pass Cổng';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_access::code') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allCategoryIds = $user->allowedCategoryIds();

        // AccessCode đã lọc theo partner_id qua BelongsToPartner; allowedCategoryIds chỉ thu hẹp thêm.
        if (empty($allCategoryIds)) {
            return $query;
        }

        return $query->whereIn('category_id', $allCategoryIds);
    }

    public static function form(Form $form): Form
    {
        return AccessCodeForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return AccessCodeTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccessCode::route('/'),
            'create' => Pages\CreateAccessCode::route('/create'),
            'edit' => Pages\EditAccessCode::route('/{record}/edit'),
        ];
    }
}
