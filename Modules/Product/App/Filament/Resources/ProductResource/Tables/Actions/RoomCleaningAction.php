<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions;

use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Modules\Product\App\Models\Product;

// Vòng đời "dọn vệ sinh" của 1 phòng: hết giờ đặt → tự động chuyển 'cleaning' (xem
// app/Console/Commands/MarkRoomsForCleaningCommand.php) → nhân viên TRỰC ĐÚNG CHI NHÁNH xác
// nhận đã dọn xong → phòng quay lại 'available'. Có thêm action đánh dấu thủ công cho trường
// hợp phòng cần dọn ngoài luồng tự động (khách trả phòng sớm, sự cố...).
class RoomCleaningAction
{
    public static function markForCleaning(): Action
    {
        return Action::make('markForCleaning')
            ->label('Đánh dấu cần dọn')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(fn (Product $record) => "Chuyển phòng \"{$record->name}\" sang trạng thái đang dọn vệ sinh?")
            // Đánh dấu "cần dọn" thủ công CHỈ dành cho chủ đối tác/super_admin — nhân viên chỉ
            // được xem tình trạng phòng + xác nhận đã dọn xong (confirmCleaning() bên dưới), để
            // luồng tự động (hết giờ đặt) là nguồn duy nhất tạo lượt "cần dọn" cho nhân viên.
            ->visible(fn () => self::canMarkForCleaning())
            ->action(function (Product $record): void {
                $record->markForCleaning();

                Notification::make()
                    ->title('Đã chuyển phòng sang trạng thái đang dọn vệ sinh')
                    ->warning()
                    ->send();
            });
    }

    public static function confirmCleaning(): Action
    {
        return Action::make('confirmCleaning')
            ->label('Xác nhận đã dọn xong')
            ->icon('heroicon-o-sparkles')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (Product $record) => "Xác nhận phòng \"{$record->name}\" đã dọn vệ sinh xong và sẵn sàng nhận đặt mới?")
            ->form([
                Textarea::make('note')
                    ->label('Ghi chú (không bắt buộc)')
                    ->rows(2),
            ])
            ->visible(fn (Product $record) => $record->housekeeping_status === 'cleaning' && self::canManageCleaning($record))
            ->action(function (Product $record, array $data): void {
                $employee = auth()->user()?->employee;

                if (! $employee) {
                    Notification::make()
                        ->title('Tài khoản này chưa có hồ sơ nhân viên liên kết — không thể xác nhận')
                        ->danger()
                        ->send();

                    return;
                }

                $record->confirmCleaned($employee, $data['note'] ?? null);

                Notification::make()
                    ->title('Đã xác nhận dọn xong — phòng sẵn sàng nhận đặt mới')
                    ->success()
                    ->send();
            });
    }

    // Chỉ chủ đối tác/super_admin được chủ động đánh dấu phòng "cần dọn" — nhân viên không có
    // quyền này (chỉ xem + xác nhận đã dọn xong khi phòng đã ở trạng thái 'cleaning').
    private static function canMarkForCleaning(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->isPartnerOwner());
    }

    // Chỉ super_admin, chủ đối tác, hoặc nhân viên TRỰC ĐÚNG CHI NHÁNH (work_branch_ids /
    // works_all_branches) của phòng này mới được xác nhận đã dọn xong — tránh nhân viên chi
    // nhánh khác xác nhận nhầm phòng không thuộc trách nhiệm của mình.
    // public (không còn private) — tái sử dụng bởi menu thao tác nhanh trên thẻ phòng ở
    // Dashboard (xem Modules/Dashboard/App/Filament/Pages/Dashboard.php::confirmRoomCleaning()).
    public static function canManageCleaning(Product $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isPartnerOwner()) {
            return true;
        }

        $employee = $user->employee;

        if (! $employee) {
            return false;
        }

        if ($employee->works_all_branches) {
            return true;
        }

        $branchIds = $employee->workBranches()->pluck('categories.id');
        $roomBranchIds = $record->categories()->where('category_type', 'product')->pluck('categories.id');

        return $roomBranchIds->intersect($branchIds)->isNotEmpty();
    }
}
