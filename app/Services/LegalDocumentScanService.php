<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PartnerLegalDocument;
use App\Support\LegalDocumentFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// QUÉT giấy tờ pháp lý (ĐKKD / ANTT / PCCC) để GỢI Ý giá trị cho các ô nhập theo loại (App\Support\LegalDocumentFields).
// Chỉ gợi ý: không lưu gì, không tự duyệt — người nhập kiểm tra lại rồi mới nộp. Đọc chữ bằng OCR.space (cùng API key với quét CCCD),
// rồi dò theo NHÃN trên giấy ("Mã số doanh nghiệp:", "Người chịu trách nhiệm...:"). Giấy tờ không có mẫu cố định như CCCD nên có ô không đọc được → trả null.
class LegalDocumentScanService
{
    private const API_URL = 'https://api.ocr.space/parse/image';

    private const TIMEOUT_SECONDS = 45;

    // Từ khoá nhận diện loại giấy tờ (đã bỏ dấu, chữ thường) — để cảnh báo khi tệp không khớp loại đã chọn.
    private const TYPE_KEYWORDS = [
        'business_license' => ['dang ky doanh nghiep', 'dang ky ho kinh doanh', 'dang ky kinh doanh', 'ma so doanh nghiep', 'ma so ho kinh doanh'],
        'security_order'   => ['an ninh, trat tu', 'an ninh trat tu', 'du dieu kien ve an ninh'],
        'fire_safety'      => ['phong chay', 'chua chay', 'pccc', 'tham duyet thiet ke', 'nghiem thu ve phong chay'],
    ];

    // Nhãn của từng ô trên giấy (đã bỏ dấu, chữ thường), ưu tiên từ trên xuống.
    private const LABELS = [
        'business_license' => [
            'document_number'          => ['ma so doanh nghiep', 'ma so ho kinh doanh', 'ma so dang ky ho kinh doanh', 'ma so chi nhanh', 'ma so thue', 'ma so'],
            'business_address'         => ['dia chi tru so chinh', 'dia chi tru so', 'dia diem kinh doanh', 'dia chi kinh doanh', 'dia chi chi nhanh'],
            'business_lines'           => ['nganh, nghe kinh doanh', 'nganh nghe kinh doanh', 'nganh, nghe', 'nganh nghe'],
            'representative_id_number' => ['so dinh danh ca nhan', 'so giay to phap ly cua ca nhan', 'so giay to phap ly', 'so giay chung thuc ca nhan', 'so the can cuoc', 'so can cuoc', 'so cccd', 'so cmnd'],
            'phone'                    => ['dien thoai', 'so dien thoai'],
        ],
        'security_order' => [
            'business_name'         => ['ten co so kinh doanh', 'ten co so', 'co so kinh doanh'],
            'business_address'      => ['dia chi co so kinh doanh', 'dia chi kinh doanh', 'dia diem kinh doanh', 'dia chi'],
            'responsible_person'    => ['nguoi chiu trach nhiem ve an ninh, trat tu', 'nguoi chiu trach nhiem ve an ninh trat tu', 'nguoi chiu trach nhiem', 'ho va ten nguoi dung ten', 'nguoi dung ten'],
            'responsible_id_number' => ['so dinh danh ca nhan', 'so the can cuoc', 'so can cuoc', 'so cccd', 'so cmnd', 'cccd', 'cmnd'],
        ],
        'fire_safety' => [
            'investor'             => ['chu dau tu/chu phuong tien', 'chu dau tu', 'chu phuong tien', 'ten co so', 'don vi de nghi'],
            'representative'       => ['nguoi dai dien theo phap luat', 'nguoi dai dien', 'dai dien la ong/ba', 'dai dien'],
            'representative_title' => ['chuc danh', 'chuc vu'],
            'site_address'         => ['dia diem xay dung', 'dia diem kinh doanh', 'dia diem', 'dia chi'],
        ],
    ];

    // Dòng tên cơ quan cấp (đã bỏ dấu), theo loại.
    private const ISSUER_HINTS = [
        'business_license' => ['phong dang ky kinh doanh', 'phong tai chinh', 'so ke hoach va dau tu', 'so tai chinh', 'uy ban nhan dan'],
        'security_order'   => ['phong canh sat', 'cong an'],
        'fire_safety'      => ['phong canh sat pccc', 'canh sat phong chay', 'phong canh sat', 'cong an'],
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.ocr_space.api_key'));
    }

    /**
     * @return array{type: string, text_found: bool, detected_type: ?string, type_matches: ?bool, fields: array<string, ?string>, found: int, total: int,
     *               fire_safety_stage: ?array, warnings: array<int, string>}
     */
    public function scan(UploadedFile $file, string $type): array
    {
        return $this->suggest($type, $this->extractText($file));
    }

    /** Gợi ý giá trị từ đoạn chữ đã đọc được (tách riêng để kiểm thử không cần gọi OCR). */
    public function suggest(string $type, string $text): array
    {
        $fields = $this->parse($type, $text);
        $detected = $this->detectType($text);
        $found = count(array_filter($fields, fn ($value) => filled($value)));
        $warnings = [];

        if (trim($text) === '') {
            $warnings[] = 'Không đọc được chữ trong tệp. Vui lòng dùng ảnh/PDF rõ nét hơn hoặc tự nhập.';
        } elseif ($detected !== null && $detected !== $type) {
            $warnings[] = 'Nội dung tệp giống "' . PartnerLegalDocument::TYPES[$detected] . '" hơn loại đã chọn. Vui lòng kiểm tra lại loại giấy tờ.';
        }
        if (trim($text) !== '' && $found < count($fields)) {
            $warnings[] = 'Một số ô chưa đọc được — vui lòng kiểm tra và nhập bổ sung.';
        }

        return [
            'type'              => $type,
            'text_found'        => trim($text) !== '',
            'detected_type'     => $detected,
            'type_matches'      => $detected === null ? null : $detected === $type,
            'fields'            => $fields,
            'found'             => $found,
            'total'             => count($fields),
            'fire_safety_stage' => $type === 'fire_safety' ? LegalDocumentFields::fireSafetyStage($fields['document_number'] ?? null) : null,
            'warnings'          => $warnings,
        ];
    }

    /** @return array<string, ?string> mọi ô của loại giấy tờ; ô không đọc được = null */
    public function parse(string $type, string $text): array
    {
        $fields = array_fill_keys(array_keys(LegalDocumentFields::for($type)), null);
        if ($fields === [] || trim($text) === '') {
            return $fields;
        }

        $lines = array_values(array_filter(array_map(fn ($line) => trim(preg_replace('/\s+/u', ' ', $line)), preg_split('/\R/u', $text)), fn ($line) => $line !== ''));
        $folded = array_map(fn ($line) => $this->fold($line), $lines);

        foreach (self::LABELS[$type] ?? [] as $key => $labels) {
            $fields[$key] = $this->valueAfterLabel($lines, $folded, $labels);
        }

        $fields['issued_at'] = $this->issuedAt($type, $folded);
        $fields['issuer'] = $this->issuer($type, $lines, $folded);

        match ($type) {
            'business_license' => $this->refineBusinessLicense($fields, $lines, $folded),
            'security_order'   => $this->refineSecurityOrder($fields, $lines, $folded),
            'fire_safety'      => $fields['document_number'] = $this->numberLine($lines, $folded, '/\S*(PCCC|TD|NT|GXN|BB)\S*/iu'),
            default            => null,
        };

        foreach (['representative_id_number', 'responsible_id_number'] as $key) {
            if (array_key_exists($key, $fields)) {
                $fields[$key] = preg_match('/\b(\d{12}|\d{9})\b/', (string) $fields[$key], $m) ? $m[1] : null;
            }
        }
        if (array_key_exists('phone', $fields)) {
            $digits = preg_replace('/[^\d+]/', '', (string) $fields['phone']);
            $fields['phone'] = preg_match('/^(0|\+84)\d{9}$/', $digits) ? $digits : null;
        }

        return array_map(fn ($value) => filled($value) ? Str::limit(trim((string) $value, " \t:.-–"), 480, '') : null, $fields);
    }

    /** Loại giấy tờ mà nội dung giống nhất (theo từ khoá); không đủ dấu hiệu → null. */
    public function detectType(string $text): ?string
    {
        $folded = $this->fold($text);
        $scores = array_map(fn (array $keywords) => array_sum(array_map(fn ($keyword) => substr_count($folded, $keyword), $keywords)), self::TYPE_KEYWORDS);
        arsort($scores);

        return reset($scores) > 0 ? array_key_first($scores) : null;
    }

    private function refineBusinessLicense(array &$fields, array $lines, array $folded): void
    {
        // Mã số: 10 số (hoặc 10-3 cho đơn vị phụ thuộc); hộ kinh doanh có thể kèm chữ → giữ nguyên cụm đầu tiên.
        $number = (string) $fields['document_number'];
        $fields['document_number'] = preg_match('/\b\d{10}(-\d{3})?\b/', $number, $m) ? $m[0]
            : (preg_match('/[0-9A-Z][0-9A-Z.\-]{5,}/u', mb_strtoupper($number), $m) ? $m[0] : null);

        // Người đại diện: dòng "Họ và tên" đầu tiên SAU mục người đại diện / chủ hộ.
        $from = $this->firstLine($folded, ['nguoi dai dien theo phap luat', 'dai dien ho kinh doanh', 'chu ho kinh doanh', 'nguoi dai dien']);
        $fields['legal_representative'] = $this->valueAfterLabel($lines, $folded, ['ho va ten', 'ho ten'], $from ?? 0)
            ?? ($from !== null ? $this->afterColon($lines[$from]) : null);
    }

    private function refineSecurityOrder(array &$fields, array $lines, array $folded): void
    {
        $fields['document_number'] = $this->numberLine($lines, $folded, '/\S*GCN\S*|\d+\s*\/\s*\S+/iu');

        // "Người chịu trách nhiệm về ANTT của cơ sở kinh doanh:" thường xuống dòng "Họ và tên: ..." → lấy họ tên sau mục đó.
        $from = $this->firstLine($folded, ['nguoi chiu trach nhiem', 'nguoi dung ten']);
        if ($from !== null) {
            $fields['responsible_person'] = $this->valueAfterLabel($lines, $folded, ['ho va ten', 'ho ten'], $from) ?? $fields['responsible_person'];
        }
    }

    /** Dòng "Số: ..." đầu tiên; ưu tiên cụm khớp $prefer (vd có "PCCC", "GCN"). */
    private function numberLine(array $lines, array $folded, string $prefer): ?string
    {
        foreach ($folded as $i => $line) {
            if (! preg_match('/^so\s*[:.]/', $line)) {
                continue;
            }
            $value = (string) $this->afterColon($lines[$i]);
            if ($value === '') {
                continue;
            }

            return preg_match($prefer, $value, $m) ? trim($m[0]) : (preg_split('/\s{2,}|\s+ngay\s+/iu', $value)[0] ?? $value);
        }

        return null;
    }

    /** Ngày cấp: ĐKKD ưu tiên "Đăng ký lần đầu"; còn lại lấy ngày ở dòng địa danh ("..., ngày dd tháng mm năm yyyy"). Trả YYYY-MM-DD. */
    private function issuedAt(string $type, array $folded): ?string
    {
        $candidates = $folded;
        if ($type === 'business_license') {
            $first = $this->firstLine($folded, ['dang ky lan dau']);
            if ($first !== null) {
                $candidates = array_slice($folded, $first, 2);
            }
        }

        foreach ($candidates as $line) {
            if (preg_match('/ngay\s*(\d{1,2})\s*thang\s*(\d{1,2})\s*nam\s*(\d{4})/', $line, $m) || preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $line, $m)) {
                if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                }
            }
        }

        return null;
    }

    /** Cơ quan cấp: dòng tiêu đề cơ quan (kèm dòng cơ quan cấp trên ngay phía trên, nếu có). */
    private function issuer(string $type, array $lines, array $folded): ?string
    {
        $index = $this->firstLine($folded, self::ISSUER_HINTS[$type] ?? []);
        if ($index === null) {
            return null;
        }
        $parent = $index > 0 && preg_match('/^(so |cong an|uy ban|ubnd)/', $folded[$index - 1]) && ! str_contains($folded[$index - 1], ':') ? $lines[$index - 1] . ' — ' : '';

        return $parent . ($this->afterColon($lines[$index]) ?: $lines[$index]);
    }

    /** Giá trị sau nhãn: phần sau dấu ":" trên cùng dòng, không có thì lấy dòng kế tiếp (không phải một nhãn khác). */
    private function valueAfterLabel(array $lines, array $folded, array $labels, int $from = 0): ?string
    {
        foreach ($labels as $label) {
            for ($i = $from; $i < count($folded); $i++) {
                $pos = strpos($folded[$i], $label);
                if ($pos === false) {
                    continue;
                }
                $value = $this->afterColon($lines[$i]);
                if (filled($value)) {
                    return $value;
                }
                // Không có dấu ":" nhưng giá trị nằm ngay sau nhãn trên cùng dòng ("Mã số doanh nghiệp 0312345678").
                $rest = trim(mb_substr($lines[$i], $pos + strlen($label)), " \t:.-–");
                if ($rest !== '' && ! str_contains($lines[$i], ':')) {
                    return $rest;
                }
                // Nhãn đứng riêng một dòng → giá trị ở dòng dưới, miễn dòng đó không phải một nhãn khác ("xxx: yyy" hoặc kết thúc bằng ":").
                $next = $lines[$i + 1] ?? null;
                if ($rest === '' && $next !== null && ! preg_match('/:\s*$/u', $next) && ! preg_match('/^[^:]{0,40}:\s*\S/u', $next)) {
                    return $next;
                }
            }
        }

        return null;
    }

    private function afterColon(string $line): ?string
    {
        $pos = mb_strpos($line, ':');

        return $pos === false ? null : (trim(mb_substr($line, $pos + 1)) ?: null);
    }

    private function firstLine(array $folded, array $needles): ?int
    {
        foreach ($needles as $needle) {
            foreach ($folded as $i => $line) {
                if (str_contains($line, $needle)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function fold(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }

    /** Đọc chữ trong ảnh/PDF qua OCR.space; ghép mọi trang. Lỗi/không cấu hình → chuỗi rỗng. */
    protected function extractText(UploadedFile $file): string
    {
        if (! $this->isConfigured()) {
            return '';
        }

        try {
            $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg'));
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->attach('file', (string) file_get_contents($file->getRealPath()), 'document.' . $extension)
                ->post(self::API_URL, [
                    'apikey'            => config('services.ocr_space.api_key'),
                    'language'          => 'auto',
                    'isOverlayRequired' => 'false',
                    'detectOrientation' => 'true',
                    'scale'             => 'true',
                    'isTable'           => 'false',
                    'filetype'          => $extension === 'jpeg' ? 'JPG' : strtoupper($extension),
                    'OCREngine'         => '2',
                ]);

            $result = $response->json();
            if (! $response->successful() || ! is_array($result) || empty($result['ParsedResults'])) {
                Log::warning('[LegalDocumentScan] OCR.space không trả kết quả', ['status' => $response->status(), 'error' => $result['ErrorMessage'] ?? null]);

                return '';
            }

            return trim(implode("\n", array_map(fn ($page) => (string) ($page['ParsedText'] ?? ''), $result['ParsedResults'])));
        } catch (\Throwable $e) {
            Log::warning('[LegalDocumentScan] Quét thất bại', ['error' => $e->getMessage()]);

            return '';
        }
    }
}
