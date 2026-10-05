<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PartnerLegalDocument;

// BỘ TRƯỜNG RIÊNG theo loại giấy tờ pháp lý — MỘT nguồn duy nhất cho API (công khai + admin), Filament, trang đăng ký và API quét giấy tờ.
// Số / nơi cấp / ngày cấp dùng cột chung của partner_legal_documents (COLUMNS); các trường còn lại lưu trong cột JSON `extra`.
// Loại không khai báo ở đây (thuế, uỷ quyền, khác...) dùng form chung như cũ (số, nơi cấp, ngày cấp, ngày hết hạn, tên).
class LegalDocumentFields
{
    public const COLUMNS = ['document_number', 'issued_at', 'issuer'];

    // key => [nhãn, kiểu ô nhập]. Thứ tự = thứ tự hiển thị trên form.
    public const FIELDS = [
        'business_license' => [
            'document_number'          => ['Mã số doanh nghiệp', 'text'],
            'issued_at'                => ['Ngày cấp', 'date'],
            'issuer'                   => ['Nơi cấp', 'text'],
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
    ];

    // PCCC: tình trạng suy ra từ SỐ VĂN BẢN — TD-PCCC = mới thẩm duyệt thiết kế; NT / BB / GXN-PCCC = đã nghiệm thu.
    public const FIRE_SAFETY_STAGES = [
        'design_approved' => 'Thẩm duyệt: Chưa hoạt động',
        'accepted'        => 'Đã nghiệm thu: Chuẩn bị hoạt động',
    ];

    public static function has(?string $type): bool
    {
        return isset(self::FIELDS[$type]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function for(?string $type): array
    {
        return self::FIELDS[$type] ?? [];
    }

    /** @return array<int, string> khoá lưu trong cột `extra` của loại này */
    public static function extraKeys(?string $type): array
    {
        return array_values(array_diff(array_keys(self::for($type)), self::COLUMNS));
    }

    /** @return array<int, string> mọi khoá `extra` của mọi loại (API nhận phẳng ở cấp ngoài cùng) */
    public static function allExtraKeys(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn (string $type) => self::extraKeys($type), array_keys(self::FIELDS)))));
    }

    /** Rule cho các trường riêng (đều không bắt buộc; có nhập thì phải đúng định dạng). */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::allExtraKeys() as $key) {
            $rules[$key] = match (true) {
                str_ends_with($key, '_id_number') => ['nullable', 'string', 'regex:/^[0-9]{9}([0-9]{3})?$/'],
                $key === 'phone'                  => ['nullable', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
                $key === 'business_lines'         => ['nullable', 'string', 'max:2000'],
                str_ends_with($key, '_address')   => ['nullable', 'string', 'max:500'],
                default                           => ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    public static function messages(): array
    {
        return [
            'representative_id_number.regex' => 'Số định danh cá nhân phải gồm 9 hoặc 12 chữ số.',
            'responsible_id_number.regex'    => 'Số định danh cá nhân/CCCD phải gồm 9 hoặc 12 chữ số.',
            'phone.regex'                    => 'Số điện thoại không hợp lệ.',
        ];
    }

    /** Nhãn cho thông báo lỗi validate (khoá trùng giữa các loại lấy nhãn đầu tiên). */
    public static function attributes(): array
    {
        $labels = [];
        foreach (self::FIELDS as $fields) {
            foreach ($fields as $key => [$label]) {
                $labels[$key] ??= mb_strtolower($label);
            }
        }

        return array_diff_key($labels, array_flip(self::COLUMNS));
    }

    /** Lọc dữ liệu gửi lên thành giá trị cột `extra` của ĐÚNG loại giấy tờ (bỏ khoá của loại khác và ô trống); không có gì → null. */
    public static function extraFrom(?string $type, array $data): ?array
    {
        $extra = [];
        foreach (self::extraKeys($type) as $key) {
            if (filled($data[$key] ?? null)) {
                $extra[$key] = trim((string) $data[$key]);
            }
        }

        return $extra === [] ? null : $extra;
    }

    /** Mô tả form theo loại cho client dựng ô nhập: [{type, label, fields: [{key, label, input}]}]. */
    public static function schema(): array
    {
        return array_map(fn (string $type) => [
            'type'   => $type,
            'label'  => PartnerLegalDocument::TYPES[$type] ?? $type,
            'fields' => array_map(fn (string $key, array $def) => ['key' => $key, 'label' => $def[0], 'input' => $def[1]], array_keys(self::FIELDS[$type]), self::FIELDS[$type]),
        ], array_keys(self::FIELDS));
    }

    /** Các trường của một giấy tờ theo đúng thứ tự form, kèm giá trị — để client hiển thị chi tiết: [{key, label, value}]. */
    public static function display(PartnerLegalDocument $document): array
    {
        $rows = [];
        foreach (self::for($document->type) as $key => [$label]) {
            $value = in_array($key, self::COLUMNS, true) ? $document->{$key} : ($document->extra[$key] ?? null);
            $rows[] = ['key' => $key, 'label' => $label, 'value' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value];
        }

        return $rows;
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
