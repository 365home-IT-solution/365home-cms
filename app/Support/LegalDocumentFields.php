<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PartnerLegalDocument;

// BỘ Ô RIÊNG theo loại giấy tờ pháp lý — MỘT nguồn duy nhất cho API (công khai + admin), Filament, trang đăng ký và API quét giấy tờ.
// MỖI LOẠI CÓ CỘT RIÊNG HOÀN TOÀN, kể cả ô trùng tên (số, ngày cấp, nơi cấp): cột = <tiền tố loại>_<tên ô>,
// vd dkkd_issued_at / antt_issued_at / pccc_issued_at / cccd_issued_at — không loại nào dùng chung cột với loại khác.
// 3 cột chung cũ (document_number, issuer, issued_at) chỉ còn là BẢN TÓM TẮT tự chép từ cột riêng (PartnerLegalDocument::saving)
// để danh sách, snapshot hợp đồng và client cũ vẫn đọc được. Loại không khai báo ở đây (thuế, uỷ quyền, khác...) dùng form chung như cũ.
class LegalDocumentFields
{
    public const PREFIXES = ['business_license' => 'dkkd', 'security_order' => 'antt', 'fire_safety' => 'pccc', 'citizen_id' => 'cccd'];

    // Ô nào của mỗi loại được chép sang cột tóm tắt chung.
    public const SUMMARY = ['document_number', 'issued_at', 'issuer'];

    // tên ô (chưa gắn tiền tố) => [nhãn, kiểu ô nhập]. Thứ tự = thứ tự hiển thị trên form.
    private const DEFINITIONS = [
        'business_license' => [
            'document_number'          => ['Mã số doanh nghiệp', 'text'],
            'issued_at'                => ['Ngày cấp', 'date'],
            'issuer'                   => ['Nơi cấp/Cơ quan cấp', 'text'],
            'business_address'         => ['Địa chỉ kinh doanh', 'text'],
            'business_lines'           => ['Ngành, nghề kinh doanh', 'textarea'],
            'legal_representative'     => ['Người đại diện theo pháp luật', 'text'],
            'representative_id_number' => ['Số định danh cá nhân', 'text'],
            'phone'                    => ['Số điện thoại', 'tel'],
        ],
        'security_order' => [
            'document_number'       => ['Số giấy chứng nhận', 'text'],
            'issued_at'             => ['Ngày cấp', 'date'],
            'issuer'                => ['Nơi cấp/Cơ quan cấp', 'text'],
            'business_name'         => ['Tên cơ sở kinh doanh', 'text'],
            'business_address'      => ['Địa chỉ kinh doanh', 'text'],
            'responsible_person'    => ['Người chịu trách nhiệm', 'text'],
            'responsible_id_number' => ['Số định danh cá nhân/CCCD', 'text'],
        ],
        'fire_safety' => [
            'document_number'      => ['Số văn bản', 'text'],
            'issued_at'            => ['Ngày cấp', 'date'],
            'issuer'               => ['Nơi cấp/Cơ quan cấp', 'text'],
            'investor'             => ['Chủ đầu tư/Chủ phương tiện', 'text'],
            'representative'       => ['Người đại diện', 'text'],
            'representative_title' => ['Chức danh', 'text'],
            'site_address'         => ['Địa điểm xây dựng / Kinh doanh', 'text'],
        ],
        // CCCD: các ô lấy từ MÃ QR trên thẻ (bắt buộc đọc được QR khi nộp — LegalDocumentScanService::citizenId); QR không có nơi cấp nên ô đó tự nhập.
        'citizen_id' => [
            'document_number' => ['Số CCCD', 'text'],
            'full_name'       => ['Họ và tên', 'text'],
            'dob'             => ['Ngày sinh', 'date'],
            'gender'          => ['Giới tính', 'text'],
            'address'         => ['Nơi thường trú', 'text'],
            'issued_at'       => ['Ngày cấp', 'date'],
            'issuer'          => ['Nơi cấp', 'text'],
        ],
    ];

    // Ô của CCCD do MÃ QR quyết định (client không sửa được; server ghi đè bằng dữ liệu QR khi nộp).
    public const CITIZEN_ID_QR_FIELDS = ['document_number', 'full_name', 'dob', 'gender', 'address', 'issued_at'];

    // PCCC: tình trạng suy ra từ SỐ VĂN BẢN — TD-PCCC = mới thẩm duyệt thiết kế; NT / BB / GXN-PCCC = đã nghiệm thu.
    public const FIRE_SAFETY_STAGES = [
        'design_approved' => 'Thẩm duyệt: Chưa hoạt động',
        'accepted'        => 'Đã nghiệm thu: Chuẩn bị hoạt động',
    ];

    /** @return array<int, string> các loại có bộ ô riêng */
    public static function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function has(?string $type): bool
    {
        return isset(self::DEFINITIONS[$type]);
    }

    /** @return array<int, string> tên ô CHƯA gắn tiền tố của một loại (dùng nội bộ, vd bộ quét) */
    public static function names(?string $type): array
    {
        return array_keys(self::DEFINITIONS[$type] ?? []);
    }

    /** Tên cột/khoá API của một ô: key('fire_safety', 'issued_at') = 'pccc_issued_at'. */
    public static function key(string $type, string $name): string
    {
        return self::PREFIXES[$type] . '_' . $name;
    }

    /** @return array<string, array{0: string, 1: string}> cột => [nhãn, kiểu ô nhập] của một loại */
    public static function for(?string $type): array
    {
        $fields = [];
        foreach (self::DEFINITIONS[$type] ?? [] as $name => $definition) {
            $fields[self::key($type, $name)] = $definition;
        }

        return $fields;
    }

    /** @return array<int, string> */
    public static function keys(?string $type): array
    {
        return array_keys(self::for($type));
    }

    /** @return array<int, string> mọi cột riêng của mọi loại */
    public static function allKeys(): array
    {
        return array_merge(...array_map(fn (string $type) => self::keys($type), self::types()));
    }

    /** @return array<int, string> các cột ngày (để cast và validate) */
    public static function dateKeys(): array
    {
        return array_values(array_filter(self::allKeys(), fn (string $key) => str_ends_with($key, '_issued_at') || str_ends_with($key, '_dob')));
    }

    /** Rule cho các ô riêng (đều không bắt buộc; có nhập thì phải đúng định dạng). */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::allKeys() as $key) {
            $rules[$key] = match (true) {
                $key === 'cccd_document_number'         => ['nullable', 'string', 'regex:/^[0-9]{9}([0-9]{3})?$/'],
                str_ends_with($key, '_issued_at')       => ['nullable', 'date', 'before_or_equal:today'],
                str_ends_with($key, '_dob')             => ['nullable', 'date', 'before:today'],
                str_ends_with($key, '_id_number')       => ['nullable', 'string', 'regex:/^[0-9]{9}([0-9]{3})?$/'],
                str_ends_with($key, '_phone')           => ['nullable', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
                str_ends_with($key, '_document_number') => ['nullable', 'string', 'max:100'],
                str_ends_with($key, '_business_lines')  => ['nullable', 'string', 'max:2000'],
                str_ends_with($key, '_address')         => ['nullable', 'string', 'max:500'],
                default                                 => ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    public static function messages(): array
    {
        $messages = [];
        foreach (self::allKeys() as $key) {
            if (str_ends_with($key, '_issued_at')) {
                $messages["{$key}.before_or_equal"] = 'Ngày cấp không được ở tương lai.';
            } elseif (str_ends_with($key, '_dob')) {
                $messages["{$key}.before"] = 'Ngày sinh không hợp lệ.';
            } elseif ($key === 'cccd_document_number') {
                $messages["{$key}.regex"] = 'Số CCCD phải gồm 9 hoặc 12 chữ số.';
            } elseif (str_ends_with($key, '_id_number')) {
                $messages["{$key}.regex"] = 'Số định danh cá nhân phải gồm 9 hoặc 12 chữ số.';
            } elseif (str_ends_with($key, '_phone')) {
                $messages["{$key}.regex"] = 'Số điện thoại không hợp lệ.';
            }
        }

        return $messages;
    }

    /** Nhãn cho thông báo lỗi validate. */
    public static function attributes(): array
    {
        $labels = [];
        foreach (self::types() as $type) {
            foreach (self::for($type) as $key => [$label]) {
                $labels[$key] = mb_strtolower($label);
            }
        }

        return $labels;
    }

    /**
     * Giá trị các CỘT RIÊNG từ dữ liệu gửi lên: chỉ lấy ô của ĐÚNG loại giấy tờ (ô của loại khác bị bỏ qua).
     * $partial = true (sửa): chỉ trả các ô có trong dữ liệu gửi lên; false (tạo): trả đủ mọi ô của loại, thiếu = null.
     */
    public static function valuesFrom(?string $type, array $data, bool $partial = false): array
    {
        $values = [];
        foreach (self::keys($type) as $key) {
            if ($partial && ! array_key_exists($key, $data)) {
                continue;
            }
            $values[$key] = filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        }

        return $values;
    }

    /** Mô tả form theo loại cho client dựng ô nhập: [{type, label, prefix, fields: [{key, label, input}]}]. */
    public static function schema(): array
    {
        return array_map(fn (string $type) => [
            'type'   => $type,
            'label'  => PartnerLegalDocument::TYPES[$type] ?? $type,
            'prefix' => self::PREFIXES[$type],
            'fields' => array_map(fn (string $key, array $def) => ['key' => $key, 'label' => $def[0], 'input' => $def[1]], self::keys($type), array_values(self::for($type))),
        ], self::types());
    }

    /** Các ô của một giấy tờ theo đúng thứ tự form, kèm giá trị — để client hiển thị chi tiết: [{key, label, value}]. */
    public static function display(PartnerLegalDocument $document): array
    {
        $rows = [];
        foreach (self::for($document->type) as $key => [$label]) {
            $value = $document->{$key};
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value];
        }

        return $rows;
    }

    /**
     * Gọi trong PartnerLegalDocument::saving — giữ mỗi loại một bộ cột riêng:
     *  - xoá giá trị ở cột riêng của các LOẠI KHÁC (giấy tờ đổi loại không mang theo dữ liệu loại cũ);
     *  - chép số / ngày cấp / nơi cấp của loại hiện tại sang 3 cột tóm tắt chung. Client cũ chỉ gửi cột chung thì chép ngược vào cột riêng.
     */
    public static function sync(PartnerLegalDocument $document): void
    {
        foreach (self::types() as $type) {
            if ($type !== $document->type) {
                foreach (self::keys($type) as $key) {
                    $document->{$key} = null;
                }
            }
        }

        if (! self::has($document->type)) {
            return;
        }

        foreach (self::SUMMARY as $name) {
            $key = self::key($document->type, $name);
            // Chép ngược chỉ khi giấy tờ KHÔNG đổi loại (đổi loại thì số/ngày/nơi cấp của loại cũ không được trôi sang loại mới).
            // Giấy tờ đang sửa mà cột riêng vừa bị xoá trắng (dirty) thì tôn trọng việc xoá, không chép ngược.
            if (blank($document->{$key}) && filled($document->{$name}) && ! ($document->exists && ($document->isDirty($key) || $document->isDirty('type')))) {
                $document->{$key} = $document->{$name};
            }
            $document->{$name} = $document->{$key};
        }
    }

    /**
     * PCCC: phân loại theo số văn bản. "12/TD-PCCC" → thẩm duyệt (chưa hoạt động); "34/NT-PCCC", "56/BB-...", "78/GXN-PCCC" → đã nghiệm thu.
     *
     * @return array{code: string, label: string}|null
     */
    public static function fireSafetyStage(?string $documentNumber): ?array
    {
        $tokens = preg_split('/[^A-Z0-9]+/', mb_strtoupper(\Illuminate\Support\Str::ascii((string) $documentNumber)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = match (true) {
            array_intersect($tokens, ['NT', 'BB', 'GXN', 'BBNT', 'NTPCCC', 'GXNPCCC']) !== [] => 'accepted',
            array_intersect($tokens, ['TD', 'TDPCCC', 'TDTK']) !== []                         => 'design_approved',
            default                                                                           => null,
        };

        return $code ? ['code' => $code, 'label' => self::FIRE_SAFETY_STAGES[$code]] : null;
    }
}
