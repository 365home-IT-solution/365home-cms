<?php

namespace Modules\BladeThemeV1\Support;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class QrCodeGenerator
{
    /**
     * Sinh QR code dạng SVG inline (data URI) thay vì gọi api.qrserver.com — Search Console
     * (Site Audit) báo lỗi "External resources blocked by robots.txt" vì robots.txt của
     * qrserver.com chặn Googlebot thu thập ảnh QR đó. Render tại chỗ bằng bacon/bacon-qr-code
     * (đã có sẵn qua filament-breezy) để tránh phụ thuộc dịch vụ ngoài.
     */
    public static function dataUri(string $data, int $size = 200, int $margin = 0): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle($size, $margin, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
                new SvgImageBackEnd
            )
        ))->writeString($data);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
