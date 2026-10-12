<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

// Nhúng chữ ký số PAdES-BES vào PDF theo đúng kỹ thuật "incremental update" chuẩn (không sửa 1 byte
// nào của PDF gốc — chỉ THÊM các object mới vào cuối file + 1 bảng xref/trailer mới trỏ /Prev về
// bảng cũ). Đây là cách MỌI công cụ ký PDF thật (Adobe, iText...) đều dùng, để nếu bỏ phần thêm vào
// thì file vẫn đọc được y hệt bản gốc (an toàn, không phá nội dung đã có).
//
// KHÔNG có thư viện PHP nào dựng sẵn đúng luồng "để trống chỗ ký ngoài, tự điền chữ ký thật vào sau"
// — các thư viện ký PDF phổ biến (TCPDF, FPDI...) đều giả định có sẵn private key ngay trên máy.
class PdfIncrementalSigner
{
    // Số byte dự trữ cho CMS SignedData (chữ ký thật của 1 hợp đồng thường ~1.5-2.5KB, dự trù dư
    // ra nhiều lần để chắc chắn không thiếu chỗ dù chứng thư dài hơn dự kiến).
    private const RESERVED_CONTENT_BYTES = 8000;

    public function prepare(string $pdfBytes): PreparedPdfSignature
    {
        $maxObjNum = $this->findMaxObjectNumber($pdfBytes);
        // File đã có chữ ký (ký chồng: bên A ký sau bên B): lấy đúng PHIÊN BẢN MỚI NHẤT của catalog/trang và thêm trường chữ ký vào AcroForm đang có,
        // không ghi đè — ghi đè sẽ làm mất chữ ký trước đó.
        $existingSignatures = preg_match_all('/\/ByteRange\s*\[/', $pdfBytes);
        // Có sẵn trường chữ ký còn trống (xem addEmptySignatureField): vẫn phải thêm vào AcroForm đang có chứ không dựng AcroForm mới đè lên.
        $incremental = $existingSignatures > 0 || str_contains($pdfBytes, '/AcroForm');
        [$catalogObjNum, $catalogDict] = $incremental ? $this->latestRevision($pdfBytes, $this->rootObjectNumber($pdfBytes)) : $this->findObjectByType($pdfBytes, 'Catalog');
        [$pageObjNum, $pageDict] = $this->findObjectByType($pdfBytes, 'Page');
        if ($incremental) {
            [$pageObjNum, $pageDict] = $this->latestRevision($pdfBytes, $pageObjNum);
        }
        $existingAcro = $incremental ? $this->existingAcroForm($pdfBytes, $catalogDict) : null;

        $sigObjNum = $maxObjNum + 1;
        $widgetObjNum = $maxObjNum + 2;
        $acroFormObjNum = $existingAcro['ref'] ?? ($maxObjNum + 3);
        $fieldName = '365home Contract Signature' . ($existingSignatures > 0 ? ' ' . ($existingSignatures + 1) : '');

        $byteRangePlaceholder = '0000000000 0000000000 0000000000';
        $contentsPlaceholder = str_repeat('00', self::RESERVED_CONTENT_BYTES);
        $signDate = now()->format('YmdHisO');
        $signDate = substr($signDate, 0, -2) . "'" . substr($signDate, -2) . "'";

        $sigBody = "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /ETSI.CAdES.detached"
            . " /M (D:{$signDate}) /ByteRange [0 {$byteRangePlaceholder}] /Contents <{$contentsPlaceholder}> >>";

        $widgetBody = "<< /Type /Annot /Subtype /Widget /FT /Sig /Rect [0 0 0 0] /F 132"
            . " /V {$sigObjNum} 0 R /T ({$fieldName}) /P {$pageObjNum} 0 R >>";

        $writeAcroForm = true;
        if ($existingAcro === null) {
            $acroFormBody = "<< /Fields [{$widgetObjNum} 0 R] /SigFlags 3 >>";
            $newCatalogDict = $this->upsertDictKey($catalogDict, 'AcroForm', "{$acroFormObjNum} 0 R");
        } elseif ($existingAcro['ref'] !== null) {
            // AcroForm là object riêng: ghi phiên bản mới của chính object đó (cùng số), catalog giữ nguyên.
            $acroFormBody = $this->addFieldToAcroForm($existingAcro['dict'], $widgetObjNum);
            $newCatalogDict = $catalogDict;
        } else {
            // AcroForm nằm trực tiếp trong catalog: sửa ngay trong catalog.
            $acroFormBody = '';
            $writeAcroForm = false;
            $newCatalogDict = str_replace($existingAcro['dict'], $this->addFieldToAcroForm($existingAcro['dict'], $widgetObjNum), $catalogDict);
        }
        $newPageDict = $this->appendToAnnotsArray($pageDict, $widgetObjNum);

        $appendix = '';
        $offsets = [];
        $baseOffset = strlen($pdfBytes);

        $addObj = function (int $num, string $body) use (&$appendix, &$offsets, $baseOffset): void {
            $offsets[$num] = $baseOffset + strlen($appendix);
            $appendix .= "{$num} 0 obj\n{$body}\nendobj\n";
        };

        // Ghi Sig object TRƯỚC — cần biết offset tuyệt đối của chuỗi hex /Contents bên trong để
        // tính đúng /ByteRange (phải tính SAU khi biết được vị trí thật trong file cuối cùng).
        $sigObjOffset = $baseOffset + strlen($appendix);
        $addObj($sigObjNum, $sigBody);

        $ltPosInSigObj = strpos($sigBody, '<' . $contentsPlaceholder . '>');
        $hexStartAbs = $sigObjOffset + strlen("{$sigObjNum} 0 obj\n") + $ltPosInSigObj + 1;
        $hexEndAbs = $hexStartAbs + strlen($contentsPlaceholder);

        $addObj($widgetObjNum, $widgetBody);
        if ($writeAcroForm) {
            $addObj($acroFormObjNum, $acroFormBody);
        }
        $addObj($catalogObjNum, $newCatalogDict);
        $addObj($pageObjNum, $newPageDict);

        $xrefOffset = $baseOffset + strlen($appendix);
        $maxNewObjNum = max($widgetObjNum, $acroFormObjNum); // số object mới cao nhất (widget/acroform > sig > catalog/page vốn đã có)
        $size = max($maxNewObjNum, $catalogObjNum, $pageObjNum) + 1;

        $originalStartXref = $this->findLastStartXref($pdfBytes);

        $xrefAndTrailer = $this->buildXrefAndTrailer($offsets, $size, $catalogObjNum, $originalStartXref);
        $appendix .= $xrefAndTrailer . "startxref\n{$xrefOffset}\n%%EOF\n";

        // /ByteRange phải phủ TOÀN BỘ file kể cả bảng xref/trailer ở cuối, nếu không công cụ kiểm tra coi như file bị thêm nội dung sau khi ký.
        // Tính SAU khi đã ghép xref/trailer; số thật được patch vào placeholder cố định 10 chữ số nên không làm lệch offset nào.
        $byteRange = [0, $hexStartAbs, $hexEndAbs, $baseOffset + strlen($appendix) - $hexEndAbs];

        $finalPdf = $pdfBytes . $appendix;

        // Patch /ByteRange bằng số thật — độ dài chuỗi placeholder và số thật PHẢI bằng nhau (đã
        // dùng %010d cố định 10 chữ số/số) để không làm lệch offset của bất kỳ byte nào phía sau.
        $realByteRangeStr = sprintf('%010d %010d %010d', $byteRange[1], $byteRange[2], $byteRange[3]);
        $finalPdf = substr_replace($finalPdf, $realByteRangeStr, $this->findByteRangePlaceholderOffset($finalPdf, $sigObjOffset, $byteRangePlaceholder), strlen($byteRangePlaceholder));

        return new PreparedPdfSignature(
            pdfBytes: $finalPdf,
            byteRange: $byteRange,
            contentsHexStartOffset: $hexStartAbs,
            contentsHexMaxBytes: self::RESERVED_CONTENT_BYTES,
        );
    }

    public function embedSignature(PreparedPdfSignature $prepared, string $cmsDer): string
    {
        $hex = strtoupper(bin2hex($cmsDer));
        $maxHexLen = $prepared->contentsHexMaxBytes * 2;

        if (strlen($hex) > $maxHexLen) {
            throw new \RuntimeException(
                "Chữ ký CMS ({$hex} hex chars) vượt quá khoảng đã dự trữ ({$maxHexLen} chars) — "
                . 'tăng PdfIncrementalSigner::RESERVED_CONTENT_BYTES.'
            );
        }

        $hexPadded = str_pad($hex, $maxHexLen, '0', STR_PAD_RIGHT);

        return substr_replace($prepared->pdfBytes, $hexPadded, $prepared->contentsHexStartOffset, $maxHexLen);
    }

    // Tính hash CHỈ trên phần ByteRange thật (loại trừ đúng vùng hex /Contents) — đây là dữ liệu
    // VNPT phải ký (thật ra là hash của DER(signedAttrs) chứa messageDigest này, xem CmsSignedDataBuilder).
    public function computeByteRangeHash(PreparedPdfSignature $prepared): string
    {
        [, $r1, $r2, $r3] = $prepared->byteRange;
        $part1 = substr($prepared->pdfBytes, 0, $r1);
        $part2 = substr($prepared->pdfBytes, $r2, $r3);

        return hash('sha256', $part1 . $part2);
    }

    // Số object của catalog (/Root) theo trailer MỚI NHẤT của file.
    private function rootObjectNumber(string $pdf): int
    {
        if (! preg_match_all('/\/Root\s+(\d+)\s+0\s+R/', $pdf, $m)) {
            throw new \RuntimeException('Không tìm thấy /Root trong PDF đã có chữ ký (có thể file dùng cross-reference stream).');
        }

        return (int) end($m[1]);
    }

    // Phiên bản MỚI NHẤT của một object (incremental update ghi lại cùng số object ở cuối file).
    private function latestRevision(string $pdf, int $objNum): array
    {
        if (! preg_match_all('/(?<!\d)' . $objNum . '\s+0\s+obj\s*(<<.*?>>)\s*endobj/s', $pdf, $m)) {
            throw new \RuntimeException("Không tìm thấy object {$objNum} trong PDF đã có chữ ký (có thể nằm trong object stream).");
        }

        return [$objNum, end($m[1])];
    }

    /** @return array{ref: ?int, dict: string}|null ref = số object riêng của AcroForm, null nếu AcroForm nằm trong catalog */
    private function existingAcroForm(string $pdf, string $catalogDict): ?array
    {
        if (preg_match('/\/AcroForm\s+(\d+)\s+0\s+R/', $catalogDict, $m)) {
            [, $dict] = $this->latestRevision($pdf, (int) $m[1]);

            return ['ref' => (int) $m[1], 'dict' => $dict];
        }

        if (preg_match('/\/AcroForm\s*(<<)/', $catalogDict, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[1][1];
            $depth = 0;
            for ($i = $start, $n = strlen($catalogDict); $i < $n - 1; $i++) {
                $two = substr($catalogDict, $i, 2);
                if ($two === '<<') {
                    $depth++;
                    $i++;
                } elseif ($two === '>>') {
                    $depth--;
                    $i++;
                    if ($depth === 0) {
                        return ['ref' => null, 'dict' => substr($catalogDict, $start, $i - $start + 1)];
                    }
                }
            }
        }

        return null;
    }

    private function addFieldToAcroForm(string $dict, int $widgetObjNum): string
    {
        if (preg_match('/\/Fields\s+\d+\s+0\s+R/', $dict)) {
            throw new \RuntimeException('AcroForm dùng danh sách trường gián tiếp — chưa hỗ trợ ký chồng.');
        }

        if (preg_match('/\/Fields\s*\[(.*?)\]/s', $dict, $m)) {
            $dict = preg_replace('/\/Fields\s*\[.*?\]/s', '/Fields [' . trim($m[1]) . " {$widgetObjNum} 0 R]", $dict, 1);
        } else {
            $dict = $this->upsertDictKey($dict, 'Fields', "[{$widgetObjNum} 0 R]");
        }

        if (! preg_match('/\/SigFlags\s+\d+/', $dict)) {
            $dict = $this->upsertDictKey($dict, 'SigFlags', '3');
        }

        return $dict;
    }

    private function findMaxObjectNumber(string $pdf): int
    {
        preg_match_all('/(\d+)\s+0\s+obj\b/', $pdf, $matches);

        return $matches[1] ? max(array_map('intval', $matches[1])) : 1;
    }

    // Tìm object theo /Type — trả về [objNum, nội dung dict bên trong << >>]. Với 'Page' phải loại
    // trừ '/Type /Pages' (cây trang gốc, khác hẳn 1 trang cụ thể).
    private function findObjectByType(string $pdf, string $type): array
    {
        preg_match_all('/(\d+)\s+0\s+obj\s*(<<.*?>>)\s*endobj/s', $pdf, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $dict = $m[2];
            if ($type === 'Page' && preg_match('/\/Type\s*\/Pages\b/', $dict)) {
                continue;
            }
            if (preg_match('/\/Type\s*\/' . preg_quote($type, '/') . '\b/', $dict)) {
                return [(int) $m[1], $dict];
            }
        }

        throw new \RuntimeException("Không tìm thấy object /Type /{$type} trong PDF gốc.");
    }

    private function upsertDictKey(string $dict, string $key, string $rawValue): string
    {
        // Bỏ dấu << >> ngoài cùng, thêm key mới vào cuối, đóng lại — đơn giản và an toàn vì PDF
        // dict không quan tâm thứ tự key.
        $inner = preg_replace('/^<<(.*)>>$/s', '$1', trim($dict));

        return "<<{$inner} /{$key} {$rawValue} >>";
    }

    private function appendToAnnotsArray(string $pageDict, int $widgetObjNum): string
    {
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $pageDict, $m)) {
            $newAnnots = trim($m[1]) . " {$widgetObjNum} 0 R";

            return preg_replace('/\/Annots\s*\[.*?\]/s', "/Annots [{$newAnnots}]", $pageDict, 1);
        }

        return $this->upsertDictKey($pageDict, 'Annots', "[{$widgetObjNum} 0 R]");
    }

    private function findLastStartXref(string $pdf): int
    {
        if (! preg_match_all('/startxref\s+(\d+)/', $pdf, $matches)) {
            throw new \RuntimeException('Không tìm thấy startxref trong PDF gốc.');
        }

        return (int) end($matches[1]);
    }

    private function buildXrefAndTrailer(array $offsets, int $size, int $rootObjNum, int $prevStartXref): string
    {
        $xref = "xref\n";
        foreach ($offsets as $objNum => $offset) {
            $xref .= "{$objNum} 1\n";
            $xref .= sprintf("%010d %05d n \n", $offset, 0);
        }

        $xref .= "trailer\n<< /Size {$size} /Root {$rootObjNum} 0 R /Prev {$prevStartXref} >>\n";

        return $xref;
    }

    private function findByteRangePlaceholderOffset(string $pdf, int $searchFrom, string $placeholder): int
    {
        $pos = strpos($pdf, $placeholder, $searchFrom);

        if ($pos === false) {
            throw new \RuntimeException('Không tìm thấy vị trí placeholder /ByteRange để patch giá trị thật.');
        }

        return $pos;
    }
}
