<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardForm;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Pages;
use Modules\Book\App\Filament\Resources\PriceBoardResource\Tables\PriceBoardTable;
use Modules\Product\App\Models\PriceBoard;

/**
 * Quản lý các "bảng giá" đặt tên, có thời hạn (Tết, khuyến mãi, đối tác...) — bổ sung cho trang
 * "Hệ thống giá" (BookResource/SettingBook, vẫn giữ nguyên = sửa bảng giá mặc định). Xem
 * App\Services\PriceBoardSyncService để biết cơ chế áp/khôi phục giá theo ngày hiệu lực.
 */
class PriceBoardResource extends Resource
{
    protected static ?string $model = PriceBoard::class;
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Quản lý';
    // Đứng ngay sau "Hệ thống giá" (BookResource, sort=-3) — mặc định Filament sắp theo alphabet
    // nên "Bảng giá" (B) bị tách xa "Hệ thống giá" (H), trông như 2 mục không liên quan.
    protected static ?int $navigationSort = -2;

    public static function getNavigationLabel(): string
    {
        return 'Bảng giá';
    }

    public static function getModelLabel(): string
    {
        return 'Bảng giá';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Bảng giá';
    }

    public static function getEloquentQuery(): Builder
    {
        // Bảng mặc định (is_default=true) là dữ liệu nội bộ, đã có trang "Hệ thống giá" quản lý —
        // không hiển thị/cho sửa lẫn ở đây.
        return parent::getEloquentQuery()->where('is_default', false);
    }

    // Dùng chung quyền với trang "Hệ thống giá" (BookResource) vì cùng phạm vi nghiệp vụ giá phòng.
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_book') ?? false;
    }

    public static function form(Form $form): Form
    {
        return PriceBoardForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PriceBoardTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPriceBoards::route('/'),
            'create' => Pages\CreatePriceBoard::route('/create'),
            'edit'   => Pages\EditPriceBoard::route('/{record}/edit'),
        ];
    }
}
