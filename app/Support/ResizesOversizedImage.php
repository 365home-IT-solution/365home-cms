<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;

// Hạ kích thước ảnh gốc ngay tại chỗ (đè lên chính file đó, giữ nguyên định dạng/tên/URL) nếu
// cạnh dài vượt quá mức app cần — không sinh thêm bản nào khác, không đổi field API.
class ResizesOversizedImage
{
    public static function apply(string $absolutePath, int $maxLongEdge = 1440): void
    {
        if (! file_exists($absolutePath)) {
            return;
        }

        $dimensions = @getimagesize($absolutePath);

        if (! $dimensions) {
            return;
        }

        [$width, $height] = $dimensions;

        if (max($width, $height) <= $maxLongEdge) {
            return;
        }

        // getimagesize() đọc header nên có thể đọc được dù phần dữ liệu ảnh phía sau bị hỏng/ghi
        // dở — Imagick giải mã thật ở bước save() mới phát hiện ra. 1 file hỏng KHÔNG được phép
        // làm crash cả loạt (lệnh backfill xử lý hàng trăm ảnh trong 1 lần chạy).
        //
        // Ghi ra file tạm rồi rename() đè lên bản gốc — nếu process bị OOM-kill/crash giữa lúc
        // save() (từng xảy ra thật, làm bản gốc còn lại 0 byte không thể phục hồi), file tạm hỏng
        // bị vứt đi còn bản gốc không hề bị đụng tới. Giữ nguyên đuôi file gốc vì save() không gọi
        // ->format() nên tự suy định dạng từ phần mở rộng của đường dẫn truyền vào.
        $info = pathinfo($absolutePath);
        $tmpPath = $info['dirname'].DIRECTORY_SEPARATOR.'.resize-tmp-'.uniqid().'.'.$info['extension'];

        try {
            Image::load($absolutePath)
                ->fit(Fit::Max, $maxLongEdge, $maxLongEdge)
                ->save($tmpPath);

            if (! file_exists($tmpPath) || filesize($tmpPath) === 0) {
                throw new \RuntimeException('File resize ra rỗng');
            }

            rename($tmpPath, $absolutePath);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            Log::warning('ResizesOversizedImage: không đọc được ảnh, bỏ qua', [
                'path'  => $absolutePath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
