<?php

namespace Modules\Minihouse\App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;

// Dùng chung giữa quét CCCD lúc điền form (TenantForm — live, trước khi lưu) và quét lúc lưu
// (TenantObserver — sau khi lưu) — tránh viết lặp 2 nơi cùng logic map kết quả CccdScannerService
// (Modules\Payment, dùng lại nguyên bản của Home) sang đúng tên field của Tenant.
class CccdScanMapper
{
    // $front/$back: state của FileUpload — có thể là 1 trong 2 dạng tuỳ thời điểm gọi:
    //  - string: đường dẫn tương đối trên disk 'public' (khách đã lưu — Sửa khách thuê, hoặc quét
    //    lại sau khi lưu ở TenantObserver).
    //  - TemporaryUploadedFile (bọc trong mảng key => file, đúng cấu trúc raw state của FileUpload
    //    trước khi dehydrate): khách CHƯA lưu (đang ở form Tạo) — Filament chỉ thật sự di chuyển
    //    ảnh vào thư mục lưu trữ lúc form dehydrate (validate + lưu), nên lúc đang điền form Tạo,
    //    getRawState() trả về đúng file tạm này (nằm ở storage/app/livewire-tmp) — đọc thẳng từ đó
    //    bằng getRealPath(), không cần đợi lưu xong mới quét được.
    //
    // Quét RIÊNG mặt trước trước (1 ảnh, nhanh hơn hẳn) — chỉ quét thêm mặt sau khi mặt trước
    // không ra kết quả. CCCD gắn chip (đời mới, 2021+) có QR ở mặt trước; CCCD/CMND mã vạch (đời
    // cũ hơn) có QR ở mặt sau — vẫn hỗ trợ được cả 2 đời thẻ, chỉ khác là không gộp cả 2 ảnh vào
    // MỌI bước thử như scanPaths()/scanBothSides() của Home (mỗi bước jsQR/zbarimg thử tất cả ảnh
    // cùng lúc) — cách đó tốn gấp đôi thời gian xử lý một cách không cần thiết cho phần lớn trường
    // hợp thực tế (khách dùng CCCD gắn chip, chỉ cần quét đúng 1 ảnh mặt trước là đủ), và là 1 phần
    // nguyên nhân khiến quét chậm/timeout ("This page has expired") khi phải rơi xuống OCR.space
    // (gọi API ngoài qua mạng) cho cả 2 ảnh.
    public static function scan(mixed $front, mixed $back): ?array
    {
        $front = self::resolveScanPath($front);
        $back  = self::resolveScanPath($back);

        if ($front) {
            $result = app(CccdScannerService::class)->scanImage($front);
            if ($result) {
                return $result;
            }
        }

        if ($back) {
            return app(CccdScannerService::class)->scanImage($back);
        }

        return null;
    }

    // Unwrap state của FileUpload về 1 đường dẫn thật đọc được trên đĩa:
    //  - raw state (getRawState(), Get::get() lúc còn trong live callback) là mảng
    //    ['uuid' => TemporaryUploadedFile] kể cả khi field không multiple() — lấy phần tử đầu.
    //  - state đã dehydrate (giá trị cột trong DB, $record->id_card_front) là string tương đối
    //    trên disk 'public'.
    private static function resolveScanPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = array_values($value)[0] ?? null;
        }

        if ($value instanceof \Illuminate\Http\UploadedFile) {
            return $value->getRealPath() ?: null;
        }

        if (is_string($value) && $value !== '') {
            return Storage::disk('public')->path($value);
        }

        return null;
    }

    /** @return array<string, mixed> field => giá trị đã map, chỉ gồm field quét ĐỌC ĐƯỢC (không rỗng). */
    public static function mapToTenantFields(array $scan): array
    {
        $fields = [
            'fullname'          => $scan['full_name'] ?? null,
            'id_card_number'    => $scan['cccd'] ?? null,
            'gender'            => ! empty($scan['gender']) ? self::mapGender($scan['gender']) : null,
            'permanent_address' => $scan['address'] ?? null,
        ];

        if (! empty($scan['dob'])) {
            $dob = Carbon::createFromFormat('d/m/Y', $scan['dob'])?->startOfDay();
            if ($dob) {
                $fields['date_of_birth'] = $dob;
            }
        }

        return array_filter($fields, fn ($value) => filled($value));
    }

    public static function mapGender(string $raw): ?string
    {
        if (str_contains($raw, 'Nữ')) {
            return 'nu';
        }
        if (str_contains($raw, 'Nam')) {
            return 'nam';
        }

        return null;
    }
}
