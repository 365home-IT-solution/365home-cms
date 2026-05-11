<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Tables\Actions;

// Sửa lại phần import ở đây
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;

class CouponAction
{
    public static function action(): array // Nên thêm kiểu trả về là array
    {
        return [
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết'),
                EditAction::make()->label('Cập nhật'),
                // Chú ý: DeleteAction thường không nhận tham số chuỗi trực tiếp làm tên nếu dùng mặc định
                DeleteAction::make()->label('Xóa'),

                Action::make('toggle_status')
                    ->label(fn ($record) => $record->is_active ? 'Tạm dừng' : 'Kích hoạt')
                    ->icon(fn ($record) => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn ($record) => $record->is_active ? 'warning' : 'success')
                    ->action(function ($record) {
                        $record->update(['is_active' => !$record->is_active]);
                    })
                    ->requiresConfirmation(),
            ])
        ];
    }
}