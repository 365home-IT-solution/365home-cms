<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;
use Modules\AuditLog\Services\AuditLogger;
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
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (CustomerResource::$scannedCccdData) {
            $data['cccd_data'] = CustomerResource::$scannedCccdData;

            return $data;
        }

        // Ảnh QR đã lưu từ trước (không tải ảnh mới) mà hồ sơ chưa có dữ liệu CCCD hợp lệ → quét
        // ảnh đang lưu; vẫn không đọc được thì chặn lưu: có ảnh QR thì phải có dữ liệu CCCD.
        $qrPath = $data['cccd_qr_image'] ?? null;
        if (is_string($qrPath) && $qrPath !== '' && CccdIdentity::validate($this->record->cccd_data, requireQr: false) !== null) {
            $disk    = Storage::disk('public');
            $scanned = $disk->exists($qrPath) ? app(CccdIntakeService::class)->scanQr($disk->path($qrPath)) : null;

            if (CccdIdentity::validate($scanned) !== null) {
                $message = 'Ảnh CCCD mặt có mã QR đang lưu không đọc được mã QR nên chưa có dữ liệu CCCD. Vui lòng tải lên ảnh chụp rõ hơn (hoặc xoá ảnh này) rồi lưu lại.';
                $this->addError('data.cccd_qr_image', $message);
                Notification::make()->title('Không quét được QR CCCD')->body($message)->danger()->persistent()->send();
                $this->halt();
            }

            $data['cccd_data'] = $scanned;
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->oldCouponIds = $this->record->coupons()->pluck('coupon_customers.coupon_id')->map(fn ($id) => (string) $id)->all();
    }

    protected function afterSave(): void
    {
        // Lưu xong ở lại form sửa (không chuyển về danh sách) — nạp lại dữ liệu CCCD vừa quét từ QR.
        $this->refreshFormData(['cccd_data']);

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
            DeleteAction::make()->label('Xoá'),
            RestoreAction::make()->label('Khôi phục'),
            ForceDeleteAction::make()->label('Xoá vĩnh viễn'),
        ];
    }
}
