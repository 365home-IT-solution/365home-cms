<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Forms\ResidenceDeclarationForm;
use Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Pages;
use Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Tables\ResidenceDeclarationTable;
use Modules\Minihouse\App\Models\ResidenceDeclaration;

// "Khai báo lưu trú" mang từ Home qua (xem App\Filament\Resources\CccdDeclarationResource) — cùng
// mẫu chính thức của Bộ Công an, áp dụng chung nguyên tắc pháp lý (Luật Cư trú) cho khách thuê
// theo tháng thay vì khách đặt phòng ngắn hạn. Dữ liệu được TỰ ĐỘNG tạo/cập nhật ngay khi tạo/sửa
// Hợp đồng hoặc Người ở cùng (xem ResidenceDeclarationService/ContractObserver/
// ContractOccupantObserver) — trang này chỉ để BỔ SUNG các trường mẫu yêu cầu mà hệ thống chưa thu
// thập được (lý do lưu trú, tỉnh/thành, phường/xã...) và đánh dấu đã nộp cho công an.
class ResidenceDeclarationResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = ResidenceDeclaration::class;

    protected static ?string $navigationIcon   = 'heroicon-o-identification';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Khai báo lưu trú';
    protected static ?int $navigationSort      = 9;

    public static function getModelLabel(): string
    {
        return 'Khai báo lưu trú';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Khai báo lưu trú';
    }

    public static function permissionGroup(): string
    {
        return 'residence_declarations';
    }

    // Không cho tạo tay — bản ghi luôn được sinh tự động theo Hợp đồng/Người ở cùng, tạo tay dễ bị
    // trùng/lệch dữ liệu nguồn.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return ResidenceDeclarationForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ResidenceDeclarationTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResidenceDeclarations::route('/'),
            'edit'  => Pages\EditResidenceDeclaration::route('/{record}/edit'),
        ];
    }
}
