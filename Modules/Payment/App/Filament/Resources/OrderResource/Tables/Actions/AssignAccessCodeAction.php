<?php

declare(strict_types=1);

namespace Modules\Payment\App\Filament\Resources\OrderResource\Tables\Actions;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Support\HtmlString;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\Payment\Entities\Order;

class AssignAccessCodeAction
{
    public static function make(): Action
    {
        return Action::make('assignAccessCode')
            ->label('Cấp mã cổng')
            ->icon('heroicon-o-key')
            ->color('success')
            ->modalHeading(fn(Order $record) => "Cấp mã cổng — Đơn #{$record->order_code}")
            ->modalWidth('md')
            ->fillForm(function (Order $record): array {
                $record->load('items.product');
                $item = $record->items->first();
                return [
                    'valid_from'  => $item?->checkin_date  ?? now(),
                    'valid_until' => $item?->checkout_date ?? now()->addDays(1),
                ];
            })
            ->form(function (Order $record): array {
                $record->load(['accessCodes', 'items.product']);
                $existing = $record->accessCodes->first();
                $item     = $record->items->first();
                $product  = $item?->product;
                $fields   = [];

                // Hiển thị mã hiện tại nếu đã có
                if ($existing) {
                    $fields[] = Placeholder::make('current_code')
                        ->label('')
                        ->content(new HtmlString(
                            '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">'
                            . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;font-weight:600;margin-bottom:6px;">Mã cổng hiện tại</div>'
                            . '<div style="font-size:28px;font-family:monospace;font-weight:bold;color:#16a34a;letter-spacing:3px;">'
                            . htmlspecialchars($existing->code)
                            . '</div>'
                            . '<div style="font-size:12px;color:#6b7280;margin-top:6px;">'
                            . 'Hiệu lực: ' . ($existing->valid_from?->format('d/m/Y H:i') ?? '—')
                            . ' → ' . ($existing->valid_until?->format('d/m/Y H:i') ?? '—')
                            . '</div>'
                            . '</div>'
                        ));
                }

                // Thông tin phòng / khóa
                if ($product) {
                    $lockInfo = $product->lock_id
                        ? "🔑 Lock check-in: <code>{$product->lock_id}</code>"
                          . ($product->lock_id_checkout ? " / Lock check-out: <code>{$product->lock_id_checkout}</code>" : '')
                        : '⚠️ Phòng <b>chưa có TTLock</b> — sẽ dùng mã từ danh sách thủ công.';

                    $fields[] = Placeholder::make('product_info')
                        ->label('Phòng & Khóa')
                        ->content(new HtmlString(
                            '<div style="font-size:13px;line-height:1.6;">'
                            . "<div>🏠 <b>{$product->name}</b></div>"
                            . "<div>{$lockInfo}</div>"
                            . '</div>'
                        ));
                }

                $fields[] = DateTimePicker::make('valid_from')
                    ->label('Bắt đầu hiệu lực (check-in)')
                    ->required()
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i');

                $fields[] = DateTimePicker::make('valid_until')
                    ->label('Hết hạn (check-out)')
                    ->required()
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->after('valid_from');

                return $fields;
            })
            ->modalSubmitActionLabel(fn(Order $record) => $record->hasAccessCode() ? 'Cấp mã mới (thay thế)' : 'Cấp mã cổng')
            ->action(function (Order $record, array $data): void {
                $record->load('items.product');
                $item = $record->items->sortBy('checkin_date')->first();

                $checkinDate  = $data['valid_from']  ?? $record->items->min('checkin_date');
                $checkoutDate = $data['valid_until'] ?? $record->items->max('checkout_date');
                $product      = $item?->product;

                try {
                    /** @var AccessCodeService $service */
                    $service = app(AccessCodeService::class);
                    $code = $service->assignCodeToOrder(
                        $record->id,
                        $record->category_id,
                        $checkinDate,
                        $checkoutDate,
                        $product,
                    );

                    Notification::make()
                        ->title('Cấp mã cổng thành công')
                        ->body("Mã {$code->code} đã được gán cho đơn #{$record->order_code}")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    // Phân biệt lỗi timeout vs lỗi khác
                    $isTimeout = str_contains($msg, 'timed out') || str_contains($msg, 'Connection') || str_contains($msg, 'TTLock API');
                    Notification::make()
                        ->title($isTimeout ? 'TTLock tạm thời không phản hồi' : 'Không thể cấp mã cổng')
                        ->body($isTimeout
                            ? 'Kết nối TTLock bị timeout. Token đã được cache — vui lòng nhấn cấp mã lại ngay bây giờ.'
                            : $msg
                        )
                        ->danger()
                        ->send();
                }
            });
    }
}
