<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Forms\Form;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Forms\AccessCodeForm;
use Modules\AccessCode\App\Filament\Resources\AccessCodeResource\Tables\AccessCodeTable;

// Bảng "Pass Cổng" bên trong trang gộp "Khóa cổng" (App\Filament\Pages\GateLockManagement) — tái
// dùng NGUYÊN VẸN cột/filter/action/quyền hạn đã có ở AccessCodeResource, chỉ đổi nơi hiển thị
// (AccessCodeResource vẫn còn route thật, chỉ ẩn khỏi menu — xem shouldRegisterNavigation() ở đó).
class AccessCodeTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('view_any_access::code') ?? false;
    }

    // Ghi đè table() thay hẳn logic mặc định của InteractsWithTable (gồm cả
    // ->query($this->getTableQuery())) — nên phải tự gắn ->query() ở đây, KHÔNG thể chỉ override
    // getTableQuery() (nó sẽ không bao giờ được gọi tới nữa). Từng bị lỗi "Table ... must have a
    // [query()]" vì thiếu dòng này.
    //
    // Nút "Thêm mới" trước đây nằm ở header của trang ListAccessCode (Filament\Actions\Action, chỉ
    // dùng được ở PAGE), không nằm trong AccessCodeTable::table() nên bị bỏ sót khi gộp — bổ sung
    // lại bằng pushHeaderActions() (CỘNG THÊM, không thay thế GenerateTTLockPasscodesAction đã có
    // sẵn trong AccessCodeTable::table() — headerActions() thường sẽ ghi đè toàn bộ danh sách cũ).
    public function table(Table $table): Table
    {
        return AccessCodeTable::table($table->query(AccessCodeResource::getEloquentQuery()))
            ->heading('Pass Cổng')
            ->pushHeaderActions([
                CreateAction::make()
                    ->form(fn (Form $form): Form => AccessCodeForm::form($form)),
            ]);
    }
}
