<?php

namespace Modules\VehicleRental\App\Services;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleDocument;

/**
 * Giấy tờ (KYC) của khách thuê xe. Khách gửi CCCD + GPLX + ảnh chân dung; nhân viên quầy duyệt. Ảnh lưu đĩa 'public' với tên
 * ngẫu nhiên (cùng quy ước ảnh CCCD của đơn phòng). Gửi lại bộ giấy tờ → quay về "chờ duyệt".
 */
class VehicleKycService
{
    public const DISK = 'public';

    public const DIRECTORY = 'rental-kyc';

    public function __construct(private VehicleRentalNotifier $notifier)
    {
    }

    /**
     * @param  array{full_name?:string, id_number?:string, license_number?:string, license_class?:string, license_expires_on?:string}  $data
     * @param  array<string, UploadedFile|null>  $files  khoá thuộc VehicleDocument::IMAGE_FIELDS
     */
    public function submit(string $customerId, array $data, array $files): VehicleDocument
    {
        $doc = VehicleDocument::query()->firstOrNew(['customer_id' => $customerId]);

        $attrs = [];
        foreach (VehicleDocument::IMAGE_FIELDS as $field) {
            if (($files[$field] ?? null) instanceof UploadedFile) {
                $this->deleteFile($doc->{$field});
                $attrs[$field] = $files[$field]->store(self::DIRECTORY, self::DISK);
            }
        }

        $doc->fill($attrs + array_intersect_key($data, array_flip(['full_name', 'id_number', 'license_number', 'license_class', 'license_expires_on'])));
        if (isset($data['license_class'])) {
            $doc->license_class = strtoupper(trim($data['license_class']));
        }
        $doc->status = VehicleDocument::STATUS_PENDING;
        $doc->reject_reason = null;
        $doc->reviewed_by = null;
        $doc->reviewed_at = null;
        $doc->save();

        $this->notifier->documentSubmitted($doc);

        return $doc;
    }

    /** Hồ sơ đã đủ ảnh/thông tin để nhân viên duyệt chưa. @return string[] danh sách còn thiếu */
    public function missing(VehicleDocument $doc): array
    {
        $labels = [
            'id_front' => 'ảnh CCCD mặt trước', 'id_back' => 'ảnh CCCD mặt sau', 'license_front' => 'ảnh GPLX mặt trước',
            'license_back' => 'ảnh GPLX mặt sau', 'selfie' => 'ảnh chân dung', 'license_class' => 'hạng GPLX',
            'license_expires_on' => 'ngày hết hạn GPLX', 'id_number' => 'số CCCD',
        ];

        return collect($labels)->filter(fn ($label, $field) => blank($doc->{$field}))->values()->all();
    }

    public function approve(VehicleDocument $doc, ?string $staffId): VehicleDocument
    {
        $missing = $this->missing($doc);
        if ($missing) {
            throw new DomainException('Hồ sơ còn thiếu: ' . implode(', ', $missing) . '.');
        }

        $doc->update([
            'status' => VehicleDocument::STATUS_APPROVED, 'reject_reason' => null,
            'reviewed_by' => $staffId, 'reviewed_at' => now(),
        ]);

        $this->notifier->documentReviewed($doc);

        return $doc;
    }

    public function reject(VehicleDocument $doc, string $reason, ?string $staffId): VehicleDocument
    {
        $doc->update([
            'status' => VehicleDocument::STATUS_REJECTED, 'reject_reason' => $reason,
            'reviewed_by' => $staffId, 'reviewed_at' => now(),
        ]);

        $this->notifier->documentReviewed($doc);

        return $doc;
    }

    /**
     * Khách đủ điều kiện nhận xe chưa: hồ sơ đã duyệt, hạng GPLX hợp loại xe, GPLX còn hạn tới hết thời gian thuê.
     *
     * @return string|null lý do không đủ điều kiện; null = đủ điều kiện
     */
    public function ineligibleReason(string $customerId, Vehicle $vehicle, CarbonInterface $until): ?string
    {
        $doc = VehicleDocument::query()->where('customer_id', $customerId)->first();

        if (! $doc) {
            return 'Khách chưa nộp giấy tờ (CCCD, GPLX).';
        }
        if ($doc->status !== VehicleDocument::STATUS_APPROVED) {
            return $doc->status === VehicleDocument::STATUS_REJECTED
                ? 'Giấy tờ của khách bị từ chối: ' . ($doc->reject_reason ?: 'cần nộp lại') . '.'
                : 'Giấy tờ của khách chưa được duyệt.';
        }

        $allowed = (array) config("vehiclerental.license_classes.{$vehicle->type}", []);
        if (! in_array(strtoupper((string) $doc->license_class), $allowed, true)) {
            return 'Hạng GPLX ' . ($doc->license_class ?: '(chưa có)') . ' không được phép thuê loại xe này (cần: ' . implode(', ', $allowed) . ').';
        }

        if ($doc->license_expires_on === null || $doc->license_expires_on->lt($until->copy()->startOfDay())) {
            return 'GPLX hết hạn trước khi kết thúc thời gian thuê.';
        }

        return null;
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
