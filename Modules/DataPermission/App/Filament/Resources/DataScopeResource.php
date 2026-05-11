<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\DataPermission\App\Filament\Resources\DataScopeResource\Forms\DataScopeForm;
use Modules\DataPermission\App\Filament\Resources\DataScopeResource\Pages;
use Modules\DataPermission\App\Filament\Resources\DataScopeResource\Tables\DataScopeTable;
use Modules\DataPermission\Entities\DataScope;

class DataScopeResource extends Resource
{
    protected static ?string $model = DataScope::class;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->can('view_any_data::scope') ?? false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_any_data::scope') ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_data::scope') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_data::scope') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('update_data::scope') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('delete_data::scope') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('delete_any_data::scope') ?? false;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-shield-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Phân quyền';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phân quyền dữ liệu';
    }

    public static function getModelLabel(): string
    {
        return 'Phân quyền dữ liệu';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phân quyền dữ liệu';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return DataScopeForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return DataScopeTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDataScope::route('/'),
            'create' => Pages\CreateDataScope::route('/create'),
            'edit'   => Pages\EditDataScope::route('/{record}/edit'),
        ];
    }
}
