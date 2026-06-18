<?php

namespace Modules\Payment\App\Services;

use App\Models\Customer;
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
    /** Giới hạn tổng thời gian scan để tránh 504 Gateway Timeout */
    private const MAX_SCAN_SECONDS = 25;

    private float $scanDeadline = 0;

    private function startTimer(): void
    {
        $this->scanDeadline = microtime(true) + self::MAX_SCAN_SECONDS;
    }

    private function isTimedOut(): bool
    {
        return $this->scanDeadline > 0 && microtime(true) >= $this->scanDeadline;
    }

    /**
     * Quét và trả về mảng cccd_data cho đơn hàng, hoặc null nếu không đọc được.
     */
    public function scanOrder(Order $order): ?array
    {
        ini_set('memory_limit', '256M');
        $this->startTimer();

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

        if ($this->isTimedOut()) {
            Log::warning('[CccdScanner] scanOrder timeout sau mặt sau', ['order_id' => $order->id ?? null]);
            return null;
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

        return null;
    }

    /**
     * Quét và trả về mảng cccd_data cho khách hàng, hoặc null nếu không đọc được.
     */
    public function scanCustomer(Customer $customer): ?array
    {
        ini_set('memory_limit', '256M');
        $this->startTimer();

        // Ưu tiên mặt sau
        if ($customer->cccd_back) {
            $path = Storage::disk('public')->path($customer->cccd_back);
            if (file_exists($path)) {
                $data = $this->tryQrScan($path);
                if ($data) {
                    return $data;
                }
            }
        }

        if ($this->isTimedOut()) {
            Log::warning('[CccdScanner] scanCustomer timeout sau mặt sau', ['customer_id' => $customer->id ?? null]);
            return null;
        }

        // Thử mặt trước
        if ($customer->cccd_front) {
            $path = Storage::disk('public')->path($customer->cccd_front);
            if (file_exists($path)) {
                $data = $this->tryQrScan($path);
                if ($data) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * Quét QR từ 2 path đã lưu trên disk 'public'.
     * Dùng cho Livewire booking flow sau khi file đã store() vĩnh viễn.
     */
    public function scanPaths(?string $frontPath, ?string $backPath): ?array
    {
        ini_set('memory_limit', '256M');
        $this->startTimer();

        if ($backPath) {
            $path = Storage::disk('public')->path($backPath);
            if (file_exists($path)) {
                $data = $this->tryQrScan($path);
                if ($data) return $data;
            }
        }

        if ($this->isTimedOut()) {
            Log::warning('[CccdScanner] scanPaths timeout sau mặt sau');
            return null;
        }

        if ($frontPath) {
            $path = Storage::disk('public')->path($frontPath);
            if (file_exists($path)) {
                $data = $this->tryQrScan($path);
                if ($data) return $data;
            }
        }

        return null;
    }

    /**
     * Quét QR từ file ảnh cục bộ.
     * Thứ tự ưu tiên: Node.js jsQR → zbarimg CLI → khanamiryan
     * Nếu thất bại, thử xoay ảnh 90°/270°/180° để xử lý ảnh dọc hoặc xéo.
     */
    protected function tryQrScan(string $imagePath): ?array
    {
        // Chiến lược 1: Node.js jsQR — xử lý tốt nhất với ảnh JPEG chất lượng thấp
        $data = $this->tryNodeJsQR($imagePath);
        if ($data) {
            return $data;
        }

        // Chiến lược 2: zbarimg CLI
        $data = $this->tryZbarimg($imagePath);
        if ($data) {
            return $data;
        }

        // Chiến lược 3: khanamiryan PHP ZXing
        $data = $this->tryKhanamiryan($imagePath);
        if ($data) {
            return $data;
        }

        // Chiến lược 4: thử xoay ảnh — xử lý ảnh dọc (portrait) hoặc xéo
        // 90° CCW sửa ảnh chụp xoay 90° CW, 270° CCW sửa 90° CCW, 180° sửa lật ngược
        foreach ([90, 270, 180] as $angle) {
            if ($this->isTimedOut()) {
                Log::debug('[CccdScanner] rotation aborted (global timeout)', ['angle' => $angle]);
                break;
            }

            $rotatedPath = $this->rotateImageForQr($imagePath, $angle);
            if (! $rotatedPath) {
                continue;
            }

            $data = $this->tryNodeJsQR($rotatedPath)
                 ?? $this->tryZbarimg($rotatedPath)
                 ?? $this->tryKhanamiryan($rotatedPath);

            @unlink($rotatedPath);

            if ($data) {
                Log::debug('[CccdScanner] thành công sau khi xoay', ['angle' => $angle]);
                return $data;
            }
        }

        return null;
    }

    /**
     * Tạo bản sao ảnh đã xoay (dùng GD) để thử lại QR scan.
     * angle tính ngược chiều kim đồng hồ (PHP imagerotate convention).
     */
    protected function rotateImageForQr(string $imagePath, int $angle): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($imagePath),
            'png'         => @imagecreatefrompng($imagePath),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : null,
            default       => null,
        };

        if (! $src) {
            return null;
        }

        // Downscale xuống ≤1.4M px TRƯỚC khi rotate để tránh giữ 2 bản full-res
        // đồng thời trong bộ nhớ (21MB × 2 = 42MB với ảnh điện thoại 2976×1879)
        $w = imagesx($src);
        $h = imagesy($src);
        $maxPixels = 1_400_000;
        if ($w * $h > $maxPixels) {
            $scale = sqrt($maxPixels / ($w * $h));
            $tw    = (int) ($w * $scale);
            $th    = (int) ($h * $scale);
            $small = imagecreatetruecolor($tw, $th);
            imagecopyresampled($small, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
            imagedestroy($src);
            $src = $small;
        }

        $rotated = imagerotate($src, $angle, 0);
        imagedestroy($src);

        if (! $rotated) {
            return null;
        }

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cccd_rot' . $angle . '_' . uniqid() . '.jpg';
        imagejpeg($rotated, $tmpPath, 95);
        imagedestroy($rotated);

        return file_exists($tmpPath) ? $tmpPath : null;
    }

    /**
     * Dùng Node.js + jsQR — xử lý ảnh JPEG nén tốt hơn ZBar/ZXing.
     * Script qr_scan.cjs phải ở root dự án.
     */
    protected function tryNodeJsQR(string $imagePath): ?array
    {
        $scriptPath = base_path('qr_scan.cjs');
        if (! file_exists($scriptPath)) {
            return null;
        }

        $found = PHP_OS_FAMILY === 'Windows'
            ? @shell_exec('where node 2>NUL')
            : @shell_exec('which node 2>/dev/null');
        if (! $found) {
            return null;
        }

        $realPath = realpath($imagePath) ?: str_replace('/', DIRECTORY_SEPARATOR, $imagePath);

        // Downscale ảnh lớn bằng GD trước khi pass sang Node.js:
        // Jimp đọc file gốc chậm hơn nhiều khi ảnh > 2M pixels.
        $tmpScaled = $this->downscaleForNodeQr($realPath);
        $scanPath  = $tmpScaled ?? $realPath;

        try {
            $argv = ['node', $scriptPath, $scanPath];

            // Trên Linux: dùng `timeout` để kill nếu Node.js treo quá lâu (exit 124)
            if (PHP_OS_FAMILY !== 'Windows') {
                array_unshift($argv, 'timeout', '10');
            }

            $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                return null;
            }

            $stdout   = stream_get_contents($pipes[1]);
            $stderr   = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode === 124) {
                Log::warning('[CccdScanner] jsQR timeout (>10s)', ['path' => basename($imagePath)]);
                return null;
            }

            $text = $stdout ? trim($stdout) : null;
            Log::debug('[CccdScanner] jsQR result', [
                'exit'   => $exitCode,
                'stdout' => $text ? substr($text, 0, 80) : null,
                'stderr' => trim((string) $stderr),
            ]);

            if ($text && $exitCode === 0) {
                if (class_exists(\Normalizer::class)) {
                    $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
                }
                if ($this->isCccdQr($text)) {
                    Log::debug('[CccdScanner] jsQR thành công');
                    return $this->parseQrData($text);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[CccdScanner] jsQR exception', ['error' => $e->getMessage()]);
        } finally {
            if ($tmpScaled && file_exists($tmpScaled)) {
                @unlink($tmpScaled);
            }
        }

        return null;
    }

    /**
     * Downscale ảnh xuống ≤ 2M pixels trước khi pass sang Node.js.
     * Giúp Jimp đọc file nhanh hơn và giảm bộ nhớ trong qr_scan.cjs.
     * Trả về path tmp, hoặc null nếu ảnh đã nhỏ / không thể resize.
     */
    protected function downscaleForNodeQr(string $imagePath): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $imgInfo = @getimagesize($imagePath);
        if (! $imgInfo) {
            return null;
        }

        $w = $imgInfo[0];
        $h = $imgInfo[1];
        $maxPixels = 2_000_000;

        if ($w * $h <= $maxPixels) {
            return null;
        }

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($imagePath),
            'png'         => @imagecreatefrompng($imagePath),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : null,
            default       => null,
        };

        if (! $src) {
            return null;
        }

        $scale   = sqrt($maxPixels / ($w * $h));
        $tw      = (int) ($w * $scale);
        $th      = (int) ($h * $scale);
        $resized = imagecreatetruecolor($tw, $th);
        imagecopyresampled($resized, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($src);

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cccd_node_' . uniqid() . '.jpg';
        imagejpeg($resized, $tmpPath, 95);
        imagedestroy($resized);

        if (! file_exists($tmpPath)) {
            return null;
        }

        Log::debug('[CccdScanner] jsQR downscaled', [
            'from' => "{$w}x{$h} (" . ($w * $h) . 'px)',
            'to'   => "{$tw}x{$th} (" . ($tw * $th) . 'px)',
        ]);

        return $tmpPath;
    }

    /**
     * Dùng zbarimg CLI nếu có — không bị lỗi encoding Vietnamese.
     */
    protected function tryZbarimg(string $imagePath): ?array
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files (x86)\\ZBar\\bin\\zbarimg.exe',
                'C:\\Program Files\\ZBar\\bin\\zbarimg.exe',
                'zbarimg',
            ]
            : ['/usr/bin/zbarimg', '/usr/local/bin/zbarimg', 'zbarimg'];

        $bin = null;
        foreach ($candidates as $c) {
            $isAbsolute = str_contains($c, DIRECTORY_SEPARATOR) || str_contains($c, '/');
            if ($isAbsolute) {
                if (file_exists($c)) { $bin = $c; break; }
            } else {
                $found = PHP_OS_FAMILY === 'Windows'
                    ? @shell_exec('where ' . escapeshellarg($c) . ' 2>NUL')
                    : @shell_exec('which ' . escapeshellarg($c) . ' 2>/dev/null');
                if ($found) { $bin = $c; break; }
            }
        }

        Log::debug('[CccdScanner] zbarimg binary', ['bin' => $bin]);
        if (! $bin) { return null; }

        // Normalize path: Storage::disk()->path() trả về mixed slashes trên Windows
        $realPath = realpath($imagePath) ?: str_replace('/', DIRECTORY_SEPARATOR, $imagePath);

        // Ảnh 800x500 thường quá nhỏ cho ZBar — thử trên ảnh đã upscale+preprocess
        $prePath = $this->preprocessImageForQr($realPath ?: $imagePath);

        $pathsToTry = array_filter([$realPath ?: $imagePath, $prePath]);

        foreach ($pathsToTry as $tryPath) {
            // ZBar 0.10 không có --symbology; dùng -S để giới hạn QR, fallback tất cả
            $argSets = [
                [$bin, '--raw', '-Senable=0', '-Sqrcode.enable=1', '-q', $tryPath],
                [$bin, '--raw', '-q', $tryPath],
            ];

            foreach ($argSets as $argv) {
                try {
                    $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                    if (! is_resource($process)) { continue; }

                    $stdout   = stream_get_contents($pipes[1]);
                    $stderr   = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $exitCode = proc_close($process);

                    $text = $stdout ? trim($stdout) : null;
                    Log::debug('[CccdScanner] zbarimg result', [
                        'img'    => basename($tryPath),
                        'flags'  => implode(' ', array_slice($argv, 1, 3)),
                        'exit'   => $exitCode,
                        'stdout' => $text,
                        'stderr' => trim((string) $stderr),
                    ]);

                    if ($text) {
                        if (class_exists(\Normalizer::class)) {
                            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
                        }
                        if ($this->isCccdQr($text)) {
                            Log::debug('[CccdScanner] zbarimg thành công', ['img' => basename($tryPath)]);
                            if ($prePath && file_exists($prePath)) { @unlink($prePath); }
                            return $this->parseQrData($text);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::debug('[CccdScanner] zbarimg exception', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($prePath && file_exists($prePath)) { @unlink($prePath); }
        return null;
    }

    /**
     * Dùng khanamiryan/qrcode-detector-decoder.
     * Thử ảnh gốc trước, nếu fail thì thử phiên bản grayscale+contrast.
     */
    protected function tryKhanamiryan(string $imagePath): ?array
    {
        if (! class_exists(\Zxing\QrReader::class)) {
            Log::debug('[CccdScanner] Zxing\QrReader class không tồn tại.');
            return null;
        }

        $paths   = [$imagePath];
        $tmpPath = $this->preprocessImageForQr($imagePath);
        if ($tmpPath) {
            $paths[] = $tmpPath;
        }

        // khanamiryan cần ~10 byte RAM / pixel; giới hạn 1.8M pixel để tránh OOM
        $maxPixels = 1_800_000;

        foreach ($paths as $path) {
            try {
                $imgInfo = @getimagesize($path);
                if ($imgInfo && ($imgInfo[0] * $imgInfo[1]) > $maxPixels) {
                    Log::debug('[CccdScanner] khanamiryan skip (quá lớn)', [
                        'path' => basename($path),
                        'pixels' => $imgInfo[0] * $imgInfo[1],
                    ]);
                    continue;
                }

                $qr   = new \Zxing\QrReader($path);
                $text = $qr->text();

                if (! $text) {
                    continue;
                }

                if (! mb_check_encoding($text, 'UTF-8')) {
                    $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
                }

                Log::debug('[CccdScanner] khanamiryan raw', [
                    'path' => basename($path),
                    'text' => $text,
                    'hex'  => bin2hex(mb_substr($text, 0, 50)),
                ]);

                if ($this->isCccdQr($text)) {
                    Log::debug('[CccdScanner] khanamiryan thành công', ['path' => basename($path)]);
                    return $this->parseQrData($text);
                }
            } catch (\Throwable $e) {
                Log::debug('[CccdScanner] khanamiryan thất bại', ['error' => $e->getMessage()]);
            }
        }

        if ($tmpPath && file_exists($tmpPath)) {
            @unlink($tmpPath);
        }

        return null;
    }

    /**
     * Tiền xử lý ảnh: upscale nếu nhỏ + grayscale + contrast + sharpen.
     * Ảnh CCCD upload thường bị resize về 800x500 — QR chỉ ~150-200px,
     * cần upscale 2-3x để ZBar/ZXing decode được.
     */
    protected function preprocessImageForQr(string $imagePath): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));

        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($imagePath),
            'png'         => @imagecreatefrompng($imagePath),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($imagePath) : null,
            default       => null,
        };

        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // Upscale ảnh nhỏ (< 1500px wide) để QR đủ lớn để decode
        // Sau đó đảm bảo tổng pixel ≤ 1.4M để khanamiryan không OOM
        // (kiểm tra theo pixel area thay vì cạnh dài —
        //  ảnh điện thoại 2976×1879 = 5.6M px nhưng không trigger ngưỡng 3000px cũ)
        $targetW = $w;
        $targetH = $h;
        if ($w < 1500) {
            $targetW = (int) ($w * 1.5);
            $targetH = (int) ($h * 1.5);
        }

        $maxPixels = 1_400_000;
        if ($targetW * $targetH > $maxPixels) {
            $scale   = sqrt($maxPixels / ($targetW * $targetH));
            $targetW = (int) ($targetW * $scale);
            $targetH = (int) ($targetH * $scale);
        }

        if ($targetW !== $w || $targetH !== $h) {
            $resized = imagecreatetruecolor($targetW, $targetH);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
            imagedestroy($src);
            $src = $resized;
        }

        // Grayscale + tăng contrast mạnh + sharpen
        imagefilter($src, IMG_FILTER_GRAYSCALE);
        imagefilter($src, IMG_FILTER_CONTRAST, -50);
        imageconvolution($src, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cccd_pre_' . uniqid() . '.jpg';
        imagejpeg($src, $tmpPath, 95);
        imagedestroy($src);

        return $tmpPath;
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
                $val = trim($m[1]);
                // Strip bilingual prefix còn sót lại: "Full name: ..." hoặc "Full name / ..."
                $val = preg_replace('/^Full\s*name\s*[:\s\/\\\\]+/iu', '', $val);
                $val = trim($val);
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
                $val = trim($m[1]);
                // Strip bilingual prefix còn sót lại: "Place of residence: ..."
                $val = preg_replace('/^Place\s*of\s*residence\s*[:\s\/\\\\]+/iu', '', $val);
                $val = trim($val);
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
