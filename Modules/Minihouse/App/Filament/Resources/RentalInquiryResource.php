<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Forms\RentalInquiryForm;
use Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Pages;
use Modules\Minihouse\App\Filament\Resources\RentalInquiryResource\Tables\RentalInquiryTable;
use Modules\Minihouse\App\Models\RentalInquiry;

// "Yêu cầu liên hệ thuê phòng" gửi từ trang tìm phòng công khai (API\Minihouse\Public\*) — nhân
// viên vào đây gọi lại tư vấn, tự cập nhật trạng thái. KHÔNG có trang "Tạo mới" — bản ghi CHỈ sinh
// ra từ API công khai, panel chỉ xem/xử lý.
class RentalInquiryResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = RentalInquiry::class;
    protected static ?string $navigationIcon  = 'heroicon-o-phone-arrow-up-right';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Yêu cầu liên hệ thuê phòng';
    protected static ?int $navigationSort     = 61;

    public static function getModelLabel(): string
    {
        return 'Yêu cầu liên hệ thuê phòng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Yêu cầu liên hệ thuê phòng';
    }

    public static function permissionGroup(): string
    {
        return 'rental_inquiries';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', RentalInquiry::STATUS_NEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return RentalInquiryForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RentalInquiryTable::table($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRentalInquiries::route('/'),
            'edit'  => Pages\EditRentalInquiry::route('/{record}/edit'),
        ];
    }
}
