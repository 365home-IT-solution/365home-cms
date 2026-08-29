<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Promotion\App\Models\Coupon;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected array $oldCouponIds = [];

    // Field 'coupons' là Select ->relationship()->multiple() — pivot (coupon_customers) được
    // Filament tự đồng bộ ở saveRelationships(), KHÔNG đi qua $record->update() nên không có
    // Eloquent event nào bắn ra để ghi log (cùng lỗi đã gặp ở Product tags/services). beforeSave()
    // chạy TRƯỚC bước đó nên chụp lại state cũ ở đây, afterSave() so sánh với state mới rồi ghi
    // log thủ công.
    protected function beforeSave(): void
    {
        $this->oldCouponIds = $this->record->coupons()->pluck('coupon_customers.coupon_id')->map(fn ($id) => (string) $id)->all();
    }

    protected function afterSave(): void
    {
        $record = $this->record->fresh(['coupons']);

        $newCouponIds = $record->coupons->pluck('id')->map(fn ($id) => (string) $id)->all();
        $added        = array_diff($newCouponIds, $this->oldCouponIds);
        $removed      = array_diff($this->oldCouponIds, $newCouponIds);

        if (empty($added) && empty($removed)) {
            return;
        }

        $old = [];
        $new = [];

        if (! empty($removed)) {
            $old['ma_giam_gia_da_bo'] = Coupon::whereIn('id', $removed)->pluck('code')->implode(', ');
        }
        if (! empty($added)) {
            $new['ma_giam_gia_da_them'] = Coupon::whereIn('id', $added)->pluck('code')->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'Customer',
            record: $record,
            old: $old,
            new: $new,
            label: ($record->fullname ?? $record->phone ?? '#' . $record->id) . ' — Cập nhật mã giảm giá',
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('scanCccdQr')
                ->label('[TEST] Quét QR CCCD')
                ->icon('heroicon-m-qr-code')
                ->color('gray')
                ->visible(fn () => (bool) ($this->record->cccd_front || $this->record->cccd_back))
                ->action(function (): void {
                    /** @var \App\Models\Customer $record */
                    $record = $this->record->fresh();
                    $data   = app(CccdScannerService::class)->scanCustomer($record);

                    if (! $data) {
                        Notification::make()
                            ->title('Không đọc được QR CCCD')
                            ->body('Ảnh quá nhỏ hoặc QR bị mờ. Vui lòng upload lại ảnh gốc chất lượng cao (không resize).')
                            ->warning()
                            ->send();
                        return;
                    }

                    $record->update(['cccd_data' => $data]);

                    $this->refreshFormData(['cccd_data']);

                    $note = implode("\n", array_filter([
                        $data['cccd']      ? "Số CCCD:   {$data['cccd']}"      : null,
                        $data['full_name'] ? "Họ và tên: {$data['full_name']}" : null,
                        $data['dob']       ? "Ngày sinh: {$data['dob']}"       : null,
                        $data['gender']    ? "Giới tính: {$data['gender']}"    : null,
                        $data['address']   ? "Địa chỉ:   {$data['address']}"   : null,
                    ]));

                    Notification::make()
                        ->title('Quét CCCD thành công')
                        ->body($note)
                        ->success()
                        ->send();
                }),

            DeleteAction::make()->label('Xoá'),
            RestoreAction::make()->label('Khôi phục'),
            ForceDeleteAction::make()->label('Xoá vĩnh viễn'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
