<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Forms\PanoramaSceneForm;
use Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Pages;
use Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Tables\PanoramaSceneTable;
use Modules\Minihouse\App\Models\PanoramaScene;

// Quản lý "Sơ đồ 360°" — mỗi bản ghi là 1 ảnh toàn cảnh (sảnh/hành lang/phòng) + các điểm nóng nối
// sang ảnh khác, ghép lại thành 1 tour ảo cho khách xem công khai (xem PanoramaTourController, KHÔNG
// cần đăng nhập — mirror đúng route công khai TenantFeedbackController đang dùng). Yêu cầu người
// dùng 2026-09-13: "khách có thể vào xem sơ đồ phòng mượt mà và chính xác" — dùng ảnh 360° thường
// (không quét 3D/LiDAR) để giữ dung lượng nhỏ, tải nhanh trên điện thoại.
class PanoramaSceneResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = PanoramaScene::class;
    protected static ?string $navigationIcon  = 'heroicon-o-camera';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Sơ đồ 360°';
    // Đứng sau "Loại tài sản" (sort=4) — cùng là dữ liệu phụ trợ cho phòng/toà nhà.
    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return 'Điểm 360°';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Sơ đồ 360°';
    }

    public static function permissionGroup(): string
    {
        // Dùng chung quyền với "Phòng" — cùng nguyên tắc AmenityResource/AssetTypeResource (danh mục
        // phụ trợ, không cần bộ quyền riêng).
        return 'rooms';
    }

    public static function form(Form $form): Form
    {
        return PanoramaSceneForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PanoramaSceneTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPanoramaScenes::route('/'),
            'create' => Pages\CreatePanoramaScene::route('/create'),
            'edit'   => Pages\EditPanoramaScene::route('/{record}/edit'),
        ];
    }
}
