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

    // Mặc định CHỈ super_admin — cùng mức hạn chế với "Sửa giá hàng loạt" (xem
    // SettingBook::getHeaderActions(), đã ẩn bulk_price_update với non-super-admin từ trước) vì bản
    // chất là 2 công cụ đổi giá hàng loạt rủi ro tương đương nhau (ghi thẳng xuống hệ thống, không xem
    // trước). Trước đây Bảng giá chỉ check quyền chung `view_any_book` (dùng chung với "Hệ thống
    // giá") nên đối tác có quyền đó vẫn vào được — không nhất quán với việc bulk_price_update đã bị
    // khoá.
    //
    // Vẫn OR thêm quyền Shield riêng của chính resource này (view_any_price::board...,
    // `php artisan shield:generate --resource=PriceBoardResource` đã tạo sẵn) — để sau này muốn mở
    // cho 1 vài tài khoản đối tác cụ thể thì chỉ cần vào "Vai trò" tick quyền đó, KHÔNG cần sửa code.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_price::board') ?? false);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('create_price::board') ?? false);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('update_price::board') ?? false);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('delete_price::board') ?? false);
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
