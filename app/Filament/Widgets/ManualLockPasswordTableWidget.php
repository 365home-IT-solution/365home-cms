<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Forms\Form;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Tables\Actions\ManualLockPasswordImportAction;
use Modules\Product\App\Models\ManualLockPassword;

// Bảng "Khóa thủ công" bên trong trang gộp "Khóa cổng" (App\Filament\Pages\GateLockManagement) —
// tái dùng NGUYÊN VẸN cột/filter/action/quyền hạn đã có ở ManualLockPasswordResource, chỉ đổi nơi
// hiển thị (resource vẫn còn route thật, chỉ ẩn khỏi menu — xem shouldRegisterNavigation() ở đó).
class ManualLockPasswordTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', ManualLockPassword::class) ?? false;
    }

    // Ghi đè table() thay hẳn logic mặc định của InteractsWithTable (gồm cả
    // ->query($this->getTableQuery())) — nên phải tự gắn ->query() ở đây, KHÔNG thể chỉ override
    // getTableQuery() (nó sẽ không bao giờ được gọi tới nữa). Từng bị lỗi "Table ... must have a
    // [query()]" vì thiếu dòng này.
    //
    // 2 nút "Import Excel"/"Thêm mới" trước đây nằm ở header của trang ListManualLockPasswords
    // (Filament\Actions\Action, chỉ dùng được ở PAGE), không nằm trong ManualLockPasswordResource::
    // table() nên bị bỏ sót khi gộp — bổ sung lại ở đây bằng headerActions() của TABLE
    // (Filament\Tables\Actions\Action), tái dùng đúng form/logic gốc.
    public function table(Table $table): Table
    {
        return ManualLockPasswordResource::table($table->query(ManualLockPasswordResource::getEloquentQuery()))
            ->heading('Khóa thủ công')
            ->headerActions([
                ManualLockPasswordImportAction::make(),
                CreateAction::make()
                    ->form(fn (Form $form): Form => ManualLockPasswordResource::form($form)),
            ]);
    }
}
