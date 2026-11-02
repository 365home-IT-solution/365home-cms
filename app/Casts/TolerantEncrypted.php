<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Giống cast `encrypted` nhưng giá trị không giải mã được (vd DB sao chép từ máy chủ khác, APP_KEY khác) trả null thay vì làm hỏng cả trang —
 * coi như "chưa có token", người dùng nhập/liên kết lại.
 */
class TolerantEncrypted implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null || $value === '' ? null : Crypt::encryptString((string) $value);
    }
}
