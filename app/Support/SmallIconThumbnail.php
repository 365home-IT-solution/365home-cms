<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Bản thu nhỏ cho ảnh do admin upload nhưng chỉ hiển thị dạng icon rất nhỏ (vd ảnh chủ đề lễ hội
// 24x24 cạnh "Lịch đặt phòng trực tuyến" — file gốc từng là GIF động 720x478 ~960KB, Lighthouse
// "Improve image delivery"). Sinh lười ở lần hiển thị đầu tiên thành file anh em
// "{tên}-icon{px}.{gif|webp}" cạnh file gốc, các lần sau chỉ còn 1 lần file_exists.
//
// GIF động: GD chỉ đọc được frame đầu, nên tự tách từng frame (parse block GIF), ghép lên canvas
// theo disposal method, thu nhỏ rồi ghép lại thành GIF động — giữ được hiệu ứng động mà không cần
// Imagick/ffmpeg (server chỉ có GD).
class SmallIconThumbnail
{
    public static function url(string $path, int $size, string $disk = 'public'): string
    {
        $storage = Storage::disk($disk);
        $info = pathinfo($path);
        $isGif = strtolower($info['extension'] ?? '') === 'gif';
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
        $thumbPath = $dir.$info['filename'].'-icon'.$size.($isGif ? '.gif' : '.webp');

        if ($storage->exists($thumbPath)) {
            return $storage->url($thumbPath);
        }

        // Ảnh hỏng/không đọc được thì đừng thử lại ở MỌI request — nhớ lỗi 1 ngày.
        $failKey = 'small-icon-fail:'.$disk.':'.$thumbPath;
        if (! Cache::has($failKey) && $storage->exists($path)) {
            try {
                $source = $storage->path($path);
                $target = $storage->path($thumbPath);
                $isGif ? self::resizeGif($source, $target, $size) : self::resizeStatic($source, $target, $size);

                return $storage->url($thumbPath);
            } catch (\Throwable $e) {
                Cache::put($failKey, true, now()->addDay());
                Log::warning('SmallIconThumbnail: không thu nhỏ được ảnh, dùng bản gốc', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $storage->url($path);
    }

    // [width, height] của bản thu nhỏ (cùng quy tắc co như url()) để gắn thuộc tính width/height cho
    // <img> — trình duyệt giữ chỗ đúng tỉ lệ trước khi ảnh tải xong (tránh CLS). Đọc kích thước file
    // gốc 1 lần rồi nhớ vĩnh viễn theo path (file upload mới luôn có tên mới). null nếu không đọc được.
    public static function dimensions(string $path, int $size, string $disk = 'public'): ?array
    {
        $original = Cache::rememberForever('small-icon-dim:'.$disk.':'.$path, function () use ($path, $disk) {
            $storage = Storage::disk($disk);
            $info = $storage->exists($path) ? @getimagesize($storage->path($path)) : false;

            return $info ? [$info[0], $info[1]] : [];
        });

        return $original ? self::fitSize($original[0], $original[1], $size) : null;
    }

    private static function resizeStatic(string $source, string $target, int $size): void
    {
        $im = @imagecreatefromstring((string) file_get_contents($source));
        if (! $im) {
            throw new \RuntimeException('Không đọc được ảnh');
        }
        [$w, $h] = self::fitSize(imagesx($im), imagesy($im), $size);
        $out = self::newTransparentCanvas($w, $h);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
        self::atomicWrite($target, function (string $tmp) use ($out) {
            imagewebp($out, $tmp, 85);
        });
    }

    private static function resizeGif(string $source, string $target, int $size): void
    {
        $frames = self::parseGif((string) file_get_contents($source));
        [$screenW, $screenH] = [$frames['width'], $frames['height']];
        [$w, $h] = self::fitSize($screenW, $screenH, $size);

        $canvas = self::newTransparentCanvas($screenW, $screenH);
        $out = "GIF89a".pack('vv', $w, $h)."\x00\x00\x00"
            // NETSCAPE2.0: lặp vô hạn
            ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        foreach ($frames['frames'] as $frame) {
            $previous = $frame['disposal'] === 3 ? self::cloneImage($canvas) : null;

            $frameImage = @imagecreatefromstring($frame['gif']);
            if (! $frameImage) {
                throw new \RuntimeException('Không đọc được frame GIF');
            }
            imagealphablending($canvas, true);
            imagecopy($canvas, $frameImage, $frame['left'], $frame['top'], 0, 0, imagesx($frameImage), imagesy($frameImage));

            $small = self::newTransparentCanvas($w, $h);
            imagecopyresampled($small, $canvas, 0, 0, 0, 0, $w, $h, $screenW, $screenH);
            $out .= self::encodeFrame($small, $frame['delay']);

            if ($frame['disposal'] === 2) {
                imagealphablending($canvas, false);
                $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefilledrectangle($canvas, $frame['left'], $frame['top'],
                    $frame['left'] + $frame['width'] - 1, $frame['top'] + $frame['height'] - 1, $clear);
            } elseif ($previous) {
                $canvas = $previous;
            }
        }

        $out .= "\x3B";
        self::atomicWrite($target, function (string $tmp) use ($out) {
            file_put_contents($tmp, $out);
        });
    }

    // Tách GIF thành các frame, mỗi frame đóng gói lại thành 1 GIF 1-frame riêng (đúng kích thước
    // frame, toạ độ 0,0) để GD đọc được; toạ độ thật/disposal/delay trả về kèm theo.
    private static function parseGif(string $data): array
    {
        if (! in_array(substr($data, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            throw new \RuntimeException('Không phải file GIF');
        }
        ['w' => $width, 'h' => $height] = unpack('vw/vh', substr($data, 6, 4));
        $packed = ord($data[10]);
        $pos = 13;
        $globalTable = '';
        if ($packed & 0x80) {
            $len = 3 * (2 << ($packed & 0x07));
            $globalTable = substr($data, $pos, $len);
            $pos += $len;
        }

        $frames = [];
        $gce = null;
        $length = strlen($data);
        while ($pos < $length) {
            $byte = ord($data[$pos]);
            if ($byte === 0x3B) {
                break;
            }
            if ($byte === 0x21) {
                $label = ord($data[$pos + 1]);
                $start = $pos;
                $pos += 2;
                while ($pos < $length && ($blockLen = ord($data[$pos])) !== 0) {
                    $pos += $blockLen + 1;
                }
                $pos++;
                if ($label === 0xF9) {
                    $gce = substr($data, $start, $pos - $start);
                }
                continue;
            }
            if ($byte !== 0x2C) {
                throw new \RuntimeException('Block GIF không hợp lệ');
            }

            $desc = unpack('vleft/vtop/vwidth/vheight', substr($data, $pos + 1, 8));
            $imgPacked = ord($data[$pos + 9]);
            $pos += 10;
            $localTable = '';
            if ($imgPacked & 0x80) {
                $len = 3 * (2 << ($imgPacked & 0x07));
                $localTable = substr($data, $pos, $len);
                $pos += $len;
            }
            $dataStart = $pos;
            $pos++; // LZW min code size
            while ($pos < $length && ($blockLen = ord($data[$pos])) !== 0) {
                $pos += $blockLen + 1;
            }
            $pos++;
            $imageData = substr($data, $dataStart, $pos - $dataStart);

            $disposal = 0;
            $delay = 10;
            if ($gce !== null) {
                $disposal = (ord($gce[3]) >> 2) & 0x07;
                $delay = unpack('v', substr($gce, 4, 2))[1];
            }

            // GIF 1-frame: màn hình = đúng kích thước frame, dùng bảng màu local nếu có, không thì global.
            $table = $localTable !== '' ? $localTable : $globalTable;
            $tableBits = $localTable !== '' ? ($imgPacked & 0x07) : ($packed & 0x07);
            $single = 'GIF89a'.pack('vv', $desc['width'], $desc['height'])
                .chr(($table !== '' ? 0x80 : 0) | 0x70 | $tableBits)."\x00\x00"
                .$table
                .($gce ?? '')
                ."\x2C".pack('vvvv', 0, 0, $desc['width'], $desc['height'])
                .chr($imgPacked & 0x40) // giữ cờ interlace, bỏ bảng màu local (đã đưa lên global)
                .$imageData."\x3B";

            $frames[] = $desc + ['gif' => $single, 'disposal' => $disposal, 'delay' => $delay];
            $gce = null;
        }

        if (! $frames) {
            throw new \RuntimeException('GIF không có frame nào');
        }

        return ['width' => $width, 'height' => $height, 'frames' => $frames];
    }

    // Frame đã thu nhỏ (truecolor + alpha) -> 1 frame GIF: pixel gần trong suốt thành màu trong
    // suốt duy nhất của GIF (GIF chỉ có trong suốt 1-bit), disposal=2 vì mỗi frame là cả canvas.
    private static function encodeFrame(\GdImage $small, int $delay): string
    {
        $w = imagesx($small);
        $h = imagesy($small);
        $palette = imagecreatetruecolor($w, $h);
        $key = imagecolorallocate($palette, 255, 0, 254);
        imagefill($palette, 0, 0, $key);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                if ((($rgba >> 24) & 0x7F) < 64) {
                    imagesetpixel($palette, $x, $y, $rgba & 0xFFFFFF);
                }
            }
        }
        imagetruecolortopalette($palette, false, 255);
        $transparent = imagecolorclosest($palette, 255, 0, 254);
        imagecolortransparent($palette, $transparent);

        ob_start();
        imagegif($palette);
        $gif = (string) ob_get_clean();

        // Lấy bảng màu global + dữ liệu ảnh từ GIF 1-frame GD vừa ghi, chuyển thành bảng local.
        $packed = ord($gif[10]);
        $tableBits = $packed & 0x07;
        $pos = 13;
        $table = '';
        if ($packed & 0x80) {
            $table = substr($gif, $pos, 3 * (2 << $tableBits));
            $pos += strlen($table);
        }
        while (ord($gif[$pos]) === 0x21) {
            $pos += 2;
            while (($blockLen = ord($gif[$pos])) !== 0) {
                $pos += $blockLen + 1;
            }
            $pos++;
        }
        $imageData = substr($gif, $pos + 10, -1);

        return "\x21\xF9\x04".chr((2 << 2) | 0x01).pack('v', $delay).chr($transparent)."\x00"
            ."\x2C".pack('vvvv', 0, 0, $w, $h).chr(($table !== '' ? 0x80 : 0) | $tableBits)
            .$table.$imageData;
    }

    private static function fitSize(int $width, int $height, int $size): array
    {
        $scale = min(1, $size / max($width, $height));

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private static function newTransparentCanvas(int $w, int $h): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));

        return $im;
    }

    private static function cloneImage(\GdImage $src): \GdImage
    {
        $copy = self::newTransparentCanvas(imagesx($src), imagesy($src));
        imagecopy($copy, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));

        return $copy;
    }

    private static function atomicWrite(string $target, callable $write): void
    {
        $tmp = $target.'.tmp-'.uniqid();
        try {
            $write($tmp);
            if (! file_exists($tmp) || filesize($tmp) === 0) {
                throw new \RuntimeException('File sinh ra rỗng');
            }
            rename($tmp, $target);
        } finally {
            @unlink($tmp);
        }
    }
}
