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
    // Giới hạn tổng thời gian scan để không vượt quá max_execution_time của PHP web SAPI
    // (đã thấy trong log thực tế = 30s) khi được gọi ĐỒNG BỘ ngay lúc tạo/sửa đơn — phải
    // để dư ít nhất ~10s cho phần còn lại của afterCreate()/afterSave() (PayOS, gán mã cổng...).
    private const MAX_SCAN_SECONDS = 18;

    // Timeout riêng cho từng bước con — CỘNG DỒN các bước có thể vượt MAX_SCAN_SECONDS nếu
    // không được canh theo thời gian còn lại thực tế, nên mỗi bước còn phải tự co lại theo
    // remainingSeconds() chứ không chỉ dùng đúng hằng số này.
    private const NODE_QR_TIMEOUT_SECONDS = 8;
    private const ZBAR_TIMEOUT_SECONDS    = 4;
    private const OCR_TIMEOUT_SECONDS     = 8;

    private float $scanDeadline = 0;

    private function startTimer(): void
    {
        $this->scanDeadline = microtime(true) + self::MAX_SCAN_SECONDS;
    }

    private function isTimedOut(): bool
    {
        return $this->scanDeadline > 0 && microtime(true) >= $this->scanDeadline;
    }

    // Số giây còn lại trước khi hết ngân sách quét — dùng để mỗi bước con tự giới hạn timeout
    // của CHÍNH NÓ, tránh trường hợp bước trước ăn gần hết thời gian nhưng bước sau vẫn cứ chờ
    // đủ timeout riêng của nó, khiến tổng thời gian thực tế vượt xa MAX_SCAN_SECONDS.
    private function remainingSeconds(): float
    {
        if ($this->scanDeadline <= 0) {
            return (float) self::MAX_SCAN_SECONDS;
        }

        return max(0.0, $this->scanDeadline - microtime(true));
    }

    /**
     * Chạy 1 tiến trình con (proc_open) với timeout THẬT SỰ hoạt động trên mọi hệ điều hành.
     *
     * QUAN TRỌNG: stream_set_blocking() trên pipe của proc_open KHÔNG đáng tin cậy trên
     * Windows (giới hạn đã biết của PHP) — gọi stream_get_contents() trong lúc tiến trình
     * còn chạy vẫn có thể bị chặn (blocking) dù đã set non-blocking. Vì vậy KHÔNG được đụng
     * vào pipe khi đang poll — chỉ kiểm tra proc_get_status(), chỉ đọc pipe SAU KHI tiến
     * trình đã dừng hẳn (lúc đó đọc luôn trả về ngay, không còn rủi ro treo).
     *
     * @return array{stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    private function runProcessWithTimeout(array $argv, float $timeoutSeconds): array
    {
        $timeoutSeconds = max(0.5, $timeoutSeconds);

        $process = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return ['stdout' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false];
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }

            usleep(50_000);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exitCode' => $exitCode, 'timedOut' => $timedOut];
    }

    /**
     * Quét và trả về mảng cccd_data cho đơn hàng, hoặc null nếu không đọc được.
     */
    public function scanOrder(Order $order): ?array
    {
        ini_set('memory_limit', '256M');
        $this->startTimer();

        $frontPath = $order->cccd_front ? Storage::disk('public')->path($order->cccd_front) : null;
        $backPath  = $order->cccd_back  ? Storage::disk('public')->path($order->cccd_back)  : null;

        return $this->scanBothSides($frontPath, $backPath, ['order_id' => $order->id ?? null]);
    }

    /**
     * Quét và trả về mảng cccd_data cho khách hàng, hoặc null nếu không đọc được.
     */
    public function scanCustomer(Customer $customer): ?array
    {
        ini_set('memory_limit', '256M');
        $this->startTimer();

        $frontPath = $customer->cccd_front ? Storage::disk('public')->path($customer->cccd_front) : null;
        $backPath  = $customer->cccd_back  ? Storage::disk('public')->path($customer->cccd_back)  : null;

        return $this->scanBothSides($frontPath, $backPath, ['customer_id' => $customer->id ?? null]);
    }

    /**
     * Quét QR từ 2 path đã lưu trên disk 'public'.
     * Dùng cho Livewire booking flow sau khi file đã store() vĩnh viễn.
     */
    public function scanPaths(?string $frontPath, ?string $backPath): ?array
    {
        ini_set('memory_limit', '256M');
        $this->startTimer();

        $front = $frontPath ? Storage::disk('public')->path($frontPath) : null;
        $back  = $backPath  ? Storage::disk('public')->path($backPath)  : null;

        return $this->scanBothSides($front, $back);
    }

    /**
     * Quét QR bằng cách truyền cả 2 ảnh vào 1 lần gọi Node.js.
     * Node.js thử từng attempt trên TẤT CẢ ảnh trước khi chuyển attempt tiếp theo
     * → không cần biết QR ở mặt trước hay sau (tương thích mọi format CCCD).
     *
     * Sau đó fallback sang zbarimg + khanamiryan + rotation trên từng ảnh riêng.
     */
    protected function scanBothSides(?string $frontPath, ?string $backPath, array $logCtx = []): ?array
    {
        // Normalize EXIF orientation trước mọi bước — ảnh điện thoại thường có
        // EXIF Orientation ≠ 1 nhưng pixel thô vẫn xoay → jsQR/ZBar không decode được.
        $frontNorm = $frontPath && file_exists($frontPath)
            ? ($this->normalizeExifOrientation($frontPath) ?? $frontPath)
            : $frontPath;
        $backNorm  = $backPath && file_exists($backPath)
            ? ($this->normalizeExifOrientation($backPath) ?? $backPath)
            : $backPath;

        // Thu thập các file tmp để dọn dẹp khi thoát (dù thành công hay thất bại)
        $tmpNorm = array_values(array_filter([
            $frontNorm !== $frontPath ? $frontNorm : null,
            $backNorm  !== $backPath  ? $backNorm  : null,
        ]));

        $paths = array_values(array_filter([$frontNorm, $backNorm], fn ($p) => $p && file_exists($p)));

        try {
            if (empty($paths)) {
                return null;
            }

            // Bước 1: Node.js jsQR với tất cả ảnh cùng 1 lần — crop + scale đa chiến lược
            // Không pre-downscale ở PHP: Node.js xử lý ảnh gốc độ phân giải cao hơn.
            $data = $this->tryNodeJsQR(...$paths);
            if ($data) {
                return $data;
            }

            if ($this->isTimedOut()) {
                Log::warning('[CccdScanner] timeout sau jsQR — bỏ qua zbarimg, nhảy thẳng OCR', $logCtx);
            } else {
                // Bước 2: zbarimg CLI nếu có — nhanh, không cần PHP memory
                foreach ($paths as $path) {
                    $data = $this->tryZbarimg($path);
                    if ($data) {
                        return $data;
                    }
                    if ($this->isTimedOut()) {
                        break;
                    }
                }
            }

            // Bước 3: OCR.space — fallback cuối cùng. TRƯỚC ĐÂY luôn chạy kể cả khi đã hết
            // ngân sách thời gian (dùng Http::timeout(30) cố định) — đây chính là 1 trong các
            // nguyên nhân có thể khiến tổng thời gian quét vượt xa MAX_SCAN_SECONDS. Giờ
            // tryOcrFrontside() tự co timeout theo remainingSeconds() và tự bỏ qua nếu ngân
            // sách còn lại quá ít để gọi API có ý nghĩa.
            $ocrTarget = $frontNorm && file_exists($frontNorm) ? $frontNorm : $frontPath;
            if ($ocrTarget && file_exists($ocrTarget)) {
                $data = $this->tryOcrFrontside($ocrTarget);
                if ($data) {
                    Log::info('[CccdScanner] OCR.space fallback thành công');
                    return $data;
                }
            }

            return null;

        } finally {
            foreach ($tmpNorm as $t) {
                if ($t && file_exists($t)) {
                    @unlink($t);
                }
            }
        }
    }

    /**
     * Quét ĐỘC LẬP 1 ảnh CCCD (không gộp chung với ảnh còn lại) — dùng để so sánh chéo giữa
     * mặt trước và mặt sau (xem sidesConflict()). Khác với scanBothSides()/scanPaths(): những
     * hàm đó coi 2 ảnh là 1 "pool" và dừng lại ở lần decode QR đầu tiên, không phân biệt ảnh
     * nào là ảnh nào — nên không thể phát hiện trường hợp upload nhầm ảnh của 2 người khác nhau.
     */
    public function scanImage(?string $imagePath): ?array
    {
        if (! $imagePath || ! file_exists($imagePath)) {
            return null;
        }

        ini_set('memory_limit', '256M');
        $this->startTimer();

        $norm = $this->normalizeExifOrientation($imagePath) ?? $imagePath;

        try {
            $data = $this->tryQrScan($norm);
            if ($data) {
                return $data;
            }

            if ($this->isTimedOut()) {
                return null;
            }

            return $this->tryOcrFrontside($norm);
        } finally {
            if ($norm !== $imagePath && file_exists($norm)) {
                @unlink($norm);
            }
        }
    }

    /**
     * So sánh CHÉO thông tin quét được từ mặt trước và mặt sau — phát hiện trường hợp khách
     * upload nhầm 2 ảnh CCCD của 2 người khác nhau (VD: mặt trước là CCCD người A, mặt sau lại
     * là CCCD người B). So sánh trước theo số CCCD (đáng tin cậy nhất); nếu 1 trong 2 mặt
     * không đọc được số thì fallback so sánh họ tên (đã chuẩn hoá bỏ dấu/hoa-thường).
     *
     * Trả về true nếu phát hiện xung đột. Trả về false nếu khớp hoặc không đủ dữ liệu ở 1
     * trong 2 mặt để so sánh (tránh false positive khi ảnh mờ/không đọc được).
     */
    public function sidesConflict(?string $frontPath, ?string $backPath): bool
    {
        if (! $frontPath || ! $backPath) {
            return false;
        }

        $front = $this->scanImage($frontPath);
        $back  = $this->scanImage($backPath);

        if (! $front || ! $back) {
            return false;
        }

        $frontCccd = trim($front['cccd'] ?? '');
        $backCccd  = trim($back['cccd'] ?? '');
        if ($frontCccd !== '' && $backCccd !== '') {
            return $frontCccd !== $backCccd;
        }

        $frontName = $this->normalizeNameForCompare($front['full_name'] ?? '');
        $backName  = $this->normalizeNameForCompare($back['full_name'] ?? '');
        if ($frontName !== '' && $backName !== '') {
            return $frontName !== $backName;
        }

        return false;
    }

    /**
     * Chuẩn hoá họ tên để so sánh: bỏ dấu tiếng Việt, viết hoa, gộp khoảng trắng thừa — tránh
     * báo xung đột sai chỉ vì OCR/QR khác cách viết hoa hoặc khoảng trắng.
     */
    private function normalizeNameForCompare(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $ascii = \Illuminate\Support\Str::ascii($name);
        $ascii = preg_replace('/\s+/', ' ', $ascii);

        return mb_strtoupper(trim((string) $ascii));
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
     * Đọc EXIF Orientation và xuất bản sao ảnh đã xoay đúng chiều.
     * Trả về path tmp mới, hoặc null nếu không cần xoay / không hỗ trợ.
     *
     * Mapping EXIF Orientation → góc xoay GD (ngược chiều kim đồng hồ):
     *   1 = bình thường       → không xoay
     *   3 = lật 180°          → xoay 180°
     *   6 = xoay 90° CW       → xoay 270° (ngược lại để sửa)
     *   8 = xoay 90° CCW      → xoay 90°
     */
    protected function normalizeExifOrientation(string $imagePath): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('exif_read_data')) {
            return null;
        }

        $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg'], true)) {
            return null;
        }

        $exif        = @exif_read_data($imagePath);
        $orientation = $exif['Orientation'] ?? 1;

        $angle = match ($orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return null;
        }

        $src = @imagecreatefromjpeg($imagePath);
        if (! $src) {
            return null;
        }

        $rotated = imagerotate($src, $angle, 0);
        imagedestroy($src);

        if (! $rotated) {
            return null;
        }

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cccd_exif_' . uniqid() . '.jpg';
        imagejpeg($rotated, $tmpPath, 95);
        imagedestroy($rotated);

        Log::debug('[CccdScanner] EXIF normalize', [
            'orientation' => $orientation,
            'angle'       => $angle,
            'file'        => basename($imagePath),
        ]);

        return file_exists($tmpPath) ? $tmpPath : null;
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
     * Không pre-downscale ở PHP: Node.js tự crop + scale với độ phân giải cao hơn.
     */
    protected function tryNodeJsQR(string ...$imagePaths): ?array
    {
        $scriptPath = base_path('qr_scan.cjs');
        if (! file_exists($scriptPath)) {
            return null;
        }

        // Dùng đường dẫn tuyệt đối của node để tránh PATH khác nhau giữa shell và web server
        $nodeBin = $this->resolveNodeBin();
        if (! $nodeBin) {
            Log::warning('[CccdScanner] Node.js không tìm thấy, bỏ qua jsQR');
            return null;
        }

        $realPaths = [];
        foreach ($imagePaths as $path) {
            $real = realpath($path) ?: str_replace('/', DIRECTORY_SEPARATOR, $path);
            if ($real && file_exists($real)) {
                $realPaths[] = $real;
            }
        }

        if (empty($realPaths)) {
            return null;
        }

        try {
            $argv = array_merge([$nodeBin, $scriptPath], $realPaths);

            // Timeout co giãn theo ngân sách CÒN LẠI của toàn bộ lượt quét (không chỉ 1 hằng
            // số cố định) — nếu các bước trước đó (vd normalize EXIF nhiều ảnh) đã ăn bớt thời
            // gian, bước này cũng phải tự rút ngắn theo, tránh cộng dồn vượt MAX_SCAN_SECONDS.
            $timeoutSeconds = min(self::NODE_QR_TIMEOUT_SECONDS, $this->remainingSeconds());

            if ($timeoutSeconds <= 0) {
                Log::warning('[CccdScanner] jsQR bỏ qua — đã hết ngân sách thời gian quét');
                return null;
            }

            $result = $this->runProcessWithTimeout($argv, $timeoutSeconds);

            if ($result['timedOut']) {
                Log::warning('[CccdScanner] jsQR timeout (>' . round($timeoutSeconds, 1) . 's)');
                return null;
            }

            $stdout   = $result['stdout'];
            $stderr   = $result['stderr'];
            $exitCode = $result['exitCode'];

            $text = $stdout ? trim($stdout) : null;

            // Log ở info level để dễ debug khi QR được đọc nhưng không phải CCCD format
            Log::info('[CccdScanner] jsQR result', [
                'exit'   => $exitCode,
                'stdout' => $text ? substr($text, 0, 120) : null,
                'stderr' => trim((string) $stderr),
            ]);

            if ($text && $exitCode === 0) {
                if (class_exists(\Normalizer::class)) {
                    $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
                }
                if ($this->isCccdQr($text)) {
                    Log::info('[CccdScanner] jsQR thành công');
                    return $this->parseQrData($text);
                }
                Log::warning('[CccdScanner] jsQR đọc được QR nhưng không phải format CCCD', [
                    'decoded' => substr($text, 0, 200),
                    'pipes'   => substr_count($text, '|'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[CccdScanner] jsQR exception', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Tìm đường dẫn tuyệt đối của node executable.
     * Dùng đường dẫn tuyệt đối để tránh PATH không khớp giữa web server và shell.
     */
    private function resolveNodeBin(): ?string
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached ?: null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $raw = @shell_exec('where node 2>NUL');
        } else {
            $raw = @shell_exec('which node 2>/dev/null');
        }

        if (! $raw) {
            // Fallback: thử các đường dẫn phổ biến
            $candidates = PHP_OS_FAMILY === 'Windows'
                ? ['C:\\Program Files\\nodejs\\node.exe', 'C:\\Program Files (x86)\\nodejs\\node.exe']
                : ['/usr/bin/node', '/usr/local/bin/node', '/opt/homebrew/bin/node'];
            foreach ($candidates as $c) {
                if (file_exists($c)) {
                    $cached = $c;
                    return $c;
                }
            }
            $cached = '';
            return null;
        }

        // `where` có thể trả về nhiều dòng — lấy dòng đầu tiên
        $bin    = trim(strtok(trim($raw), "\n\r"));
        $cached = $bin ?: '';
        return $bin ?: null;
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
                // Có tới 4 tổ hợp (2 ảnh x 2 argSets) mỗi lần gọi — mỗi tổ hợp phải tự canh
                // theo ngân sách thời gian CÒN LẠI (không phải hằng số cố định), nếu không tổng
                // thời gian của riêng bước zbarimg có thể vượt xa MAX_SCAN_SECONDS.
                $timeoutSeconds = min(self::ZBAR_TIMEOUT_SECONDS, $this->remainingSeconds());
                if ($timeoutSeconds <= 0) {
                    Log::debug('[CccdScanner] zbarimg bỏ qua — đã hết ngân sách thời gian quét');
                    if ($prePath && file_exists($prePath)) { @unlink($prePath); }
                    return null;
                }

                try {
                    $result = $this->runProcessWithTimeout($argv, $timeoutSeconds);

                    if ($result['timedOut']) {
                        Log::debug('[CccdScanner] zbarimg timeout (>' . round($timeoutSeconds, 1) . 's)', ['img' => basename($tryPath)]);
                        continue;
                    }

                    $stdout   = $result['stdout'];
                    $stderr   = $result['stderr'];
                    $exitCode = $result['exitCode'];

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

            // Co timeout theo ngân sách CÒN LẠI (tối đa OCR_TIMEOUT_SECONDS) — nếu các bước
            // trước đã ăn gần hết ngân sách, gọi API dài (Http::timeout cố định 30s trước đây)
            // sẽ đẩy tổng thời gian vượt xa MAX_SCAN_SECONDS. Còn quá ít thời gian thì bỏ luôn,
            // không đáng gọi (API cần vài giây để xử lý ảnh CCCD thật).
            $timeoutSeconds = min(self::OCR_TIMEOUT_SECONDS, $this->remainingSeconds());
            if ($timeoutSeconds < 2.0) {
                Log::debug('[CccdScanner] OCR.space bỏ qua — đã hết ngân sách thời gian quét');
                return null;
            }

            $text = $ocr->extractTextFromImage($imagePath, (int) round($timeoutSeconds));
            if (empty($text)) {
                return null;
            }

            Log::info('[CccdScanner] OCR raw text', [
                'path'    => basename($imagePath),
                'length'  => strlen($text),
                'preview' => substr($text, 0, 300),
            ]);

            $data = $this->parseOcrText($text);
            if ($data) {
                Log::info('[CccdScanner] OCR parse thành công', ['path' => basename($imagePath)]);
            } else {
                Log::warning('[CccdScanner] OCR extract được text nhưng parse thất bại', [
                    'path'    => basename($imagePath),
                    'preview' => substr($text, 0, 300),
                ]);
            }

            return $data;
        } catch (\Throwable $e) {
            Log::warning('[CccdScanner] OCR thất bại', [
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

        // OCR.space hay: (1) mất dấu tiếng Việt (Số→So, Họ→Ho), (2) chèn space vào số.
        // Chuẩn hoá riêng để không ảnh hưởng matching tên/địa chỉ tiếng Việt.
        $textNum = preg_replace('/(\d)\s+(\d)/u', '$1$2', $text);
        $textNum = preg_replace('/(\d)\s+(\d)/u', '$1$2', $textNum); // lặp cho "1 2 3 4"

        // Số CCCD: 12 chữ số — ưu tiên sau label "Số / No.:" (cả có và không dấu)
        if (preg_match('/(?:S[oố]\s*[\/|]\s*No\.?|No\.?)\s*[:\.]?\s*(\d{12})/ui', $textNum, $m)) {
            $cccd = $m[1];
        } elseif (preg_match('/\b(\d{12})\b/', $textNum, $m)) {
            $cccd = $m[1];
        } elseif (preg_match('/\b(\d{9})\b/', $textNum, $m)) {
            $cccd = $m[1];
        }

        $lines = preg_split('/\r?\n/', $text);

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Họ và tên — hỗ trợ có/không dấu: "Họ và tên", "Ho va ten", "Full name"
            if (! $fullName && preg_match('/(?:H[oọ](?:\s+(?:v[aà]|va|&)\s+|\s*[&]\s*)t[eê]n|Full\s*n[ae]me?)[:\s\/\\\\]+(.+)/ui', $line, $m)) {
                $val = trim($m[1]);
                $val = preg_replace('/^Full\s*n[ae]me?\s*[:\s\/\\\\]+/iu', '', $val);
                $val = trim($val);
                if (strlen($val) > 2 && ! preg_match('/^(?:Full\s*name|\/|\\\\|\s)$/i', $val)) {
                    $fullName = $val;
                } else {
                    // Tên nằm ở dòng tiếp theo (OCR thường xuống dòng sau label)
                    $next = trim($lines[$i + 1] ?? '');
                    if (strlen($next) > 2 && preg_match('/^\p{Lu}/u', $next)) {
                        $fullName = $next;
                    }
                }
            }

            // Ngày sinh — hỗ trợ có/không dấu
            if (! $dob && preg_match('/(?:Ng[aà]y\s*sinh|Date\s*of\s*birth)[:\s\/\\\\]+(.+)/ui', $line, $m)) {
                $raw = trim($m[1]);
                // Cắt bỏ phần sau (giới tính, quốc tịch thường nằm cùng dòng)
                $raw = preg_replace('/\s+(?:Giới tính|Sex|Quốc tịch|Nationality).*/ui', '', $raw);
                if (preg_match('/(\d{2})[\/\-\.](\d{2})[\/\-\.](\d{4})/', $raw, $dm)) {
                    $dob = $dm[1] . '/' . $dm[2] . '/' . $dm[3];
                } elseif (preg_match('/\d{8}/', $raw, $dm)) {
                    $dob = $this->formatDate($dm[0]);
                }
                // Thử tìm ngày trên dòng tiếp nếu raw rỗng
                if (! $dob) {
                    $next = trim($lines[$i + 1] ?? '');
                    if (preg_match('/(\d{2})[\/\-\.](\d{2})[\/\-\.](\d{4})/', $next, $dm)) {
                        $dob = $dm[1] . '/' . $dm[2] . '/' . $dm[3];
                    }
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
