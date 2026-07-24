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
use Modules\Product\App\Models\ManualLockPassword;
use App\Services\TTLockService;

class AssignAccessCodeAction
{
    public static function make(): Action
    {
        return Action::make('assignAccessCode')
            ->label(fn (Order $record) => ($record->items->first()?->product?->has_manual_lock) ? 'Gửi mã cổng' : 'Cấp mã cổng')
            ->icon('heroicon-o-key')
            ->color('success')
            ->visible(function (Order $record): bool {
                if (!in_array($record->status, ['paid', 'deposit'])) {
                    return false;
                }
                $product = $record->items->first()?->product;
                if (! $product) {
                    return false;
                }
                if ($product->has_manual_lock) {
                    return true;
                }
                // Phòng đã gán lock_id nhưng chi nhánh KHÔNG CÒN tài khoản TTLock đang hoạt động
                // (vd tài khoản bị vô hiệu hóa sau khi đã gán khóa) — không thể cấp mã thật sự
                // được nữa, ẩn nút thay vì hiện ra rồi báo lỗi khi bấm.
                return $product->lock_id !== null && TTLockService::hasAccountForCategory($record->category_id);
            })
            ->modalHeading(fn (Order $record) => "Cấp mã cổng — Đơn #{$record->order_code}")
            ->modalWidth('md')
            ->fillForm(function (Order $record): array {
                $record->load('items.product');
                $product = $record->items->first()?->product;

                // Manual lock: không cần date fields
                if ($product && $product->has_manual_lock) {
                    return [];
                }

                $item = $record->items->first();
                return [
                    'valid_from'  => $item?->checkin_date  ?? now(),
                    'valid_until' => $item?->checkout_date ?? now()->addDays(1),
                ];
            })
            ->form(function (Order $record): array {
                $record->load(['accessCodes', 'items.product']);
                $item     = $record->items->first();
                $product  = $item?->product;
                $fields   = [];

                // ── Case: Phòng mật khẩu thủ công ──────────────────────────
                if ($product && $product->has_manual_lock) {
                    $checkinDate = $record->items->min('checkin_date');
                    $manualPwd   = ManualLockPassword::getForProductAndDate($product, $checkinDate);

                    if ($manualPwd && ($manualPwd->gate_password || $manualPwd->room_password)) {
                        $lines = [];
                        if ($manualPwd->gate_password) {
                            $lines[] = "🚪 Mã cổng: <b style='font-size:20px;font-family:monospace;letter-spacing:2px'>{$manualPwd->gate_password}</b>";
                        }
                        if ($manualPwd->room_password) {
                            $lines[] = "🏠 Mã phòng: <b style='font-size:20px;font-family:monospace;letter-spacing:2px'>{$manualPwd->room_password}</b>";
                        }

                        $fields[] = Placeholder::make('manual_pwd_info')
                            ->label('Mật khẩu hiện tại cho phòng')
                            ->content(new HtmlString(
                                '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">'
                                . '<div style="line-height:2.4;">' . implode('<br>', $lines) . '</div>'
                                . '<div style="font-size:11px;color:#6b7280;margin-top:8px;">Xác nhận để gửi thông báo kèm mã này đến khách hàng.</div>'
                                . '</div>'
                            ));
                    } else {
                        $fields[] = Placeholder::make('no_manual_pwd')
                            ->label('')
                            ->content(new HtmlString(
                                '<div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:14px 16px;">'
                                . '⚠️ Chưa có mật khẩu thủ công nào được cấu hình cho phòng <b>'
                                . htmlspecialchars($product->name ?? '')
                                . '</b>.<br><span style="font-size:12px;color:#6b7280">Vui lòng thêm trong phần quản lý phòng trước.</span>'
                                . '</div>'
                            ));
                    }

                    return $fields;
                }

                // ── Case: TTLock / AccessCode pool ──────────────────────────
                $existing = $record->accessCodes->first();

                if ($existing) {
                    $fields[] = Placeholder::make('current_code')
                        ->label('')
                        ->content(new HtmlString(
                            '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">'
                            . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;font-weight:600;margin-bottom:6px;">Mã cổng hiện tại</div>'
                            . '<div style="font-size:28px;font-family:monospace;font-weight:bold;color:#16a34a;letter-spacing:3px;">'
                            . htmlspecialchars((string) $existing->code)
                            . '</div>'
                            . '<div style="font-size:12px;color:#6b7280;margin-top:6px;">'
                            . 'Hiệu lực: ' . ($existing->valid_from?->format('d/m/Y H:i') ?? '—')
                            . ' → ' . ($existing->valid_until?->format('d/m/Y H:i') ?? '—')
                            . '</div>'
                            . '</div>'
                        ));
                }

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
            ->modalSubmitActionLabel(function (Order $record): string {
                $product = $record->items->first()?->product;
                if ($product && $product->has_manual_lock) {
                    return 'Gửi mã cho khách';
                }
                return $record->hasAccessCode() ? 'Cấp mã mới (thay thế)' : 'Cấp mã cổng';
            })
            ->action(function (Order $record, array $data): void {
                $record->load('items.product');
                $item         = $record->items->sortBy('checkin_date')->first();
                $checkinDate  = $record->items->min('checkin_date');
                $checkoutDate = $data['valid_until'] ?? $record->items->max('checkout_date');
                $product      = $item?->product;

                // ── Case: Phòng mật khẩu thủ công ──────────────────────────
                if ($product && $product->has_manual_lock) {
                    $manualPwd = ManualLockPassword::getForProductAndDate($product, $checkinDate);

                    if (!$manualPwd || (!$manualPwd->gate_password && !$manualPwd->room_password)) {
                        Notification::make()
                            ->title('Chưa có mật khẩu cho phòng này')
                            ->body('Vui lòng thêm mật khẩu thủ công trong phần quản lý phòng trước.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $parts = [];
                    if ($manualPwd->gate_password) $parts[] = 'Mã cổng: ' . $manualPwd->gate_password;
                    if ($manualPwd->room_password) $parts[] = 'Mã phòng: ' . $manualPwd->room_password;

                    $notifTitle = "Đơn #{$record->order_code}: Mã cổng đã được cấp";
                    $notifBody  = implode(' | ', $parts);
                    $extra      = ['order_code' => (string) $record->order_code, 'type' => 'order_access_code'];

                    try {
                        $notifService = app(\App\Services\NotificationFcmService::class);
                        if (is_null($record->customer_id) && $record->device_token) {
                            $notifService->sendToGuestToken($record->device_token, $notifTitle, $notifBody, 'order_access_code', $extra);
                        } elseif ($record->customer_id && $record->customer) {
                            $notifService->sendToCustomer($record->customer, $notifTitle, $notifBody, 'order_access_code', $extra);
                        }

                        app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                            (string) $record->order_code,
                            [
                                'access_code_assigned' => true,
                                'lock_type'            => 'manual',
                                'gate_password'        => $manualPwd->gate_password,
                                'room_password'        => $manualPwd->room_password,
                            ],
                            $record->customer_id ? (int) $record->customer_id : null,
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('AssignAccessCodeAction: manual FCM failed', [
                            'order_id' => $record->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }

                    Notification::make()
                        ->title('Đã gửi mã cổng cho khách')
                        ->body($notifBody)
                        ->success()
                        ->send();
                    return;
                }

                // ── Case: TTLock / AccessCode pool ──────────────────────────
                try {
                    /** @var AccessCodeService $service */
                    $service = app(AccessCodeService::class);
                    $code    = $service->assignCodeToOrder(
                        $record->id,
                        $record->category_id,
                        $data['valid_from']  ?? $checkinDate,
                        $checkoutDate,
                        $product,
                    );

                    Notification::make()
                        ->title('Cấp mã cổng thành công')
                        ->body("Mã {$code->code} đã được gán cho đơn #{$record->order_code}")
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    $msg       = $e->getMessage();
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
