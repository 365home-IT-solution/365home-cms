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

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

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
                ->label('Gán mã cổng')
                ->icon('heroicon-m-key')
                ->color('success')
                ->visible(fn () => (auth()->user()?->can('update_order') ?? false) && $this->record->status === 'paid' && !$this->record->hasAccessCode())
                ->requiresConfirmation()
                ->modalHeading('Gán mã cổng cho đơn hàng')
                ->modalDescription('Hệ thống sẽ tự động tìm mã cổng khả dụng theo chi nhánh và gán cho đơn này.')
                ->modalSubmitActionLabel('Xác nhận gán')
                ->action(function () {
                    $record = $this->record->fresh();
                    $record->load('items');

                    try {
                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                        $checkinDate  = $record->items->min('checkin_date');
                        $checkoutDate = $record->items->max('checkout_date');
                        $product      = $firstItem?->product;

                        $service = app(AccessCodeService::class);
                        $service->assignCodeToOrder(
                            $record->id,
                            $record->category_id,
                            $checkinDate,
                            $checkoutDate,
                            $product,
                        );

                        Notification::make()
                            ->title('Đã gán mã cổng thành công')
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể gán mã cổng')
                            ->body($e->getMessage())
                            ->warning()
                            ->send();
                    }
                }),

            Actions\Action::make('reissueAccessCode')
                ->label('Cấp lại mã cổng')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn () => (auth()->user()?->can('update_order') ?? false) && in_array($this->record->status, ['paid', 'deposit']))
                ->requiresConfirmation()
                ->modalHeading('Cấp lại mã cổng')
                ->modalDescription('Hệ thống sẽ thu hồi mã cổng hiện tại (nếu có) và cấp mã mới cho đơn này.')
                ->modalSubmitActionLabel('Xác nhận cấp lại')
                ->action(function () {
                    $record = $this->record->fresh(['items.product']);

                    try {
                        $service = app(AccessCodeService::class);

                        // Thu hồi mã cũ (nếu có)
                        $service->releaseCode($record->id);

                        // Cấp mã mới
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

                        Notification::make()
                            ->title('Đã cấp lại mã cổng: ' . $code->code)
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('edit', ['record' => $record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Không thể cấp lại mã cổng')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
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
            Actions\DeleteAction::make(),
        ];
    }

    /** Ngày checkout cũ trước khi save (để so sánh) */
    protected ?string $oldCheckoutDate = null;
    protected ?string $oldCheckinDate  = null;

    /**
     * Ghi nhớ ngày cũ trước khi lưu để so sánh sau.
     */
    protected function beforeSave(): void
    {
        $firstItem = $this->record->items()->where('extra_fee', 0)->first();
        $this->oldCheckoutDate = $firstItem?->checkout_date?->toDateTimeString();
        $this->oldCheckinDate  = $firstItem?->checkin_date?->toDateTimeString();
    }

    /**
     * Sau khi lưu đơn, kiểm tra nếu checkin/checkout thay đổi thì cập nhật TTLock.
     */
    protected function afterSave(): void
    {
        $record = $this->record->fresh(['items.product', 'accessCodes']);

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
    }
}