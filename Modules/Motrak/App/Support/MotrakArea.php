<?php

namespace Modules\Motrak\App\Support;

use Illuminate\Support\Str;

/**
 * Trường "admin" của Motrak có dạng "<xã/phường>,<quận/huyện>,<tỉnh/TP>" với tiền tố viết tắt, VD:
 *   "P. An Bình,TP. Cần Thơ"   "X. Tân Hạnh,H. Long Hồ,T. Vĩnh Long"   "X. Hòa Điền, T. An Giang".
 * Tỉnh/thành phố luôn là đoạn CUỐI (sau dấu phẩy cuối cùng); tiền tố "T." / "TP." / "Tỉnh" / "Thành phố" được bỏ để so khớp.
 */
class MotrakArea
{
    /** "P. An Bình,TP. Cần Thơ" → "Cần Thơ". Chuỗi không có dấu phẩy được coi là đã là tên tỉnh. null nếu rỗng. */
    public static function province(?string $area): ?string
    {
        if (! filled($area)) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $area)), fn ($p) => $p !== ''));
        if ($parts === []) {
            return null;
        }

        $name = trim(preg_replace('/^(?:TP\.|T\.|Tỉnh|Thành phố)\s*/iu', '', end($parts)));

        return $name !== '' ? $name : null;
    }

    /** Dạng chuẩn để so sánh: bỏ dấu, chữ thường, bỏ tiền tố tỉnh/thành, gộp khoảng trắng. */
    public static function normalize(string $name): string
    {
        $s = preg_replace('/\s+/', ' ', Str::lower(Str::ascii($name)));

        return trim(preg_replace('/^(?:tp|t|tinh|thanh pho)\.?\s+/', '', trim($s)));
    }
}