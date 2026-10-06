<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PartnerLegalDocument;
use App\Models\Province;
use App\Support\LegalDocumentFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Payment\App\Services\CccdScannerService;

// CCCD (citizen_id) đi đường riêng: chỉ đọc MÃ QR trên thẻ — xem citizenId().
// QUÉT giấy tờ pháp lý (ĐKKD / ANTT / PCCC) để GỢI Ý giá trị cho các ô nhập theo loại (App\Support\LegalDocumentFields).
// Chỉ gợi ý: không lưu gì, không tự duyệt — người nhập kiểm tra lại rồi mới nộp. Đọc chữ bằng OCR.space (cùng API key với quét CCCD),
// rồi dò theo BỐ CỤC + NHÃN trên giấy. Quy tắc dò được chỉnh theo 3 file mẫu thật (ĐKKD Cần Thơ 2025, GCN ANTT phường 2026, GCN thẩm duyệt PCCC 2019):
//  - Nơi cấp  = khối tiêu đề góc trái, đơn vị cấp dưới đứng trước: "PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ".
//  - Ngày cấp = ĐKKD lấy "Đăng ký lần đầu"; ANTT/PCCC CHỈ lấy dòng địa danh + ngày ở cuối giấy ("An Bình, ngày 10 tháng 06 năm 2026").
//               KHÔNG lấy ngày nằm trong câu ("... cấp ngày 06 tháng 11 năm 2025" là ngày của ĐKKD/CCCD được dẫn lại) — không thấy thì để trống.
//  - Ô nào không đọc được → null (để trống cho người nhập), không đoán.
class LegalDocumentScanService
{
    private const API_URL = 'https://api.ocr.space/parse/image';

    private const TIMEOUT_SECONDS = 45;

    // Từ khoá nhận diện loại giấy tờ (đã bỏ dấu, chữ thường) — để cảnh báo khi tệp không khớp loại đã chọn.
    private const TYPE_KEYWORDS = [
        'business_license' => ['dang ky doanh nghiep', 'ma so doanh nghiep', 'ma so ho kinh doanh', 'von dieu le', 'dang ky lan dau'],
        'security_order'   => ['an ninh, trat tu', 'an ninh trat tu', 'du dieu kien ve an ninh'],
        'fire_safety'      => ['phong chay', 'chua chay', 'pccc', 'tham duyet thiet ke', 'nghiem thu ve phong chay'],
    ];

    // Tên cơ quan viết chuẩn (OCR hay rụng dấu ở chữ in hoa): cụm đã bỏ dấu => cách viết đúng. Cụm dài xếp trước.
    private const AGENCY_PHRASES = [
        'phong canh sat pccc va cnch' => 'Phòng Cảnh sát PCCC và CNCH',
        'phong canh sat pccc & cnch'  => 'Phòng Cảnh sát PCCC & CNCH',
        'phong dang ky kinh doanh'    => 'Phòng Đăng ký kinh doanh',
        'phong tai chinh - ke hoach'  => 'Phòng Tài chính - Kế hoạch',
        'so ke hoach va dau tu'       => 'Sở Kế hoạch và Đầu tư',
        'uy ban nhan dan'             => 'Ủy ban nhân dân',
        'cong an thanh pho'           => 'Công an thành phố',
        'cong an phuong'              => 'Công an phường',
        'cong an huyen'               => 'Công an huyện',
        'cong an quan'                => 'Công an quận',
        'cong an tinh'                => 'Công an tỉnh',
        'cong an xa'                  => 'Công an xã',
        'so tai chinh'                => 'Sở Tài chính',
        'thanh pho'                   => 'Thành phố',
    ];

    // Tên tỉnh/thành để sửa dấu trong tên cơ quan cấp. Gồm cả tên TRƯỚC sáp nhập 2025 vì giấy tờ cũ (vd PCCC 2019) vẫn ghi tên cũ;
    // bảng provinces hiện hành được nối thêm lúc chạy.
    private const PROVINCES = [
        'An Giang', 'Bà Rịa - Vũng Tàu', 'Bắc Giang', 'Bắc Kạn', 'Bạc Liêu', 'Bắc Ninh', 'Bến Tre', 'Bình Định', 'Bình Dương', 'Bình Phước', 'Bình Thuận',
        'Cà Mau', 'Cần Thơ', 'Cao Bằng', 'Đà Nẵng', 'Đắk Lắk', 'Đắk Nông', 'Điện Biên', 'Đồng Nai', 'Đồng Tháp', 'Gia Lai', 'Hà Giang', 'Hà Nam', 'Hà Nội',
        'Hà Tĩnh', 'Hải Dương', 'Hải Phòng', 'Hậu Giang', 'Hòa Bình', 'Hồ Chí Minh', 'Huế', 'Hưng Yên', 'Khánh Hòa', 'Kiên Giang', 'Kon Tum', 'Lai Châu',
        'Lâm Đồng', 'Lạng Sơn', 'Lào Cai', 'Long An', 'Nam Định', 'Nghệ An', 'Ninh Bình', 'Ninh Thuận', 'Phú Thọ', 'Phú Yên', 'Quảng Bình', 'Quảng Nam',
        'Quảng Ngãi', 'Quảng Ninh', 'Quảng Trị', 'Sóc Trăng', 'Sơn La', 'Tây Ninh', 'Thái Bình', 'Thái Nguyên', 'Thanh Hóa', 'Thừa Thiên Huế', 'Tiền Giang',
        'Trà Vinh', 'Tuyên Quang', 'Vĩnh Long', 'Vĩnh Phúc', 'Yên Bái',
    ];

    /** @var array<int, string>|null tên tỉnh/thành viết chuẩn (không kèm "Tỉnh"/"Thành phố") */
    private static ?array $provinceNames = null;

    /** @var array<int, string> dòng chữ đã làm sạch */
    private array $lines = [];

    /** @var array<int, string> các dòng đó, bỏ dấu + chữ thường (cùng số ký tự để tra vị trí) */
    private array $folded = [];

    /** Vị trí dòng tiêu đề "GIẤY CHỨNG NHẬN ..." (khối phía trên là tiêu đề cơ quan + số). */
    private int $titleIndex = 0;

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
        if ($type === 'citizen_id') {
            return $this->citizenId($file);
        }

        return $this->suggest($type, $this->extractText($file));
    }

    /**
     * QUY TẮC NỘP CCCD dùng chung cho API đăng ký (đối tác), API admin và trang quản trị: tệp phải là ảnh đọc được mã QR,
     * và các ô lấy từ QR (LegalDocumentFields::CITIZEN_ID_QR_FIELDS) do QR quyết định — giá trị người nhập gửi cho các ô đó bị thay thế.
     *
     * @return array<string, ?string> các cột cccd_* lấy từ QR (không gồm cccd_issuer — QR không có nơi cấp)
     *
     * @throws ValidationException khi không đọc được mã QR (lỗi gắn vào $errorKey)
     */
    public function citizenIdValues(?UploadedFile $file, string $errorKey = 'file'): array
    {
        $scan = $file ? $this->citizenId($file) : null;
        if (! ($scan['qr'] ?? false)) {
            throw ValidationException::withMessages([$errorKey => $scan['warnings'] ?? ['CCCD phải có tệp ảnh chụp mặt có mã QR.']]);
        }

        return Arr::only($scan['fields'], array_map(fn (string $name) => LegalDocumentFields::key('citizen_id', $name), LegalDocumentFields::CITIZEN_ID_QR_FIELDS));
    }

    /**
     * CCCD: CHỈ đọc MÃ QR trên thẻ (không OCR — chữ trên ảnh dễ bị làm giả), dùng chung bộ giải mã với luồng đặt phòng.
     * Không đọc được QR → text_found = false, các ô null, warnings nêu lý do. Kết quả nhớ tạm theo nội dung tệp để lúc nộp không phải giải mã lại.
     *
     * @return array cùng cấu trúc với suggest(), thêm 'qr' => bool
     */
    public function citizenId(UploadedFile $file): array
    {
        $type = 'citizen_id';
        $fields = array_fill_keys(LegalDocumentFields::keys($type), null);
        $hint = null;

        if (is_file($file->getRealPath()) && str_starts_with((string) $file->getMimeType(), 'image/')) {
            $qr = Cache::remember('legal-cccd-qr:' . sha1_file($file->getRealPath()), now()->addMinutes(15), function () use ($file, &$hint) {
                $data = app(CccdScannerService::class)->scanQrImage($file->getRealPath());
                $hint = $data ? null : CccdScannerService::failureHint();

                // Không nhớ kết quả thất bại: khách chụp lại ảnh khác, hoặc hệ thống bận thì lần sau thử lại.
                return $data ?: false;
            });
            if ($qr === false) {
                Cache::forget('legal-cccd-qr:' . sha1_file($file->getRealPath()));
            }
        } else {
            $qr = false;
            $hint = 'CCCD phải là tệp ảnh (jpg, png, webp) chụp mặt có mã QR.';
        }

        if ($qr && preg_match('/^[0-9]{9}([0-9]{3})?$/', (string) ($qr['cccd'] ?? ''))) {
            $date = fn (?string $value) => preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', (string) $value, $m) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
            $fields = array_merge($fields, [
                'cccd_document_number' => $qr['cccd'],
                'cccd_full_name'       => filled($qr['full_name'] ?? null) ? $qr['full_name'] : null,
                'cccd_dob'             => $date($qr['dob'] ?? null),
                'cccd_gender'          => filled($qr['gender'] ?? null) ? $qr['gender'] : null,
                'cccd_address'         => filled($qr['address'] ?? null) ? $qr['address'] : null,
                'cccd_issued_at'       => $date($qr['issued_date'] ?? null),
            ]);
        } else {
            $qr = false;
        }

        return [
            'type'              => $type,
            'text_found'        => (bool) $qr,
            'qr'                => (bool) $qr,
            'detected_type'     => $qr ? $type : null,
            'type_matches'      => $qr ? true : null,
            'fields'            => $fields,
            'found'             => count(array_filter($fields, fn ($value) => filled($value))),
            'total'             => count($fields),
            'fire_safety_stage' => null,
            'warnings'          => $qr ? [] : ['Không đọc được mã QR trên CCCD. ' . ($hint ?? CccdScannerService::failureHint())],
        ];
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
            $missing = array_map(fn ($key) => LegalDocumentFields::for($type)[$key][0], array_keys(array_filter($fields, fn ($value) => blank($value))));
            $warnings[] = 'Chưa đọc được: ' . implode(', ', $missing) . '. Vui lòng kiểm tra giấy tờ và tự nhập các ô này (ô không có trên giấy thì để trống).';
        }

        return [
            'type'              => $type,
            'text_found'        => trim($text) !== '',
            'detected_type'     => $detected,
            'type_matches'      => $detected === null ? null : $detected === $type,
            'fields'            => $fields,
            'found'             => $found,
            'total'             => count($fields),
            'fire_safety_stage' => $type === 'fire_safety' ? LegalDocumentFields::fireSafetyStage($fields[LegalDocumentFields::key($type, 'document_number')] ?? null) : null,
            'warnings'          => $warnings,
        ];
    }

    /** @return array<string, ?string> mọi ô của loại giấy tờ theo TÊN CỘT RIÊNG (dkkd_* / antt_* / pccc_*); ô không đọc được = null */
    public function parse(string $type, string $text): array
    {
        $values = array_fill_keys(LegalDocumentFields::names($type), null);
        if ($values !== [] && trim($text) !== '') {
            $this->load($text);
            $values = array_merge($values, match ($type) {
                'business_license' => $this->businessLicense(),
                'security_order'   => $this->securityOrder(),
                'fire_safety'      => $this->fireSafety(),
                default            => [],
            });
        }

        $fields = [];
        foreach ($values as $name => $value) {
            $fields[LegalDocumentFields::key($type, $name)] = $this->clean($name, $value);
        }

        return $fields;
    }

    /** Loại giấy tờ mà nội dung giống nhất (theo từ khoá); không đủ dấu hiệu → null. */
    public function detectType(string $text): ?string
    {
        $folded = $this->fold($text);
        $scores = array_map(fn (array $keywords) => array_sum(array_map(fn ($keyword) => substr_count($folded, $keyword), $keywords)), self::TYPE_KEYWORDS);
        arsort($scores);

        return reset($scores) > 0 ? array_key_first($scores) : null;
    }

    // ───────────────────────── Từng loại giấy tờ ─────────────────────────

    private function businessLicense(): array
    {
        // Người đại diện + số định danh: lấy trong MỤC "Người đại diện theo pháp luật" (mục chủ sở hữu phía trên có cùng nhãn).
        $section = $this->firstLine(['nguoi dai dien theo phap luat', 'dai dien ho kinh doanh', 'chu ho kinh doanh']) ?? 0;
        $number = (string) $this->after(['ma so doanh nghiep', 'ma so ho kinh doanh', 'ma so dang ky ho kinh doanh', 'ma so chi nhanh', 'ma so thue']);

        return [
            // 10 số (hoặc 10-3 cho đơn vị phụ thuộc); hộ kinh doanh có thể kèm chữ → giữ cụm đầu tiên.
            'document_number' => preg_match('/\b\d{10}(-\d{3})?\b/', $number, $m) ? $m[0] : (preg_match('/[0-9A-Z][0-9A-Z.\-]{5,}/u', mb_strtoupper($number), $m) ? $m[0] : null),
            'issued_at'       => $this->dateIn($this->after(['dang ky lan dau'])) ?? $this->signatureDate(),
            'issuer'          => $this->issuer(),
            // Mục "2. Địa chỉ trụ sở chính": giá trị nằm ở (các) dòng ngay dưới tiêu đề mục.
            'business_address'         => $this->after(['dia chi tru so chinh', 'dia chi tru so', 'dia diem kinh doanh', 'dia chi kinh doanh', 'dia chi chi nhanh'], multiline: true),
            'business_lines'           => $this->after(['nganh, nghe kinh doanh', 'nganh nghe kinh doanh'], multiline: true),
            'legal_representative'     => $this->after(['ho, chu dem va ten', 'ho va ten', 'ho ten'], $section, stops: ['gioi tinh', 'ngay, thang', 'quoc tich']),
            'representative_id_number' => $this->after(['so dinh danh ca nhan', 'so giay to phap ly cua ca nhan', 'so giay to phap ly', 'so the can cuoc', 'so can cuoc', 'so cccd', 'so cmnd'], $section),
            'phone'                    => $this->after(['dien thoai'], stops: ['so fax', 'fax', 'thu dien tu', 'email', 'website']),
        ];
    }

    private function securityOrder(): array
    {
        return [
            'document_number' => $this->headerNumber(),
            'issued_at'       => $this->signatureDate(),
            'issuer'          => $this->issuer(),
            // Tên cơ sở: "... hồ sơ của cơ sở kinh doanh: <tên>" (thường tràn 2 dòng); mẫu cũ ghi "Tên cơ sở kinh doanh: ...".
            'business_name'      => $this->after(['ho so cua co so kinh doanh', 'ten co so kinh doanh', 'ten co so'], multiline: true) ?? $this->certifiedName(),
            'business_address'   => $this->after(['dia chi co so kinh doanh', 'dia diem kinh doanh', 'dia chi kinh doanh', 'dia chi tru so', 'dia chi'], $this->titleIndex, multiline: true),
            // "Họ và tên người chịu trách nhiệm về ANTT của cơ sở kinh doanh (ông, bà): Nguyễn An Khoa; Quốc tịch: ..." — nhãn tràn 2 dòng.
            'responsible_person' => $this->flatAfter('/nguoi (chiu trach nhiem|dung ten)[^:]{0,110}:\s*(ho( va)? ten\s*:\s*)?/', stops: [';', 'quoc tich', 'ngay, thang', 'sinh ngay', 'chuc danh', 'so cccd', 'so cmnd', 'so can cuoc', 'so dinh danh', 'so the can cuoc']),
            'responsible_id_number' => $this->after(['so can cuoc cong dan', 'can cuoc cong dan', 'so dinh danh ca nhan', 'so the can cuoc', 'so can cuoc', 'so cccd', 'so cmnd', 'cccd', 'cmnd']),
        ];
    }

    private function fireSafety(): array
    {
        return [
            'document_number'      => $this->headerNumber(),
            'issued_at'            => $this->signatureDate(),
            'issuer'               => $this->issuer(),
            'investor'             => $this->after(['chu dau tu/chu phuong tien', 'chu dau tu/ chu phuong tien', 'chu dau tu', 'chu phuong tien']),
            'representative'       => $this->after(['nguoi dai dien'], stops: ['chuc danh', 'chuc vu']),
            'representative_title' => $this->after(['chuc danh', 'chuc vu']),
            'site_address'         => $this->after(['dia diem xay dung', 'dia diem kinh doanh', 'dia diem'], multiline: true),
        ];
    }

    // ───────────────────────── Công cụ dò ─────────────────────────

    /** Làm sạch chữ OCR thành các dòng: bỏ dãy chấm kẻ dòng, bỏ quốc hiệu/tiêu ngữ (cột phải của tiêu đề hay dính vào tên cơ quan). */
    private function load(string $text): void
    {
        $this->lines = $this->folded = [];
        foreach (preg_split('/\R/u', $text) as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', preg_replace('/\.{2,}|…+/u', ' ', $raw)));
            $fold = $this->fold($line);
            if (($pos = strpos($fold, 'cong hoa xa h')) !== false && mb_strlen($fold) === mb_strlen($line)) {
                $line = trim(mb_substr($line, 0, $pos));
                $fold = $this->fold($line);
            }
            if ($line === '' || str_starts_with($fold, 'doc lap') || str_starts_with($fold, 'cong hoa xa h')) {
                continue;
            }
            $this->lines[] = $line;
            $this->folded[] = $fold;
        }

        $this->titleIndex = $this->firstLine(['giay chung nhan', 'bien ban', 'van ban']) ?? min(3, count($this->lines));
    }

    /**
     * Giá trị đứng SAU nhãn: phần sau dấu ":" đi liền nhãn; nhãn đứng riêng một dòng (tiêu đề mục) thì lấy dòng dưới.
     * $multiline: nối thêm (tối đa 2) dòng tràn xuống — dừng khi gặp dòng là một nhãn/mục khác hoặc giá trị đã kết thúc bằng dấu chấm.
     * $stops: cắt giá trị trước các cụm này (nhiều ô nằm chung một dòng: "Họ tên: X  Giới tính: Nam").
     */
    private function after(array $labels, int $from = 0, bool $multiline = false, array $stops = []): ?string
    {
        foreach ($labels as $label) {
            for ($i = $from; $i < count($this->lines); $i++) {
                $pos = strpos($this->folded[$i], $label);
                if ($pos === false) {
                    continue;
                }

                $rest = $this->tail($i, $pos + strlen($label));
                // Dấu ":" ngay sau nhãn (cho phép vài chữ chen giữa, vd "là Ông/Bà:", "(nếu có):").
                if (($colon = mb_strpos($rest, ':')) !== false && $colon <= 45) {
                    $rest = mb_substr($rest, $colon + 1);
                }
                $value = trim($rest, " \t:.,;-–");
                $next = $i + 1;
                if ($value === '') {
                    if (! isset($this->lines[$next]) || $this->looksLikeLabel($next)) {
                        continue;
                    }
                    $value = $this->lines[$next++];
                }
                for ($extra = 0; $multiline && $extra < 2 && isset($this->lines[$next]) && ! $this->looksLikeLabel($next) && ! str_ends_with(rtrim($value), '.'); $extra++) {
                    $value .= ' ' . $this->lines[$next++];
                }

                return $this->cut($value, $stops);
            }
        }

        return null;
    }

    /** Như after() nhưng nhãn có thể tràn sang dòng sau: ghép 3 dòng thành một rồi dò bằng regex (trên chữ đã bỏ dấu). */
    private function flatAfter(string $pattern, array $stops = []): ?string
    {
        for ($i = $this->titleIndex; $i < count($this->lines); $i++) {
            $flat = implode(' ', array_slice($this->lines, $i, 3));
            $fold = $this->fold($flat);
            if (mb_strlen($fold) !== mb_strlen($flat) || ! preg_match($pattern, $fold, $m, PREG_OFFSET_CAPTURE) || $m[0][1] > mb_strlen($this->lines[$i])) {
                continue;
            }
            $value = $this->cut(mb_substr($flat, $m[0][1] + strlen($m[0][0])), $stops);
            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /** Số giấy ở khối tiêu đề: "Số: 31/GCN". OCR hay tách dòng / rụng dấu "/" ("Số:" ↵ "82 TD-PCCC") → ghép lại "82/TD-PCCC". */
    private function headerNumber(): ?string
    {
        for ($i = 0; $i < min(count($this->lines), $this->titleIndex + 1); $i++) {
            if (! preg_match('/^so\s*[:.]\s*(.*)$/', $this->folded[$i], $m)) {
                continue;
            }
            $value = trim($this->tail($i, strlen($this->folded[$i]) - strlen($m[1])));
            if ($value === '' && isset($this->lines[$i + 1]) && preg_match('/^\d/', $this->lines[$i + 1])) {
                $value = $this->lines[$i + 1];
            }
            $value = preg_replace('/\s*\/\s*/', '/', trim($value));
            $value = preg_replace('/^(\d+)\s+(?=[A-Za-zĐđ])/u', '$1/', $value);

            return $value !== '' && preg_match('/\d/', $value) ? (preg_split('/\s/', $value)[0] ?? $value) : null;
        }

        return null;
    }

    /**
     * Ngày ký/cấp: dòng CHỈ gồm địa danh + ngày ở cuối giấy ("An Bình, ngày 10 tháng 06 năm 2026"; OCR có thể rụng chữ "ngày").
     * Ngày nằm giữa câu văn ("... cấp ngày 06 tháng 11 năm 2025, cơ quan cấp ...") là ngày của giấy tờ KHÁC được dẫn lại → bỏ qua.
     */
    private function signatureDate(): ?string
    {
        // Dò từ cuối giấy lên; mẫu cũ đặt dòng địa danh + ngày ngay dưới số ở khối tiêu đề nên xét hết các dòng.
        for ($i = count($this->lines) - 1; $i >= 0; $i--) {
            if (str_contains($this->folded[$i], 'cap ngay') || str_contains($this->folded[$i], ':')) {
                continue;
            }
            if (preg_match('/^\W*(?:[a-z][a-z .\-]{0,40}?[,.]?\s*)?(?:ngay\s*)?(\d{1,2})\s*thang\s*(\d{1,2})\s*nam\s*(\d{4})\d?\W*$/', $this->folded[$i], $m)) {
                return $this->isoDate((int) $m[1], (int) $m[2], (int) $m[3]);
            }
        }

        return null;
    }

    /** Ngày trong một đoạn chữ ("ngày 28 tháng 09 năm 2021" hoặc 28/09/2021) → YYYY-MM-DD. */
    private function dateIn(?string $text): ?string
    {
        $fold = $this->fold((string) $text);
        if (preg_match('/(\d{1,2})\s*thang\s*(\d{1,2})\s*nam\s*(\d{4})/', $fold, $m) || preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $fold, $m)) {
            return $this->isoDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    private function isoDate(int $day, int $month, int $year): ?string
    {
        return checkdate($month, $day, $year) && $year >= 1990 && $year <= (int) date('Y') + 1 ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * Nơi cấp/Cơ quan cấp từ khối tiêu đề góc trái, đơn vị cấp dưới đứng trước:
     *  "SỞ TÀI CHÍNH / THÀNH PHỐ CẦN THƠ / PHÒNG ĐĂNG KÝ KINH DOANH" → "PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ"
     *  "CÔNG AN THÀNH PHỐ CẦN THƠ / CÔNG AN PHƯỜNG AN BÌNH"          → "CÔNG AN PHƯỜNG AN BÌNH, THÀNH PHỐ CẦN THƠ"
     */
    private function issuer(): ?string
    {
        $header = [];
        for ($i = 0; $i < min($this->titleIndex, 6); $i++) {
            // Bỏ dòng số ("Số: 31/GCN", "82 TD-PCCC"), dòng địa danh + ngày ("Cần Thơ, ngày 14 tháng 6 năm 2022") và rác OCR quá ngắn.
            if (preg_match('/^so\s*[:.]|^so\s+\d|^\W*\d|thang\s*\d{1,2}\s*nam\s*\d{4}/', $this->folded[$i]) || mb_strlen($this->lines[$i]) < 6) {
                continue;
            }
            $header[] = $i;
        }
        if ($header === []) {
            return null;
        }

        $child = array_pop($header);
        $childFold = $this->folded[$child];

        // Thân giấy thường in lại đầy đủ tên cơ quan (rõ dấu hơn tiêu đề): "CÔNG AN PHƯỜNG AN BÌNH, THÀNH PHỐ CẦN THƠ".
        for ($i = $this->titleIndex; $i < count($this->lines); $i++) {
            if (str_starts_with($this->folded[$i], $childFold . ',') && ! str_contains($this->lines[$i], ':')) {
                return $this->canonical($this->lines[$i]);
            }
        }

        $parts = [$this->lines[$child]];
        foreach ($header as $i) {
            // Cấp trên cùng ngành công an: "CÔNG AN THÀNH PHỐ CẦN THƠ" → "THÀNH PHỐ CẦN THƠ" khi cấp dưới đã là "CÔNG AN ...".
            $parts[] = str_starts_with($childFold, 'cong an ') && str_starts_with($this->folded[$i], 'cong an ') ? mb_substr($this->lines[$i], 8) : $this->lines[$i];
        }

        return $this->canonical(implode(', ', array_map(fn ($part) => trim($part, " \t,.;:=-–"), $parts)));
    }

    /** ANTT: tên cơ sở in ở khối "CHỨNG NHẬN" (dùng khi không có nhãn tên cơ sở). */
    private function certifiedName(): ?string
    {
        foreach ($this->folded as $i => $line) {
            if ($i <= $this->titleIndex || ! preg_match('/^chung nh[a-z]n\W*$/', $line)) {
                continue;
            }
            $name = [];
            for ($j = $i + 1; $j < count($this->lines) && count($name) < 3 && ! str_starts_with($this->folded[$j], 'du dieu kien'); $j++) {
                $name[] = $this->lines[$j];
            }

            return $name === [] ? null : implode(' ', $name);
        }

        return null;
    }

    /** Sửa tên cơ quan và tên tỉnh/thành về cách viết chuẩn (OCR chữ in hoa hay rụng dấu: "THÀNH PHÔ CÂN THƠ" → "THÀNH PHỐ CẦN THƠ"). */
    private function canonical(string $text): string
    {
        $replacements = self::AGENCY_PHRASES;
        foreach (self::provinceNames() as $name) {
            $replacements[$this->fold($name)] = $name;
        }

        foreach ($replacements as $needle => $proper) {
            $fold = $this->fold($text);
            if (mb_strlen($fold) !== mb_strlen($text) || ! preg_match('/(?<![a-z])' . preg_quote($needle, '/') . '(?![a-z])/', $fold, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $at = (int) $m[0][1];
            $segment = mb_substr($text, $at, strlen($needle));
            $text = mb_substr($text, 0, $at) . (mb_strtoupper($segment) === $segment ? mb_strtoupper($proper) : $proper) . mb_substr($text, $at + strlen($needle));
        }

        return $text;
    }

    /** @return array<int, string> */
    private static function provinceNames(): array
    {
        if (self::$provinceNames === null) {
            try {
                $current = Province::query()->pluck('name')->map(fn ($name) => trim((string) preg_replace('/^(Thành phố|Tỉnh|TP\.?)\s+/iu', '', (string) $name)))->all();
            } catch (\Throwable) {
                $current = [];
            }
            // Tên dài xét trước ("Thừa Thiên Huế" trước "Huế").
            self::$provinceNames = collect([...self::PROVINCES, ...$current])->filter(fn ($name) => mb_strlen($name) >= 3)->unique()
                ->sortByDesc(fn ($name) => mb_strlen($name))->values()->all();
        }

        return self::$provinceNames;
    }

    /** Phần chữ gốc của dòng $i kể từ vị trí $offset (vị trí tính trên dòng đã bỏ dấu — hai dòng cùng số ký tự). */
    private function tail(int $i, int $offset): string
    {
        return mb_strlen($this->folded[$i]) === mb_strlen($this->lines[$i]) ? mb_substr($this->lines[$i], $offset) : (string) Str::after($this->lines[$i], ':');
    }

    /** Dòng này có phải một nhãn/mục khác không ("Điện thoại: ...", "3. Vốn điều lệ", dòng kết thúc bằng ":"). */
    private function looksLikeLabel(int $i): bool
    {
        // Dòng tràn của một giá trị (địa chỉ, tên) không chứa ":" — có ":" là đã sang ô khác, kể cả nhãn dài.
        return (bool) preg_match('/:|^\W*\d+\.\s|^\*/u', $this->lines[$i]);
    }

    private function cut(string $value, array $stops): ?string
    {
        $fold = $this->fold($value);
        if (mb_strlen($fold) === mb_strlen($value)) {
            foreach ($stops as $stop) {
                if (($pos = strpos($fold, $stop)) !== false) {
                    $value = mb_substr($value, 0, $pos);
                    $fold = substr($fold, 0, $pos);
                }
            }
        }
        $value = trim($value, " \t:.,;-–*");

        return $value === '' ? null : $value;
    }

    private function clean(string $name, ?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        $value = trim((string) $value, " \t:.,;-–*");

        return match (true) {
            str_ends_with($name, '_id_number') => preg_match('/\b(\d{12}|\d{9})\b/', $value, $m) ? $m[1] : null,
            $name === 'phone'                  => preg_match('/^(0|\+84)\d{9}$/', $digits = (string) preg_replace('/[^\d+]/', '', $value)) ? $digits : null,
            default                            => $value === '' ? null : Str::limit($value, 480, ''),
        };
    }

    private function firstLine(array $needles): ?int
    {
        foreach ($needles as $needle) {
            foreach ($this->folded as $i => $line) {
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
