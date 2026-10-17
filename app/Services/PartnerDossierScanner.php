<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LegalDocumentFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * QUÉT HỒ SƠ ĐỂ TẠO ĐỐI TÁC: nhận bộ giấy tờ (GPKD, CCCD hai mặt, giấy An ninh trật tự), đọc từng giấy bằng
 * LegalDocumentScanService (OCR.space cho GPKD / ANTT, mã QR cho CCCD), rồi gộp thành GỢI Ý cho các ô của hồ sơ đối tác
 * kèm đối chiếu chéo giữa các giấy. Chỉ gợi ý: không lưu gì, người nhập kiểm tra lại rồi mới lưu.
 *
 * Nguồn ưu tiên cho từng ô (giấy đứng trước thắng): CCCD (QR, chính xác nhất) → GPKD → ANTT.
 */
class PartnerDossierScanner
{
    /** Nhãn ô của hồ sơ đối tác — dùng hiển thị "đã tự điền". */
    public const LABELS = [
        'legal_name'                     => 'Tên pháp lý',
        'tax_code'                       => 'Mã số thuế',
        'address'                        => 'Địa chỉ',
        'phone'                          => 'Số điện thoại',
        'representative_name'            => 'Họ tên người đại diện',
        'representative_dob'             => 'Ngày sinh người đại diện',
        'representative_id_number'       => 'Số CCCD người đại diện',
        'representative_id_issued_at'    => 'Ngày cấp CCCD',
        'representative_id_issued_place' => 'Nơi cấp CCCD',
        'business_license_date'          => 'Ngày cấp GPKD',
        'business_license_issuer'        => 'Nơi cấp GPKD',
    ];

    public function __construct(private LegalDocumentScanService $scanner)
    {
    }

    public function isConfigured(): bool
    {
        return $this->scanner->isConfigured();
    }

    /**
     * @param array{gpkd?: ?UploadedFile, cccd_front?: ?UploadedFile, cccd_back?: ?UploadedFile, antt?: ?UploadedFile} $files
     *
     * @return array{fields: array<string, ?string>, sources: array<string, string>, labels: array<string, string>, warnings: list<string>,
     *               documents: array<string, array{read: bool, found: int, total: int}>}
     */
    public function scan(array $files): array
    {
        $scans = [];

        if (! empty($files['gpkd'])) {
            $scans['business_license'] = $this->scanner->scan($files['gpkd'], 'business_license');
        }

        if (! empty($files['cccd_front']) || ! empty($files['cccd_back'])) {
            $scans['citizen_id'] = $this->scanner->scan($files['cccd_front'] ?? $files['cccd_back'], 'citizen_id', empty($files['cccd_front']) ? null : ($files['cccd_back'] ?? null));
        }

        if (! empty($files['antt'])) {
            $scans['security_order'] = $this->scanner->scan($files['antt'], 'security_order');
        }

        $result = $this->merge($scans);
        $result['scans'] = array_map(fn (array $scan) => [
            'text_found' => (bool) ($scan['text_found'] ?? false),
            'qr'         => (bool) ($scan['qr'] ?? false),
            'found'      => (int) ($scan['found'] ?? 0),
            'total'      => (int) ($scan['total'] ?? 0),
            'fields'     => (array) ($scan['fields'] ?? []),
        ], $scans);

        if (! empty($files['cccd_front']) xor ! empty($files['cccd_back'])) {
            $result['warnings'][] = 'CCCD nên có đủ hai mặt (trước và sau) để đọc chắc chắn mã QR.';
        }

        return $result;
    }

    /**
     * Gộp kết quả quét (đã qua LegalDocumentScanService) thành gợi ý cho hồ sơ đối tác — tách riêng để kiểm thử không cần gọi OCR.
     *
     * @param array<string, array> $scans khoá = loại giấy tờ (business_license | citizen_id | security_order)
     */
    public function merge(array $scans): array
    {
        $v = fn (string $type, string $name) => $this->value($scans, $type, $name);

        $candidates = [
            'legal_name'                     => [['security_order', 'business_name'], ['business_license', 'legal_representative']],
            'tax_code'                       => [['business_license', 'document_number']],
            'address'                        => [['business_license', 'business_address'], ['security_order', 'business_address']],
            'phone'                          => [['business_license', 'phone']],
            'representative_name'            => [['citizen_id', 'full_name'], ['business_license', 'legal_representative'], ['security_order', 'responsible_person']],
            'representative_dob'             => [['citizen_id', 'dob']],
            'representative_id_number'       => [['citizen_id', 'document_number'], ['business_license', 'representative_id_number'], ['security_order', 'responsible_id_number']],
            'representative_id_issued_at'    => [['citizen_id', 'issued_at']],
            'representative_id_issued_place' => [['citizen_id', 'issuer']],
            'business_license_date'          => [['business_license', 'issued_at']],
            'business_license_issuer'        => [['business_license', 'issuer']],
        ];

        $fields = [];
        $sources = [];

        foreach ($candidates as $field => $options) {
            $fields[$field] = null;

            foreach ($options as [$type, $name]) {
                $value = $v($type, $name);

                if (filled($value)) {
                    $fields[$field] = $value;
                    $sources[$field] = $type;
                    break;
                }
            }
        }

        // Hộ kinh doanh: nếu GPKD không có mã số thì MST thường trùng số CCCD của chủ hộ — chỉ gợi ý khi có CCCD.
        if (blank($fields['tax_code']) && filled($v('citizen_id', 'document_number')) && isset($scans['business_license'])) {
            $fields['tax_code'] = $v('citizen_id', 'document_number');
            $sources['tax_code'] = 'citizen_id';
        }

        $documents = [];

        foreach ($scans as $type => $scan) {
            $documents[$type] = [
                'read'  => (bool) ($scan['text_found'] ?? false),
                'found' => (int) ($scan['found'] ?? 0),
                'total' => (int) ($scan['total'] ?? 0),
            ];
        }

        return [
            'fields'    => $fields,
            'sources'   => $sources,
            'labels'    => self::LABELS,
            'warnings'  => $this->warnings($scans, $fields),
            'documents' => $documents,
        ];
    }

    /**
     * ĐƯA GIẤY TỜ ĐÃ QUÉT VÀO HỒ SƠ PHÁP LÝ của đối tác: mỗi giấy thành một tài liệu (tệp + các ô đọc được) ở trạng thái CHỜ DUYỆT
     * để Super Admin xem lại và duyệt như bình thường (không tự duyệt). Tài liệu chưa có kết quả quét thì để BẢN NHÁP (admin bổ sung rồi
     * gửi duyệt). CCCD cần đủ hai mặt và đọc được mã QR (cùng luật với API nộp giấy tờ), thiếu thì bỏ qua và báo.
     *
     * @param array{gpkd?: ?UploadedFile, cccd_front?: ?UploadedFile, cccd_back?: ?UploadedFile, antt?: ?UploadedFile} $files
     * @param array<string, array> $scans kết quả quét theo loại giấy tờ (business_license | citizen_id | security_order); thiếu thì giấy đó để bản nháp
     *
     * @return array{created: array<string, string>, skipped: list<string>}
     */
    public function attach(\App\Models\Partner $partner, array $files, array $scans, ?string $userId = null): array
    {
        $created = [];
        $skipped = [];

        $existing = fn (string $type) => $partner->legalDocuments()->where('type', $type)->whereIn('status', ['pending_review', 'approved'])->exists();

        $create = function (string $type, UploadedFile $file, ?UploadedFile $back, ?array $scan) use ($partner, $userId, &$created) {
            $read = $scan && ($type === 'citizen_id' ? ! empty($scan['qr']) : ! empty($scan['text_found']));
            $document = $partner->legalDocuments()->create([
                'type'         => $type,
                'name'         => \App\Models\PartnerLegalDocument::TYPES[$type],
                ...LegalDocumentFields::valuesFrom($type, $read ? (array) $scan['fields'] : []),
                'status'       => $read ? 'pending_review' : 'draft',
                'submitted_at' => $read ? now() : null,
                'created_by'   => $userId,
            ]);

            $document->addMedia($file->getRealPath())->usingFileName($file->getClientOriginalName() ?: basename($file->getRealPath()))->preservingOriginal()->toMediaCollection('file');

            if ($back) {
                $document->addMedia($back->getRealPath())->usingFileName($back->getClientOriginalName() ?: basename($back->getRealPath()))->preservingOriginal()->toMediaCollection('file_back');
            }

            $created[$type] = $document->id;
        };

        foreach (['gpkd' => 'business_license', 'antt' => 'security_order'] as $key => $type) {
            if (empty($files[$key])) {
                continue;
            }

            if ($existing($type)) {
                $skipped[] = \App\Models\PartnerLegalDocument::TYPES[$type] . ': đối tác đã có giấy này (chờ duyệt / đã duyệt) nên không thêm lại.';
                continue;
            }

            $create($type, $files[$key], null, $scans[$type] ?? null);
        }

        if (! empty($files['cccd_front']) || ! empty($files['cccd_back'])) {
            if ($existing('citizen_id')) {
                $skipped[] = 'CCCD: đối tác đã có CCCD (chờ duyệt / đã duyệt) nên không thêm lại.';
            } elseif (empty($files['cccd_front']) || empty($files['cccd_back'])) {
                $skipped[] = 'CCCD: cần đủ cả hai mặt (trước và sau) mới đưa vào hồ sơ pháp lý.';
            } elseif (empty($scans['citizen_id']['qr'])) {
                $skipped[] = 'CCCD: chưa đọc được mã QR nên chưa đưa vào hồ sơ pháp lý — tải lại ảnh rõ hơn ở tab Tài liệu & Xác minh.';
            } else {
                $create('citizen_id', $files['cccd_front'], $files['cccd_back'], $scans['citizen_id']);
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Cảnh báo từ từng giấy + đối chiếu chéo giữa các giấy. */
    private function warnings(array $scans, array $fields): array
    {
        $warnings = [];

        foreach ($scans as $type => $scan) {
            $label = \App\Models\PartnerLegalDocument::TYPES[$type] ?? $type;

            foreach ((array) ($scan['warnings'] ?? []) as $warning) {
                $warnings[] = "{$label}: {$warning}";
            }
        }

        $cccd = $this->value($scans, 'citizen_id', 'document_number');
        $gpkdId = $this->value($scans, 'business_license', 'representative_id_number');
        $anttId = $this->value($scans, 'security_order', 'responsible_id_number');

        foreach ([['Giấy phép kinh doanh', $gpkdId], ['Giấy An ninh trật tự', $anttId]] as [$name, $id]) {
            if (filled($cccd) && filled($id) && $this->digits($cccd) !== $this->digits($id)) {
                $warnings[] = "Số CCCD trên {$name} ({$id}) KHÔNG khớp CCCD đã quét ({$cccd}). Kiểm tra lại giấy tờ.";
            }
        }

        $cccdName = $this->value($scans, 'citizen_id', 'full_name');

        foreach ([['Giấy phép kinh doanh', $this->value($scans, 'business_license', 'legal_representative')], ['Giấy An ninh trật tự', $this->value($scans, 'security_order', 'responsible_person')]] as [$name, $person]) {
            if (filled($cccdName) && filled($person) && $this->fold($cccdName) !== $this->fold($person)) {
                $warnings[] = "Tên người đại diện trên {$name} ({$person}) khác tên trên CCCD ({$cccdName}).";
            }
        }

        $tax = (string) ($fields['tax_code'] ?? '');

        if ($tax !== '' && ! preg_match('/^(\d{10}(-?\d{3})?|\d{12})$/', $tax)) {
            $warnings[] = "Mã số thuế đọc được ({$tax}) không đúng định dạng (10 số, 13 số hoặc 12 số). Kiểm tra lại.";
        }

        foreach (['legal_name', 'tax_code', 'address', 'representative_name', 'representative_id_number'] as $required) {
            if (blank($fields[$required] ?? null)) {
                $warnings[] = 'Chưa đọc được: ' . self::LABELS[$required] . ' — vui lòng tự nhập.';
            }
        }

        return array_values(array_unique($warnings));
    }

    private function value(array $scans, string $type, string $name): ?string
    {
        $value = $scans[$type]['fields'][LegalDocumentFields::key($type, $name)] ?? null;

        return filled($value) ? trim((string) $value) : null;
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function fold(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($value))) ?? '');
    }
}
