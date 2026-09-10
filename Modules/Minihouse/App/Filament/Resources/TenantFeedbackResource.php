<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\TenantFeedbackResource\Forms\TenantFeedbackForm;
use Modules\Minihouse\App\Filament\Resources\TenantFeedbackResource\Pages;
use Modules\Minihouse\App\Filament\Resources\TenantFeedbackResource\Tables\TenantFeedbackTable;
use Modules\Minihouse\App\Models\TenantFeedback;

// Xem/xử lý phản hồi khách thuê tự gửi qua link công khai (xem TenantFeedbackController) — KHÔNG có
// trang "Tạo" trong panel, phản hồi CHỈ được tạo bởi khách thuê, nhân viên chỉ xem + đánh dấu đã xử
// lý + ghi chú nội bộ.
class TenantFeedbackResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = TenantFeedback::class;
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-ellipsis';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phản hồi khách thuê';
    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string { return 'Phản hồi'; }
    public static function getPluralModelLabel(): string { return 'Phản hồi khách thuê'; }

    public static function permissionGroup(): string
    {
        return 'feedbacks';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form { return TenantFeedbackForm::form($form); }
    public static function table(Table $table): Table { return TenantFeedbackTable::table($table); }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantFeedbacks::route('/'),
            'edit'  => Pages\EditTenantFeedback::route('/{record}/edit'),
        ];
    }
}
