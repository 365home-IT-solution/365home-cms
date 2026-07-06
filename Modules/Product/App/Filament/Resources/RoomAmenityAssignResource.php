<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Resources\Resource;
use Modules\Product\App\Filament\Resources\RoomAmenityAssignResource\Pages;
use Modules\Product\App\Models\Product;

class RoomAmenityAssignResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'Gán Tiện Ích Phòng';

    protected static ?string $slug = 'room-amenity-assign';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return 'Gán Tiện Ích Phòng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Gán Tiện Ích Phòng';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('is_activated', true)->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoomAmenityAssign::route('/'),
            'edit'  => Pages\EditRoomAmenityAssign::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
