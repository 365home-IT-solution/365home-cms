<?php

namespace Modules\Payment\App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\Entities\Order;

/**
 * Quét thông tin CCCD từ ảnh đã lưu trong đơn hàng.
 *
 * Thứ tự ưu tiên:
 *   1. QR chip CCCD từ ảnh mặt sau (dùng khanamiryan/qrcode-detector-decoder)
 *   2. QR chip CCCD từ ảnh mặt trước
 *   3. OCR toàn văn ảnh mặt trước (dùng OCR.space API)
 */
class CccdScannerService
{
    /**
     * Quét và trả về mảng cccd_data cho đơn hàng, hoặc null nếu không đọc được.
     */
    public function scanOrder(Order $order): ?array
    {
        // Ưu tiên mặt sau (chip QR thường nằm ở đây)
        if ($order->cccd_back) {
            $path = Storage::disk('public')->path($order->cccd_back);
            if (file_exists($path)) {
                $data = $this->tryQrScan($path);
                if ($data) {
                    return $data;
                }
            }
        }

        // Thử mặt trước
        if ($order->cccd_front) {
            $path = Storage::disk('public')->path($order->cccd_front);
            if (file_exists($path)) {
                $data = $this->tryQrScan($path);
                if ($data) {
                    return $data;
                }
            }
        }

        // Fallback OCR mặt trước
        if ($order->cccd_front) {
            $path = Storage::disk('public')->path($order->cccd_front);
            if (file_exists($path)) {
                return $this->tryOcrFrontside($path);
            }
        }

        return null;
    }

    /**
     * Quét QR từ file ảnh cục bộ.
     * Dùng khanamiryan/qrcode-detector-decoder (PHP port của ZXing).
     */
    protected function tryQrScan(string $imagePath): ?array
    {
        if (! class_exists(\QrReader::class)) {
            Log::debug('[CccdScanner] QrReader class không tồn tại — gói khanamiryan/qrcode-detector-decoder chưa được cài.');
            return null;
        }

        try {
            $qr   = new \QrReader($imagePath);
            $text = $qr->text();

            if ($text && $this->isCccdQr($text)) {
                Log::debug('[CccdScanner] QR thành công', ['path' => basename($imagePath)]);
                return $this->parseQrData($text);
            }
        } catch (\Throwable $e) {
            Log::debug('[CccdScanner] QR thất bại', [
                'path'  => basename($imagePath),
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * OCR mặt trước CCCD qua OCR.space API.
     */
    protected function tryOcrFrontside(string $imagePath): ?array
    {
        try {
            /** @var \Modules\BladeThemeV1\Services\OcrSpaceService $ocr */
            $ocr = app(\Modules\BladeThemeV1\Services\OcrSpaceService::class);

            if (! $ocr->isConfigured()) {
                Log::debug('[CccdScanner] OCR.space chưa cấu hình API key.');
                return null;
            }

            $text = $ocr->extractTextFromImage($imagePath);
            if (empty($text)) {
                return null;
            }

            $data = $this->parseOcrText($text);
            if ($data) {
                Log::debug('[CccdScanner] OCR thành công', ['path' => basename($imagePath)]);
            }

            return $data;
        } catch (\Throwable $e) {
            Log::debug('[CccdScanner] OCR thất bại', [
                'path'  => basename($imagePath),
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Kiểm tra chuỗi QR có đúng định dạng chip CCCD Việt Nam không (≥ 7 trường phân cách |).
     */
    protected function isCccdQr(string $text): bool
    {
        return substr_count($text, '|') >= 6;
    }

    /**
     * Bóc tách dữ liệu từ QR chip CCCD.
     * Định dạng: CCCD|CMND_CU|HO_TEN|NGAY_SINH|GIOI_TINH|DIA_CHI|NGAY_CAP[|...]
     */
    protected function parseQrData(string $text): array
    {
        $p = explode('|', $text);

        return [
            'cccd'        => trim($p[0] ?? ''),
            'old_id'      => trim($p[1] ?? ''),
            'full_name'   => trim($p[2] ?? ''),
            'dob'         => $this->formatDate(trim($p[3] ?? '')),
            'gender'      => trim($p[4] ?? ''),
            'address'     => trim($p[5] ?? ''),
            'issued_date' => $this->formatDate(trim($p[6] ?? '')),
            'source'      => 'qr',
        ];
    }

    /**
     * Trích xuất thông tin từ văn bản OCR mặt trước CCCD.
     */
    protected function parseOcrText(string $text): ?array
    {
        $cccd     = '';
        $fullName = '';
        $dob      = '';
        $gender   = '';
        $address  = '';

        // Số CCCD: 12 chữ số (hoặc CMND 9 số)
        if (preg_match('/\b(\d{12})\b/', $text, $m)) {
            $cccd = $m[1];
        } elseif (preg_match('/\b(\d{9})\b/', $text, $m)) {
            $cccd = $m[1];
        }

        $lines = preg_split('/\r?\n/', $text);

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Họ và tên
            if (! $fullName && preg_match('/(?:Họ và tên|Full name)[:\s\/\\\\]+(.+)/ui', $line, $m)) {
                $val      = trim($m[1]);
                $fullName = (strlen($val) > 2 && ! preg_match('/^(?:Full name|\/|\\\\)$/i', $val))
                    ? $val
                    : trim($lines[$i + 1] ?? '');
            }

            // Ngày sinh
            if (! $dob && preg_match('/(?:Ngày sinh|Date of birth)[:\s\/\\\\]+(.+)/ui', $line, $m)) {
                $raw = trim($m[1]);
                if (preg_match('/\d{2}[\/\-]\d{2}[\/\-]\d{4}/', $raw, $dm)) {
                    $dob = $dm[0];
                } elseif (preg_match('/\d{8}/', $raw, $dm)) {
                    $dob = $this->formatDate($dm[0]);
                }
            }

            // Giới tính
            if (! $gender && preg_match('/(?:Giới tính|Sex)[:\s\/\\\\]+(.+)/ui', $line, $m)) {
                if (preg_match('/Nam|Nữ|Male|Female/iu', $m[1], $gm)) {
                    $gender = $gm[0];
                }
            }

            // Nơi thường trú
            if (! $address && preg_match('/(?:Nơi thường trú|Place of residence)[:\s\/\\\\]+(.+)/ui', $line, $m)) {
                $val       = trim($m[1]);
                $addrLines = [];
                if (strlen($val) > 2 && ! preg_match('/^(?:Place of residence|\/|\\\\)$/i', $val)) {
                    $addrLines[] = $val;
                }
                for ($j = 1; $j <= 3; $j++) {
                    $next = trim($lines[$i + $j] ?? '');
                    if ($next === '' || preg_match('/(?:Có giá trị|Expiry|Họ và tên)/ui', $next)) {
                        break;
                    }
                    $clean = preg_replace('/(?:Có giá trị đến|Date of ex?piry|Expiry).*/ui', '', $next);
                    $clean = trim($clean, " ,/\\");
                    if ($clean !== '') {
                        $addrLines[] = $clean;
                    }
                }
                $address = implode(', ', array_filter($addrLines));
            }
        }

        // Không đủ thông tin — bỏ qua
        if ($cccd === '' && $fullName === '') {
            return null;
        }

        return [
            'cccd'        => $cccd,
            'old_id'      => '',
            'full_name'   => $fullName,
            'dob'         => $dob,
            'gender'      => $gender,
            'address'     => $address,
            'issued_date' => '',
            'source'      => 'ocr',
        ];
    }

    /**
     * Chuyển chuỗi số ddmmyyyy (8 chữ số) → dd/mm/yyyy.
     */
    protected function formatDate(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) === 8 && ctype_digit($raw)) {
            return substr($raw, 0, 2) . '/' . substr($raw, 2, 2) . '/' . substr($raw, 4, 4);
        }

        return $raw;
    }
}
