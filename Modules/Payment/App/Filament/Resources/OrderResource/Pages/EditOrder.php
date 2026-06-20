<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use Modules\Payment\App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\BladeThemeV1\Services\Zns\ZaloZnsService;
use Modules\BladeThemeV1\Services\OcrSpaceService;
use Modules\Payment\App\Services\CccdScannerService;
use App\Services\OrderRealtimeService;
use App\Services\ExtraChargeService;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $paymentStatus = request()->query('payment_status');

        if ($paymentStatus === 'success') {
            Notification::make()
                ->title('Thanh toán thành công')
                ->body('Đơn hàng đang được xử lý. Trạng thái sẽ cập nhật tự động khi PayOS xác nhận.')
                ->success()
                ->send();
        } elseif ($paymentStatus === 'cancelled') {
            // Webhook có thể chưa kịp fire — cập nhật trực tiếp khi admin quay lại
            if ($this->record->status === 'pending') {
                $this->record->update(['status' => 'failed']);
            }
            Notification::make()
                ->title('Thanh toán đã bị huỷ')
                ->body('Đơn đã lưu nhưng chưa được thanh toán. Trạng thái: Hủy thanh toán.')
                ->warning()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // =========================================================
            // TEST: Giả lập thanh toán cọc thành công (không cần QR)
            // =========================================================
            Actions\Action::make('simulateDepositPaid')
                ->label('⚡ Giả lập: Cọc đã thanh toán')
                ->icon('heroicon-m-beaker')
                ->color('warning')
                ->visible(function () {
                    $record = $this->record;
                    return app()->isLocal()
                        || config('app.debug')
                        || (auth()->user()?->isSuperAdmin() ?? false);
                })
                ->visible(fn () => $this->record->status === 'pending')
                ->requiresConfirmation()
                ->modalHeading('Giả lập thanh toán cọc thành công')
                ->modalDescription(function () {
                    $r = $this->record;
                    $depositPct = $r->deposit_percent;
                    $fullAmt    = $r->full_amount ?? $r->amount;
                    $amountPaid = $r->amount;
                    if ($depositPct) {
                        $remaining  = $fullAmt - $amountPaid;
                        return "Giả lập khách đã quét QR và thanh toán CỌC {$depositPct}% = "
                            . number_format($amountPaid, 0, ',', '.') . "đ.\n"
                            . "Còn lại " . number_format($remaining, 0, ',', '.') . "đ thanh toán khi nhận phòng.\n"
                            . "Hệ thống sẽ: đánh dấu PAID → gán mã cổng → gửi ZNS cho khách.";
                    }
                    return "Giả lập khách đã thanh toán " . number_format($amountPaid, 0, ',', '.') . "đ.\n"
                        . "Hệ thống sẽ: đánh dấu PAID → gán mã cổng → gửi ZNS cho khách.";
                })
                ->modalSubmitActionLabel('Xác nhận giả lập')
                ->action(function () {
                    $record = $this->record->fresh(['items']);

                    try {
                        // 1. Đánh dấu đã thanh toán
                        $record->update(['status' => 'paid']);

                        // 2. Gán mã cổng
                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                        $checkinDate  = $record->items->min('checkin_date');
                        $checkoutDate = $record->items->max('checkout_date');
                        $product      = $firstItem?->product;

                        $accessCodeService = app(AccessCodeService::class);
                        $accessCode = $accessCodeService->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        // 3. Gửi ZNS cho khách
                        try {
                            $znsService = app(ZaloZnsService::class);
                            $znsService->sendBookingSuccessNotification($record, $accessCode);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('simulateDepositPaid: ZNS failed', ['error' => $e->getMessage()]);
                        }

                        $depositPct  = $record->deposit_percent;
                        $fullAmt     = $record->full_amount ?? $record->amount;
                        $amountPaid  = $record->amount;
                        $remaining   = $fullAmt - $amountPaid;

                        $body = $depositPct
                            ? "Đã nhận cọc {$depositPct}% = " . number_format($amountPaid, 0, ',', '.') . "đ. "
                              . "Còn lại " . number_format($remaining, 0, ',', '.') . "đ khi nhận phòng."
                            : "Đã thanh toán đủ " . number_format($amountPaid, 0, ',', '.') . "đ. Mã cổng đã gửi qua Zalo.";

                        Notification::make()
                            ->title('Giả lập thành công')
                            ->body($body)
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Lỗi giả lập')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('assignAccessCode')
                ->label(fn () => $this->record->hasAccessCode() ? 'Cấp lại mã cổng' : 'Gán mã cổng')
                ->icon(fn () => $this->record->hasAccessCode() ? 'heroicon-m-arrow-path' : 'heroicon-m-key')
                ->color(fn () => $this->record->hasAccessCode() ? 'warning' : 'success')
                ->visible(fn () => (auth()->user()?->can('update_order') ?? false) && in_array($this->record->status, ['paid', 'deposit']))
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->record->hasAccessCode() ? 'Cấp lại mã cổng' : 'Gán mã cổng cho đơn hàng')
                ->modalDescription(fn () => $this->record->hasAccessCode()
                    ? 'Hệ thống sẽ thu hồi mã cổng hiện tại và cấp mã mới. Khách hàng sẽ được thông báo realtime.'
                    : 'Hệ thống sẽ tự động tìm mã cổng khả dụng và gán cho đơn này. Khách hàng sẽ được thông báo realtime.')
                ->modalSubmitActionLabel(fn () => $this->record->hasAccessCode() ? 'Xác nhận cấp lại' : 'Xác nhận gán')
                ->action(function () {
                    $record = $this->record->fresh(['items.product', 'accessCodes']);

                    try {
                        $service = app(AccessCodeService::class);

                        // Thu hồi mã cũ nếu đã có
                        if ($record->hasAccessCode()) {
                            $service->releaseCode($record->id);
                        }

                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                        $checkinDate  = $record->items->min('checkin_date');
                        $checkoutDate = $record->items->max('checkout_date');
                        $product      = $firstItem?->product;

                        $code = $service->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        // Notify realtime + FCM đến khách
                        $notifService = app(\App\Services\NotificationFcmService::class);
                        $title        = "Đơn #{$record->order_code}: mã cổng đã được cấp";
                        $body         = 'Mã cổng của bạn đã sẵn sàng. Vui lòng kiểm tra trong chi tiết đơn hàng.';
                        $extra        = ['order_code' => (string) $record->order_code, 'type' => 'order_access_code'];
                        $this->pushClientNotification($record, $notifService, $title, $body, 'order_access_code', $extra);

                        app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                            (string) $record->order_code,
                            ['access_code_assigned' => true],
                            $record->customer_id ? (int) $record->customer_id : null,
                        );

                        Notification::make()
                            ->title('Đã gán mã cổng: ' . $code->code)
                            ->success()
                            ->send();

                        $this->record = $record->fresh();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể gán mã cổng')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('changeManualCode')
                ->label('Đổi mã cổng (thủ công)')
                ->icon('heroicon-m-key')
                ->color('info')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    if (! in_array($this->record->status, ['paid', 'deposit'])) {
                        return false;
                    }
                    // Chỉ hiện khi đơn có access code nhập tay (không có ttlock_keyboard_pwd_id)
                    return $this->record->accessCodes()
                        ->whereNull('ttlock_keyboard_pwd_id')
                        ->exists();
                })
                ->form([
                    \Filament\Forms\Components\Select::make('new_access_code_id')
                        ->label('Chọn mã cổng mới')
                        ->options(function () {
                            return \Modules\AccessCode\Entities\AccessCode::where('status', 'active')
                                ->where('category_id', $this->record->category_id)
                                ->whereNull('ttlock_keyboard_pwd_id')
                                ->whereDoesntHave('orders', fn ($q) => $q->where('orders.id', $this->record->id))
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->id => $c->code . ($c->gate_location ? " ({$c->gate_location})" : '')])
                                ->toArray();
                        })
                        ->required()
                        ->searchable(),
                ])
                ->requiresConfirmation()
                ->modalHeading('Đổi mã cổng thủ công')
                ->modalDescription('Mã cổng cũ sẽ bị thu hồi và mã mới sẽ được gán cho đơn này. Khách hàng sẽ được thông báo realtime.')
                ->modalSubmitActionLabel('Xác nhận đổi mã')
                ->action(function (array $data) {
                    $record = $this->record->fresh(['items', 'accessCodes']);

                    try {
                        $newCode = \Modules\AccessCode\Entities\AccessCode::findOrFail($data['new_access_code_id']);

                        // Thu hồi mã cũ (chỉ manual, không gọi TTLock API)
                        $record->accessCodes()
                            ->whereNull('ttlock_keyboard_pwd_id')
                            ->each(fn ($ac) => $ac->orders()->detach($record->id));

                        // Gán mã mới
                        $newCode->assignToOrder($record->id);

                        // Notify realtime đến client
                        try {
                            app(OrderRealtimeService::class)->broadcastCodeChanged(
                                (string) $record->order_code,
                                (string) $newCode->code,
                                'manual',
                                $record->customer_id,
                            );
                        } catch (\Throwable) {}

                        Notification::make()
                            ->title("Đã đổi mã cổng thành: {$newCode->code}")
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể đổi mã cổng')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('changeTtlockCode')
                ->label('Tạo mã mới (TTLock)')
                ->icon('heroicon-m-arrow-path')
                ->color('info')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    if (! in_array($this->record->status, ['paid', 'deposit'])) {
                        return false;
                    }
                    // Chỉ hiện khi đơn có access code TTLock
                    return $this->record->accessCodes()
                        ->whereNotNull('ttlock_keyboard_pwd_id')
                        ->exists();
                })
                ->requiresConfirmation()
                ->modalHeading('Tạo mã TTLock mới')
                ->modalDescription('Mã cũ sẽ bị xóa khỏi khóa, một mã 6 số mới sẽ được tạo và cập nhật lên TTLock. Khách hàng sẽ được thông báo realtime.')
                ->modalSubmitActionLabel('Xác nhận tạo mã mới')
                ->action(function () {
                    $record = $this->record->fresh(['items.product', 'accessCodes']);

                    try {
                        $accessCodeService = app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class);

                        // Thu hồi mã TTLock cũ (gọi TTLock API delete + xóa record)
                        $accessCodeService->releaseCode($record->id);

                        // Cấp mã TTLock mới
                        $firstItem    = $record->items->where('extra_fee', 0)->first() ?? $record->items->first();
                        $checkinDate  = $firstItem?->checkin_date;
                        $checkoutDate = $firstItem?->checkout_date;
                        $product      = $firstItem?->product;

                        $newCode = $accessCodeService->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        if (! $newCode) {
                            Notification::make()
                                ->title('Phòng này dùng khóa thủ công, không cần tạo mã TTLock.')
                                ->warning()
                                ->send();
                            return;
                        }

                        // Notify realtime đến client
                        try {
                            app(OrderRealtimeService::class)->broadcastCodeChanged(
                                (string) $record->order_code,
                                (string) $newCode->code,
                                'ttlock',
                                $record->customer_id,
                            );
                        } catch (\Throwable) {}

                        Notification::make()
                            ->title("Đã tạo mã TTLock mới: {$newCode->code}")
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể tạo mã TTLock mới')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('scanCccdQr')
                ->label('[TEST] Quét QR CCCD')
                ->icon('heroicon-m-qr-code')
                ->color('gray')
                ->visible(fn () => (bool) ($this->record->cccd_front || $this->record->cccd_back))
                ->action(function () {
                    $record = $this->record->fresh();
                    $data   = app(CccdScannerService::class)->scanOrder($record);

                    if (! $data) {
                        Notification::make()
                            ->title('Không đọc được QR CCCD')
                            ->body('Ảnh quá nhỏ hoặc QR bị mờ. Vui lòng upload lại ảnh gốc chất lượng cao (không resize).')
                            ->warning()
                            ->send();
                        return;
                    }

                    $note = implode("\n", array_filter([
                        $data['cccd']        ? "Số CCCD:   {$data['cccd']}"        : null,
                        $data['full_name']   ? "Họ và tên: {$data['full_name']}"   : null,
                        $data['dob']         ? "Ngày sinh: {$data['dob']}"         : null,
                        $data['gender']      ? "Giới tính: {$data['gender']}"      : null,
                        $data['address']     ? "Địa chỉ:   {$data['address']}"     : null,
                    ]));

                    $updateFields = [
                        'cccd_data'      => $data,
                        'note_for_admin' => $note,
                    ];

                    if (! empty($data['full_name'])) {
                        $updateFields['buyer_name'] = $data['full_name'];
                    }
                    if (! empty($data['address'])) {
                        $updateFields['buyer_address'] = $data['address'];
                    }

                    $record->update($updateFields);

                    $this->refreshFormData(['note_for_admin', 'cccd_data', 'buyer_name', 'buyer_address']);

                    Notification::make()
                        ->title('Quét CCCD thành công')
                        ->body($note)
                        ->success()
                        ->send();
                }),

            Actions\Action::make('retryOcr')
                ->label('Thu thập lại CCCD')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                ->visible(fn () => auth()->user()?->can('update_order') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Thu thập lại thông tin OCR từ CCCD')
                ->modalDescription('Hệ thống sẽ quét lại ảnh CCCD mặt trước và cập nhật thông tin vào trường "Thông tin người dùng". Bạn có chắc chắn muốn tiếp tục?')
                ->modalSubmitActionLabel('Xác nhận')
                ->action(function () {
                    // Refresh the record to get the latest data
                    $record = $this->record->fresh();

                    if (!$record->cccd_front) {
                        Notification::make()
                            ->title('Không tìm thấy ảnh CCCD')
                            ->body('Vui lòng upload ảnh CCCD mặt trước trước khi thu thập OCR.')
                            ->danger()
                            ->send();
                        return;
                    }

                    try {
                        $ocrService = app(OcrSpaceService::class);

                        if (!$ocrService->isConfigured()) {
                            Notification::make()
                                ->title('OCR chưa được cấu hình')
                                ->body('Vui lòng cấu hình OCR_SPACE_API_KEY trong file .env')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Get the full path to the CCCD image
                        $cccdPath = Storage::disk('public')->path($record->cccd_front);

                        if (!file_exists($cccdPath)) {
                            Notification::make()
                                ->title('File không tồn tại')
                                ->body("Không tìm thấy file: {$record->cccd_front}")
                                ->danger()
                                ->send();
                            return;
                        }

                        // Extract text from CCCD front
                        $frontText = $ocrService->extractTextFromImage($cccdPath);

                        if (empty($frontText)) {
                            Notification::make()
                                ->title('OCR thất bại')
                                ->body('Không thể trích xuất văn bản từ ảnh CCCD. Vui lòng kiểm tra log để biết chi tiết.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Format CCCD info (Name, ID, Home address)
                        $formattedInfo = $ocrService->formatCccdInfo($frontText);

                        // Update the record
                        $record->update([
                            'note_for_admin' => $formattedInfo
                        ]);

                        // Refresh form data to show the updated value immediately
                        $this->refreshFormData([
                            'note_for_admin'
                        ]);

                        Notification::make()
                            ->title('Thu thập OCR thành công')
                            ->body('Thông tin CCCD đã được cập nhật vào trường "Thông tin người dùng".')
                            ->success()
                            ->send();

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Lỗi khi thu thập OCR')
                            ->body('Chi tiết: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Actions\Action::make('regenerateExtraChargeQr')
                ->label('Tạo lại QR phát sinh')
                ->icon('heroicon-m-qr-code')
                ->color('warning')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    $r = $this->record;
                    return $r->extra_charge_amount
                        && is_null($r->extra_charge_paid_at)
                        && $r->status === 'paid';
                })
                ->requiresConfirmation()
                ->modalHeading('Tạo lại QR thanh toán phát sinh')
                ->modalDescription(function () {
                    $amount = number_format((int) $this->record->extra_charge_amount, 0, ',', '.');
                    return "Tạo lại link PayOS mới cho khoản phát sinh {$amount}đ của đơn #{$this->record->order_code}? Link cũ sẽ bị hủy.";
                })
                ->modalSubmitActionLabel('Tạo lại')
                ->action(function () {
                    $record = $this->record->fresh();

                    if (! $record->extra_charge_amount || ! is_null($record->extra_charge_paid_at)) {
                        Notification::make()
                            ->title('Không có khoản phát sinh cần tạo lại QR')
                            ->warning()
                            ->send();
                        return;
                    }

                    try {
                        $result = app(ExtraChargeService::class)->createExtraChargePayOS($record, (int) $record->extra_charge_amount);

                        // Notify khách QR mới
                        $notifService = app(\App\Services\NotificationFcmService::class);
                        $amount       = number_format((int) $record->extra_charge_amount, 0, ',', '.');
                        $title        = "Đơn #{$record->order_code}: QR thanh toán phát sinh mới";
                        $body         = "Link thanh toán {$amount}đ đã được tạo lại. Vui lòng thanh toán trong 60 phút.";
                        $extra        = [
                            'order_code'   => (string) $record->order_code,
                            'type'         => 'order_extra_charge',
                            'checkout_url' => $result['checkout_url'],
                            'amount'       => (int) $record->extra_charge_amount,
                        ];
                        $this->pushClientNotification($record, $notifService, $title, $body, 'order_extra_charge', $extra);

                        app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                            (string) $record->order_code,
                            ['extra_charge' => ['is_expired' => false, 'qr_code' => $result['qr_code']]],
                            $record->customer_id ? (int) $record->customer_id : null,
                        );

                        Notification::make()
                            ->title('Đã tạo lại QR thanh toán phát sinh')
                            ->body('Link mới: ' . $result['checkout_url'])
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('open')
                                    ->label('Mở link')
                                    ->url($result['checkout_url'])
                                    ->openUrlInNewTab(),
                            ])
                            ->success()
                            ->persistent()
                            ->send();

                        $this->refreshFormData(['extra_charge_checkout_url', 'extra_charge_expired_at']);

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Không thể tạo lại QR')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('collectExtraChargeCash')
                ->label('Thu tiền mặt (phát sinh)')
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->visible(function () {
                    if (! (auth()->user()?->can('update_order') ?? false)) {
                        return false;
                    }
                    $r = $this->record;
                    // Chỉ hiện cho đơn paid: extra charge là khoản thu riêng sau thanh toán.
                    // Deposit: extra đã gộp vào remaining, admin thu qua nút trong form.
                    return $r->extra_charge_amount
                        && is_null($r->extra_charge_paid_at)
                        && $r->status === 'paid';
                })
                ->requiresConfirmation()
                ->modalHeading('Xác nhận thu tiền mặt')
                ->modalDescription(function () {
                    $amount = number_format((int) $this->record->extra_charge_amount, 0, ',', '.');
                    return "Xác nhận đã thu {$amount}đ tiền mặt cho khoản phát sinh của đơn #{$this->record->order_code}?";
                })
                ->modalSubmitActionLabel('Xác nhận đã thu')
                ->action(function () {
                    $record = $this->record->fresh();

                    if (! $record->extra_charge_amount || ! is_null($record->extra_charge_paid_at)) {
                        Notification::make()
                            ->title('Không có khoản phát sinh chờ thanh toán')
                            ->warning()
                            ->send();
                        return;
                    }

                    try {
                        app(\App\Services\ExtraChargeService::class)->markExtraChargeAsCash($record, (int) $record->extra_charge_amount);

                        $notifService = app(\App\Services\NotificationFcmService::class);
                        $title        = "Đơn #{$record->order_code}: khoản phát sinh đã thanh toán";
                        $body         = 'Khoản phát sinh ' . number_format((int) $record->extra_charge_amount, 0, ',', '.') . 'đ đã được ghi nhận (tiền mặt).';
                        $extra        = ['order_code' => (string) $record->order_code, 'type' => 'order_extra_charge_paid'];

                        $this->pushClientNotification($record, $notifService, $title, $body, 'order_extra_charge_paid', $extra);

                        app(\App\Services\OrderRealtimeService::class)->broadcastOrderUpdate(
                            (string) $record->order_code,
                            ['extra_charge' => ['is_paid' => true, 'paid_at' => now()->toISOString(), 'payment_method' => 'cod']],
                            $record->customer_id ? (int) $record->customer_id : null,
                        );

                        Notification::make()
                            ->title('Đã ghi nhận thu tiền mặt')
                            ->body(number_format((int) $record->extra_charge_amount, 0, ',', '.') . 'đ')
                            ->success()
                            ->send();

                        $this->refreshFormData(['extra_charge_amount', 'extra_charge_paid_at', 'extra_charge_payment_method']);

                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Lỗi khi ghi nhận thu tiền')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }

    private function sendStatusChangeNotification(\Modules\Payment\Entities\Order $order): void
    {
        $messages = [
            'paid'               => ['Đặt phòng thành công!',      "Đơn #{$order->order_code} đã được xác nhận thanh toán."],
            'confirmed'          => ['Đơn đã được xác nhận',       "Đơn #{$order->order_code} đã được xác nhận bởi chúng tôi."],
            'deposit'            => ['Đã nhận tiền cọc',           "Đơn #{$order->order_code} đã nhận cọc " . ($order->deposit_percent ?? '') . "%."],
            'cancelled'          => ['Đơn đã bị hủy',              "Đơn #{$order->order_code} đã bị hủy. Liên hệ chúng tôi nếu có thắc mắc."],
            'cancelled_payment'  => ['Thanh toán hết hạn',         "Đơn #{$order->order_code} đã hết hạn thanh toán."],
            'failed'             => ['Thanh toán thất bại',        "Đơn #{$order->order_code} thanh toán không thành công."],
            'shipped'            => ['Đơn đang được xử lý',        "Đơn #{$order->order_code} đang được xử lý."],
        ];

        [$title, $body] = $messages[$order->status] ?? [null, null];

        if (! $title) {
            return;
        }

        $extra = ['order_code' => (string) $order->order_code, 'type' => 'order_status_changed'];
        $notifService = app(\App\Services\NotificationFcmService::class);

        try {
            // Guest (không có customer_id) → dùng device_token lưu trên order
            if (is_null($order->customer_id) && $order->device_token) {
                $notifService->sendToGuestToken($order->device_token, $title, $body, 'order_status_changed', $extra);
                return;
            }

            // Customer đăng nhập
            if ($order->customer_id) {
                $customer = $order->customer;
                if ($customer) {
                    $notifService->sendToCustomer($customer, $title, $body, 'order_status_changed', $extra);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EditOrder: sendStatusChangeNotification failed', [
                'order_id' => $order->id,
                'status'   => $order->status,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** Ngày checkout cũ trước khi save (để so sánh) */
    protected ?string $oldCheckoutDate = null;
    protected ?string $oldCheckinDate  = null;
    protected ?string $oldStatus       = null;
    protected ?int    $oldGuestCount    = null;
    protected array   $oldServices      = [];
    protected int     $oldServicesTotal = 0;

    /**
     * Ghi nhớ ngày cũ, trạng thái cũ, guest_count cũ và services cũ trước khi lưu để so sánh sau.
     */
    protected function beforeSave(): void
    {
        $firstItem = $this->record->items()->where('extra_fee', 0)->first();
        $this->oldCheckoutDate  = $firstItem?->checkout_date?->toDateTimeString();
        $this->oldCheckinDate   = $firstItem?->checkin_date?->toDateTimeString();
        $this->oldStatus        = $this->record->status;
        $this->oldGuestCount    = (int) $this->record->guest_count;
        $this->oldServicesTotal = (int) $this->record->services()->sum('subtotal');
        $this->oldServices      = $this->record->services()
            ->get(['service_id', 'quantity'])
            ->map(fn ($s) => ['service_id' => $s->service_id, 'quantity' => $s->quantity])
            ->toArray();
    }

    /**
     * Sau khi lưu đơn, gửi thông báo nếu trạng thái thay đổi và cập nhật TTLock nếu ngày thay đổi.
     */
    protected function afterSave(): void
    {
        $record = $this->record->fresh(['items.product', 'accessCodes', 'services']);

        // ── Gửi push notification khi status thay đổi ────────────────────────
        if ($this->oldStatus && $record->status !== $this->oldStatus) {
            $this->sendStatusChangeNotification($record);
        }

        // ── Broadcast realtime + xử lý chênh lệch giá khi thay đổi ─────────
        $guestCountChanged = (int) $record->guest_count !== $this->oldGuestCount;

        $newServicesSnapshot = $record->services
            ->map(fn ($s) => ['service_id' => $s->service_id, 'quantity' => $s->quantity])
            ->toArray();
        $servicesChanged = $newServicesSnapshot !== $this->oldServices;

        if ($guestCountChanged || $servicesChanged) {
            $changes = [];

            if ($guestCountChanged) {
                $changes['guest_count'] = ['from' => $this->oldGuestCount, 'to' => $record->guest_count];
            }
            if ($servicesChanged) {
                $changes['services'] = $record->services->map(fn ($s) => [
                    'service_name' => $s->service_name,
                    'quantity'     => $s->quantity,
                    'subtotal'     => $s->subtotal,
                ])->values()->toArray();
            }

            // Broadcast realtime đến client
            try {
                app(OrderRealtimeService::class)->broadcastOrderUpdate(
                    (string) $record->order_code,
                    [
                        'guest_count' => $record->guest_count,
                        'services'    => $record->services->map(fn ($s) => [
                            'service_name' => $s->service_name,
                            'quantity'     => $s->quantity,
                            'subtotal'     => $s->subtotal,
                        ])->values()->toArray(),
                        'changes' => $changes,
                    ],
                    $record->customer_id ? (int) $record->customer_id : null,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('afterSave: broadcastOrderUpdate failed', [
                    'order_id' => $record->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            // FCM + WS notification đến khách về thay đổi đơn
            $this->sendOrderUpdateNotification($record, $changes);

            // Xử lý chênh lệch giá (chỉ khi đơn đã thanh toán ít nhất 1 lần)
            if (in_array($record->status, ['paid', 'deposit'])) {
                $this->handlePriceDiff($record);
            }
        }

        $firstItem    = $record->items->where('extra_fee', 0)->first() ?? $record->items->first();
        $newCheckout  = $firstItem?->checkout_date?->toDateTimeString();
        $newCheckin   = $firstItem?->checkin_date?->toDateTimeString();

        $checkoutChanged = $newCheckout && $newCheckout !== $this->oldCheckoutDate;
        $checkinChanged  = $newCheckin  && $newCheckin  !== $this->oldCheckinDate;

        // Reset flag thông báo nếu ngày thay đổi
        if ($checkoutChanged || $checkinChanged) {
            $record->items()->where('extra_fee', 0)->update([
                'expiry_notified'   => false,
                'checkout_notified' => false,
            ]);
        }

        // Chỉ gọi TTLock API khi ngày thực sự thay đổi và đơn có mã cổng
        if ((!$checkoutChanged && !$checkinChanged) || $record->accessCodes->isEmpty()) {
            return;
        }

        $checkinDate  = $firstItem?->checkin_date;
        $checkoutDate = $firstItem?->checkout_date;

        if (!$checkinDate || !$checkoutDate) {
            return;
        }

        try {
            $service = app(AccessCodeService::class);
            $updated = $service->updateCodeDatesForOrder(
                $record->id,
                $checkinDate,
                $checkoutDate,
                $firstItem?->product,
            );

            if ($updated) {
                Notification::make()
                    ->title('Đã cập nhật thời gian mã cổng trên TTLock')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('afterSave: updateCodeDates failed', [
                'order_id' => $record->id,
                'error'    => $e->getMessage(),
            ]);
        }

        // Refresh $this->record để header action visible() callbacks thấy data mới
        // (ví dụ: extra_charge_amount vừa được set bởi handlePriceDiff)
        $this->record = $this->record->fresh();
    }

    /**
     * Tính chênh lệch giá sau khi admin cập nhật guest_count/services và xử lý theo trạng thái đơn.
     *
     * - deposit: cộng diff vào full_amount → remaining tự tăng
     * - paid:    tạo PayOS link mới cho khoản phát sinh, admin xem link trong notification
     */
    private function handlePriceDiff(\Modules\Payment\Entities\Order $order): void
    {
        try {
            $extraChargeService = app(ExtraChargeService::class);
            $diff = $extraChargeService->calculateDiff(
                $order,
                $this->oldGuestCount ?? (int) $order->guest_count,
                $this->oldServicesTotal,
            );

            if ($diff === 0) {
                return;
            }

            $notifService = app(\App\Services\NotificationFcmService::class);
            $extra        = ['order_code' => (string) $order->order_code, 'type' => 'order_extra_charge'];

            // ── Đơn deposit: cộng vào remaining ──────────────────────────────
            if ($order->status === 'deposit') {
                $extraChargeService->applyDiffToDeposit($order, $diff);

                // full_amount KHÔNG đổi — remaining = (realTotal - depositPaid) + extraCharge
                $order->refresh();
                $depositPct   = (int) $order->deposit_percent;
                $depositPaid  = (int) $order->full_amount;
                $newRealTotal = $depositPct > 0 ? (int) round($depositPaid * 100 / $depositPct) : $depositPaid;
                $extraCharge  = (int) ($order->extra_charge_amount ?? 0);
                $newRemaining = ($newRealTotal - $depositPaid) + $extraCharge;

                $label = $diff > 0
                    ? 'Phần còn lại tăng thêm ' . number_format($diff, 0, ',', '.') . 'đ'
                    : 'Phần còn lại giảm ' . number_format(abs($diff), 0, ',', '.') . 'đ';

                // Notify admin (Filament)
                Notification::make()
                    ->title($label)
                    ->body('Số tiền còn lại: ' . number_format($newRemaining, 0, ',', '.') . 'đ. Khách thanh toán khi check-out.')
                    ->info()
                    ->send();

                // WS realtime → app khách cập nhật deposit block ngay
                try {
                    app(OrderRealtimeService::class)->broadcastOrderUpdate(
                        (string) $order->order_code,
                        [
                            'deposit' => [
                                'remaining_amount' => $newRemaining,
                                'qr_code'          => null,
                                'checkout_url'     => null,
                            ],
                            'summary' => [
                                'grand_total' => $newRealTotal,
                            ],
                        ],
                        $order->customer_id ? (int) $order->customer_id : null,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('handlePriceDiff: deposit broadcastOrderUpdate failed', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }

                // FCM notify khách
                $clientTitle = "Đơn #{$order->order_code}: số tiền còn lại đã thay đổi";
                $clientBody  = $diff > 0
                    ? 'Dịch vụ/số người bổ sung làm tăng ' . number_format($diff, 0, ',', '.') . 'đ. Số tiền còn lại: ' . number_format($newRemaining, 0, ',', '.') . 'đ.'
                    : 'Điều chỉnh giảm ' . number_format(abs($diff), 0, ',', '.') . 'đ. Số tiền còn lại: ' . number_format($newRemaining, 0, ',', '.') . 'đ.';

                $this->pushClientNotification($order, $notifService, $clientTitle, $clientBody, 'order_deposit_updated', $extra);

                return;
            }

            // ── Đơn đã paid: tạo PayOS mới cho khoản phát sinh ───────────────
            if ($diff > 0) {
                $result = $extraChargeService->createExtraChargePayOS($order, $diff);

                // Notify admin (Filament)
                Notification::make()
                    ->title('Phát sinh thêm ' . number_format($diff, 0, ',', '.') . 'đ')
                    ->body('Link thanh toán: ' . $result['checkout_url'])
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('view_link')
                            ->label('Mở link PayOS')
                            ->url($result['checkout_url'])
                            ->openUrlInNewTab(),
                    ])
                    ->warning()
                    ->persistent()
                    ->send();

                // FCM + WS → khách
                $clientTitle = "Đơn #{$order->order_code}: phát sinh thêm " . number_format($diff, 0, ',', '.') . 'đ';
                $clientBody  = 'Bổ sung dịch vụ/số người. Vui lòng thanh toán khoản phát sinh.';
                $this->pushClientNotification($order, $notifService, $clientTitle, $clientBody, 'order_extra_charge',
                    array_merge($extra, ['amount' => $diff])
                );

                try {
                    app(OrderRealtimeService::class)->broadcastOrderUpdate(
                        (string) $order->order_code,
                        ['extra_charge' => ['amount' => $diff, 'qr_code' => $result['qr_code'], 'is_paid' => false]],
                        $order->customer_id ? (int) $order->customer_id : null,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('handlePriceDiff: paid extra charge broadcastOrderUpdate failed', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Giảm giá — thông báo admin + khách
                Notification::make()
                    ->title('Tổng tiền giảm ' . number_format(abs($diff), 0, ',', '.') . 'đ')
                    ->body('Vui lòng thông báo cho khách về khoản giảm này.')
                    ->info()
                    ->send();

                $clientTitle = "Đơn #{$order->order_code}: tổng tiền đã giảm";
                $clientBody  = 'Dịch vụ/số người đã được điều chỉnh, giảm ' . number_format(abs($diff), 0, ',', '.') . 'đ.';
                $this->pushClientNotification($order, $notifService, $clientTitle, $clientBody, 'order_extra_charge', $extra);

                try {
                    $order->refresh();
                    app(OrderRealtimeService::class)->broadcastOrderUpdate(
                        (string) $order->order_code,
                        ['summary' => ['grand_total' => (int) $order->full_amount + (int) ($order->extra_charge_amount ?? 0)]],
                        $order->customer_id ? (int) $order->customer_id : null,
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('handlePriceDiff: paid price reduction broadcastOrderUpdate failed', [
                        'order_id' => $order->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('handlePriceDiff failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Không thể tạo link thanh toán phát sinh')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Gửi FCM + WS notification đến khách khi admin cập nhật guest_count / services.
     */
    private function sendOrderUpdateNotification(\Modules\Payment\Entities\Order $order, array $changes): void
    {
        $parts = [];
        if (isset($changes['guest_count'])) {
            $parts[] = 'Số người: ' . $changes['guest_count']['to'];
        }
        if (isset($changes['services'])) {
            $names   = array_column($changes['services'], 'service_name');
            $parts[] = 'Dịch vụ: ' . implode(', ', $names);
        }

        if (empty($parts)) {
            return;
        }

        $title = "Đơn #{$order->order_code} đã được cập nhật";
        $body  = implode(' · ', $parts);
        $extra = ['order_code' => (string) $order->order_code, 'type' => 'order_updated'];

        $this->pushClientNotification(
            $order,
            app(\App\Services\NotificationFcmService::class),
            $title,
            $body,
            'order_updated',
            $extra,
        );
    }

    /**
     * Push FCM + WS notification đến khách (customer hoặc guest).
     * Tương tự sendStatusChangeNotification nhưng dùng được cho mọi loại notification.
     */
    private function pushClientNotification(
        \Modules\Payment\Entities\Order $order,
        \App\Services\NotificationFcmService $notifService,
        string $title,
        string $body,
        string $type,
        array $extra = [],
    ): void {
        try {
            if (is_null($order->customer_id) && $order->device_token) {
                $notifService->sendToGuestToken($order->device_token, $title, $body, $type, $extra);
                return;
            }

            if ($order->customer_id) {
                $customer = $order->customer;
                if ($customer) {
                    $notifService->sendToCustomer($customer, $title, $body, $type, $extra);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pushClientNotification failed', [
                'order_id' => $order->id,
                'type'     => $type,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}