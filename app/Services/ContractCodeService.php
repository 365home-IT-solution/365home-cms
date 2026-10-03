<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use Illuminate\Support\Facades\DB;

// Mã số hợp đồng TỰ SINH, không nhập tay — đúng mẫu "Số: 001/2026/HĐHT-365":
//   {số thứ tự 3 chữ số, tăng dần trong năm}/{năm}/HĐHT-365
// Số thứ tự cấp liên tục trong từng năm (đầu năm bắt đầu lại từ 001), không trùng, không tái sử dụng (tính cả hợp đồng đã xoá mềm).
// Cấp 1 lần cho mỗi đối tác (lúc tạo hợp đồng đầu tiên) và giữ nguyên khi tạo lại phiên bản hợp đồng.
class ContractCodeService
{
    public const SUFFIX = 'HĐHT-365';

    public function assign(Partner $partner): string
    {
        if (filled($partner->contract_code)) {
            return $partner->contract_code;
        }

        return DB::transaction(function () use ($partner) {
            // Khoá các mã cùng năm để hai lần cấp đồng thời không ra cùng một số.
            $year = (int) now()->format('Y');
            $tail = "/{$year}/" . self::SUFFIX;

            $current = Partner::withTrashed()->withoutGlobalScopes()->lockForUpdate()->find($partner->id);
            if ($current && filled($current->contract_code)) {
                $partner->contract_code = $current->contract_code;

                return $current->contract_code;
            }

            $max = Partner::withTrashed()->withoutGlobalScopes()
                ->where('contract_code', 'like', '%' . $tail)
                ->lockForUpdate()
                ->pluck('contract_code')
                ->map(fn (string $code) => (int) strtok($code, '/'))
                ->max() ?? 0;

            $code = sprintf('%03d%s', $max + 1, $tail);

            Partner::withTrashed()->withoutGlobalScopes()->whereKey($partner->id)->update(['contract_code' => $code]);
            $partner->contract_code = $code;
            $partner->syncOriginalAttribute('contract_code');

            return $code;
        });
    }

    /** Mã đúng định dạng chuẩn? (dùng cho kiểm tra/test) */
    public static function isValid(?string $code): bool
    {
        return (bool) preg_match('~^\d{3,}/\d{4}/' . preg_quote(self::SUFFIX, '~') . '$~u', (string) $code);
    }
}
