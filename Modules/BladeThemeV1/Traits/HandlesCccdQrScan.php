<?php

namespace Modules\BladeThemeV1\Traits;

use App\Models\Customer;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Luồng CCCD 1 ảnh cho trang đặt phòng: khách chỉ tải 1 ảnh mặt có mã QR, quét NGAY khi upload
 * xong để báo hợp lệ/lỗi — KHÔNG hiển thị lại thông tin đọc được. Dữ liệu được tạm giữ phía
 * server và chỉ ghi vào đơn (orders.cccd_data / order_guest_cccds.cccd_data → cccd_declarations)
 * lúc tạo đơn.
 *
 * Bảo mật:
 *  - Dữ liệu CCCD tạm giữ trong session phía server, MÃ HOÁ (Crypt) và hết hạn sau
 *    CCCD_SCAN_TTL_SECONDS, gắn với đúng tên file tạm + sha1 của ảnh đã quét — client không thể
 *    gửi cccd_data tự chế hay đổi ảnh sau khi quét.
 *  - Snapshot Livewire (nằm trong HTML) chỉ chứa trạng thái ok/lỗi, không có thông tin cá nhân.
 *  - Rate limit theo IP + session (CccdIntakeService) vì mỗi lượt quét chạy Node/zbar tốn CPU.
 *  - Chỉ nhận dữ liệu từ QR (không OCR), đối chiếu cấu trúc số CCCD với ngày sinh/giới tính.
 *
 * Slot: 'main' = khách đặt phòng (cccd_qr_image), 'extra.{i}' = người đi cùng (cccdQrImageExtra[i]).
 */
trait HandlesCccdQrScan
{
    private const CCCD_SCAN_SESSION_KEY = 'product_detail.cccd_scans';
    private const CCCD_SCAN_TTL_SECONDS = 1800;

    // Xoá kết quả quét cũ NGAY khi đổi ảnh — updated() (validateOnly) chạy trước hook riêng và
    // có thể ném lỗi, khi đó updatedCccdQrImage() không chạy và kết quả cũ không được còn lại.
    public function updatingCccdQrImage(): void
    {
        $this->forgetCccdScan('main');
    }

    public function updatingCccdQrImageExtra($value, $key): void
    {
        $this->forgetCccdScan('extra.' . (int) $key);
    }

    public function updatedCccdQrImage(): void
    {
        $this->scanCccdSlot('main', $this->cccd_qr_image, 'cccd_qr_image');
    }

    public function updatedCccdQrImageExtra($value, $key): void
    {
        $index = (int) $key;
        $this->scanCccdSlot("extra.{$index}", $this->cccdQrImageExtra[$index] ?? null, "cccdQrImageExtra.{$index}");
    }

    // Bỏ ảnh vừa tải, quay lại dùng CCCD trong hồ sơ (chỉ có tác dụng khi hồ sơ có CCCD hợp lệ).
    public function useProfileCccd(): void
    {
        $this->cccd_qr_image = '';
        $this->forgetCccdScan('main');
        $this->resetErrorBag('cccd_qr_image');
    }

    protected function scanCccdSlot(string $slot, mixed $file, string $field): void
    {
        $this->forgetCccdScan($slot);

        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        // Kiểm tra ảnh / rate limit / quét QR dùng chung CccdIntakeService với các API.
        $intake = app(CccdIntakeService::class);
        $error  = $intake->imageError($file) ?? $intake->hitScanLimit('s:' . session()->getId());
        $data   = null;

        if (! $error) {
            $data  = $intake->scanQr($file);
            $error = CccdIdentity::validate($data);
        }

        if (! $error) {
            $error = $this->cccdAgeError($data, $slot);
        }

        if (! $error) {
            $others = $this->collectedCccdPeople();
            unset($others[$slot]);
            $error = $this->cccdDuplicateError($data, $slot, $others);
        }

        if ($error) {
            $this->cccdScanStatus[$slot] = ['ok' => false, 'error' => $error];
            $this->addError($field, $error);
            Log::info('[ProductDetail] CCCD scan rejected', ['slot' => $slot, 'reason' => $error]);
            return;
        }

        $scans = session(self::CCCD_SCAN_SESSION_KEY, []);
        $scans[$slot] = Crypt::encryptString(json_encode([
            'file' => $file->getFilename(),
            'sha1' => sha1_file($file->getRealPath()),
            'at'   => time(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE));
        session([self::CCCD_SCAN_SESSION_KEY => $scans]);

        $this->cccdScanStatus[$slot] = ['ok' => true, 'error' => null];
        $this->resetErrorBag($field);
    }

    /**
     * Dữ liệu CCCD đã quét cho slot — chỉ trả về khi file hiện tại đúng là file đã quét (cùng tên
     * file tạm, cùng nội dung). Ngược lại null → buộc khách tải/quét lại.
     */
    protected function verifiedCccdScan(string $slot, mixed $file): ?array
    {
        if (! $file instanceof TemporaryUploadedFile) {
            return null;
        }

        $scan = $this->decryptCccdScan(session(self::CCCD_SCAN_SESSION_KEY, [])[$slot] ?? null);
        $path = $file->getRealPath();

        if (! $scan || ! $path || ! is_file($path)
            || time() - (int) ($scan['at'] ?? 0) > self::CCCD_SCAN_TTL_SECONDS
            || ! hash_equals((string) $scan['file'], $file->getFilename())
            || ! hash_equals((string) $scan['sha1'], (string) sha1_file($path))) {
            return null;
        }

        return $scan['data'];
    }

    /**
     * cccd_data trong hồ sơ khách đã đăng nhập — đọc lại từ DB (authUserId là #[Locked]), chỉ
     * dùng khi dữ liệu có cấu trúc hợp lệ.
     */
    protected function authProfileCccdData(): ?array
    {
        if (! $this->isAuthUser || ! $this->authUserId) {
            return null;
        }

        $data = Customer::find($this->authUserId)?->cccd_data;

        return is_array($data) && CccdIdentity::validate($data, requireQr: false) === null ? $data : null;
    }

    /**
     * Trước khi mở modal xác nhận: mọi CCCD bắt buộc đều phải quét QR thành công.
     */
    protected function ensureCccdScansReady(): bool
    {
        $missing = [];

        // Kiểm tra lại độ tuổi ở đây (không chỉ lúc quét): khách có thể quét trước rồi mới đổi
        // sang khung qua đêm / đổi ngày nhận phòng.
        if ($this->cccd_qr_image instanceof TemporaryUploadedFile) {
            $data = $this->verifiedCccdScan('main', $this->cccd_qr_image);
            if (! $data) {
                $missing['cccd_qr_image'] = 'CCCD người đặt phòng chưa quét được mã QR. Vui lòng tải lại ảnh mặt có mã QR.';
            } elseif ($ageError = $this->cccdAgeError($data, 'main')) {
                $missing['cccd_qr_image'] = $ageError;
            }
        } elseif (! $this->authHasCccd) {
            $missing['cccd_qr_image'] = 'Vui lòng tải ảnh CCCD (mặt có mã QR).';
        } elseif ($ageError = $this->cccdAgeError($this->authProfileCccdData(), 'main')) {
            $missing['cccd_qr_image'] = $ageError;
        }

        if ($this->hasOvernightSlotSelected()) {
            // Mỗi người đi cùng so trùng với người đặt + những người đi cùng đứng TRƯỚC (báo lỗi ở
            // người nhập sau, không báo cả 2 phía).
            $people = $this->collectedCccdPeople();
            $before = array_intersect_key($people, ['main' => true]);

            for ($i = 0; $i < max(0, (int) $this->guests - 1); $i++) {
                $slot = "extra.{$i}";
                $data = $people[$slot] ?? null;
                if (! $data) {
                    $missing["cccdQrImageExtra.{$i}"] = 'CCCD người đi cùng #' . ($i + 2) . ' chưa quét được mã QR.';
                    continue;
                }

                $error = $this->cccdAgeError($data, $slot) ?? $this->cccdDuplicateError($data, $slot, $before);
                if ($error) {
                    $missing["cccdQrImageExtra.{$i}"] = $error;
                }
                $before[$slot] = $data;
            }
        }

        foreach ($missing as $field => $message) {
            $this->addError($field, $message);
        }

        if ($missing) {
            $this->dispatch('notify', ['message' => implode(' | ', $missing), 'type' => 'error']);
        }

        return ! $missing;
    }

    /**
     * Điều kiện tuổi cho 1 slot: dưới 16 tuổi → lỗi, cho cả người đặt (mọi loại khung) lẫn người
     * đi cùng (chỉ thu khi có qua đêm). Tính tuổi tại ngày nhận phòng (chưa chọn → hôm nay).
     */
    protected function cccdAgeError(?array $data, string $slot): ?string
    {
        return CccdIdentity::ageError(
            $data,
            isBooker: $slot === 'main',
            on: $this->cccdCheckinDate(),
            who: $this->cccdSlotLabel($slot),
        );
    }

    /**
     * Lỗi nếu $data trùng thông tin (số CCCD, hoặc họ tên + ngày sinh) với 1 người trong $others
     * (slot => cccd_data).
     */
    protected function cccdDuplicateError(array $data, string $slot, array $others): ?string
    {
        foreach ($others as $otherSlot => $other) {
            if ($otherSlot !== $slot && is_array($other) && CccdIdentity::samePerson($data, $other)) {
                return $this->cccdSlotLabel($slot) . ': thông tin CCCD trùng với ' . mb_strtolower($this->cccdSlotLabel($otherSlot))
                    . '. Mỗi người cần CCCD riêng của chính mình.';
            }
        }

        return null;
    }

    /**
     * cccd_data của mọi khách ĐANG gắn trên form (slot => data): người đặt (ảnh vừa quét, hoặc hồ
     * sơ nếu không tải ảnh mới) + người đi cùng trong phạm vi số khách hiện tại. Chỉ lấy ảnh đang
     * gắn trên form này — session có thể còn slot cũ của tab/trang khác.
     */
    protected function collectedCccdPeople(): array
    {
        $people = [
            'main' => $this->cccd_qr_image instanceof TemporaryUploadedFile
                ? $this->verifiedCccdScan('main', $this->cccd_qr_image)
                : $this->authProfileCccdData(),
        ];

        for ($i = 0; $i < max(0, (int) $this->guests - 1); $i++) {
            $people["extra.{$i}"] = $this->verifiedCccdScan("extra.{$i}", $this->cccdQrImageExtra[$i] ?? null);
        }

        return array_filter($people);
    }

    protected function cccdSlotLabel(string $slot): string
    {
        return $slot === 'main' ? 'Người đặt phòng' : 'Người đi cùng #' . ((int) substr($slot, 6) + 2);
    }

    protected function cccdCheckinDate(): ?Carbon
    {
        try {
            if ((int) $this->bookingStyle === 2) {
                return filled($this->startTime) ? Carbon::parse($this->startTime)->startOfDay() : null;
            }

            [$checkin] = $this->computeCheckinCheckoutFromSlots();

            return $checkin !== '' ? Carbon::parse($checkin)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function pruneCccdExtraScans(int $companionCount): void
    {
        $scans = session(self::CCCD_SCAN_SESSION_KEY, []);

        foreach (array_keys($this->cccdScanStatus + $scans) as $slot) {
            if (str_starts_with($slot, 'extra.') && (int) substr($slot, 6) >= $companionCount) {
                unset($this->cccdScanStatus[$slot], $scans[$slot]);
            }
        }

        session([self::CCCD_SCAN_SESSION_KEY => $scans]);
    }

    protected function clearCccdScans(): void
    {
        session()->forget(self::CCCD_SCAN_SESSION_KEY);
        $this->cccdScanStatus = [];
    }

    private function forgetCccdScan(string $slot): void
    {
        $scans = session(self::CCCD_SCAN_SESSION_KEY, []);
        unset($scans[$slot], $this->cccdScanStatus[$slot]);
        session([self::CCCD_SCAN_SESSION_KEY => $scans]);
    }

    private function decryptCccdScan(mixed $payload): ?array
    {
        if (! is_string($payload)) {
            return null;
        }

        try {
            $scan = json_decode(Crypt::decryptString($payload), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($scan) && is_array($scan['data'] ?? null) ? $scan : null;
    }
}
