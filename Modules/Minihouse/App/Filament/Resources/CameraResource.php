<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Filament\Resources\CameraResource\Forms\CameraForm;
use Modules\Minihouse\App\Filament\Resources\CameraResource\Pages;
use Modules\Minihouse\App\Filament\Resources\CameraResource\Tables\CameraTable;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Models\Camera;
use Modules\Minihouse\App\Support\ActiveBuildingScope;
use Modules\Minihouse\App\Support\HomestayBridge;

// Mang tính năng Camera của Home (App\Filament\Resources\CameraResource) sang panel MiniHouse —
// DÙNG CHUNG bảng "cameras" + service App\Services\Go2RtcClient qua Modules\Minihouse\App\Models\
// Camera (kế thừa App\Models\Camera, chỉ override cách tìm server go2rtc/Frigate — xem
// Camera::resolveCameraSettings() — để lọc THEO TỪNG TOÀ NHÀ thay vì theo đối tác như Home, vì
// MiniHouse chỉ có 1 đối tác nội bộ cố định). Từ khi gộp Phòng/Toà nhà MiniHouse vào
// products/categories (xem HomestayBridge), 1 Toà nhà MiniHouse VẬT LÝ CHÍNH LÀ 1 Category gốc
// category_type=product — đúng hình dạng Camera.branch_id đang cần, nên tái dùng được ngay không
// cần migration mới cho bảng cameras.
//
// BẮT BUỘC tự lọc lại bằng tay ở getEloquentQuery() — App\Models\Camera dùng 2 trait BelongsToBranch/
// BelongsToPartner CHỈ áp dụng global scope khi App\Support\AdminPanelContext::isActive() (middleware
// riêng của panel "admin"/Home, KHÔNG chạy trong panel "minihouse-admin") — nếu không tự lọc, camera
// của Home sẽ lộ hết sang panel này (rò rỉ dữ liệu 2 chiều, đúng thứ tuyệt đối không được phép theo
// yêu cầu "không ảnh hưởng gì đến homestay").
class CameraResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Camera::class;

    protected static ?string $navigationIcon  = 'heroicon-o-video-camera';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Camera';
    protected static ?int    $navigationSort  = 70;

    public static function getModelLabel(): string
    {
        return 'Camera';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Camera';
    }

    public static function permissionGroup(): string
    {
        return 'cameras';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('partner_id', HomestayBridge::PARTNER_ID)
            ->whereIn('branch_id', ActiveBuildingScope::permittedBuildingIds());
    }

    public static function form(Form $form): Form
    {
        return CameraForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return CameraTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCameras::route('/'),
            'create' => Pages\CreateCamera::route('/create'),
            'edit'   => Pages\EditCamera::route('/{record}/edit'),
        ];
    }
}
