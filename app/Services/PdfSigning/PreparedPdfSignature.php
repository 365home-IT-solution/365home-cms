<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

// Kết quả bước "chuẩn bị" — PDF đã có sẵn khoảng trống chữ ký (chưa điền), kèm toạ độ cần thiết để
// tính hash và điền chữ ký thật vào đúng chỗ sau này.
final class PreparedPdfSignature
{
    public function __construct(
        public readonly string $pdfBytes,
        public readonly array $byteRange,
        public readonly int $contentsHexStartOffset,
        public readonly int $contentsHexMaxBytes,
    ) {
    }
}
