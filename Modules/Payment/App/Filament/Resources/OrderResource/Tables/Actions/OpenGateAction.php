<?php

declare(strict_types=1);

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions;

use Modules\TTLock\App\Services\TTLockService;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;

class OpenGateAction
{
    public static function make(): Action
    {
        return Action::make('openGate')
            ->label('Mở cổng tự do')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(function (Order $record): bool {
                if (!in_array($record->status, ['paid', 'deposit'])) {
                    return false;
                }
                $product = $record->items->first()?->product;
                if (!$product || !$product->lock_id) {
                    return false;
                }
                // Dùng hasAccountForCategory() (có cache tĩnh trong request) thay vì forCategory()
                // — ->visible() chạy lại cho MỖI dòng của bảng đơn hàng, forCategory() dựng cả 1
                // instance TTLockService mỗi lần dù chỉ cần biết có/không, tốn hơn không cần thiết.
                return TTLockService::hasAccountForCategory($record->category_id);
            })
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => "Mở cổng — Đơn #{$record->order_code}")
            ->modalDescription('Hệ thống sẽ gửi lệnh mở khóa TTLock ngay lập tức. Chỉ dùng khi cần hỗ trợ khách vào phòng khẩn cấp.')
            ->modalSubmitActionLabel('Mở cổng ngay')
            ->action(function (Order $record): void {
                $record->load('items.product');
                $product = $record->items->first()?->product;

                if (!$product || !$product->lock_id) {
                    Notification::make()
                        ->title('Phòng này không có TTLock')
                        ->danger()
                        ->send();
                    return;
                }

                $ttlock = TTLockService::forCategory($record->category_id);
                if (!$ttlock) {
                    Notification::make()
                        ->title('Chi nhánh chưa kết nối TTLock')
                        ->danger()
                        ->send();
                    return;
                }

                // Cửa gắn 2 ổ cần nhả CÙNG LÚC (Product::unlock_both_locks) — mở cả 2 ổ, chỉ báo
                // thành công khi cả 2 đều mở được (xem TTLockService::remoteUnlockBoth()).
                $bothLocksRequired = $product->unlock_both_locks && $product->lock_id && $product->lock_id_checkout;

                $success = $bothLocksRequired
                    ? $ttlock->remoteUnlockBoth((int) $product->lock_id, (int) $product->lock_id_checkout)['success']
                    : $ttlock->remoteUnlock((int) $product->lock_id);

                Log::info('OpenGateAction', [
                    'order_id'    => $record->id,
                    'order_code'  => $record->order_code,
                    'lock_id'     => $product->lock_id,
                    'both_locks'  => $bothLocksRequired,
                    'success'     => $success,
                    'admin'       => auth()->user()?->email,
                ]);

                if ($success) {
                    Notification::make()
                        ->title("Đã mở cổng — Đơn #{$record->order_code}")
                        ->body('Lệnh mở khóa đã được gửi thành công đến TTLock.')
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Mở cổng thất bại')
                        ->body('TTLock không phản hồi hoặc tính năng Remote Unlock chưa được bật trên khóa.')
                        ->danger()
                        ->send();
                }
            });
    }
}
