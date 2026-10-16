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

    /**
     * Mã do người dùng NHẬP TAY → mã chuẩn. Nhận số thứ tự ("10", "010") — tự ghép năm hiện tại và hậu tố
     * (010/2026/HĐHT-365) — hoặc mã đầy đủ đúng định dạng. Sai định dạng → null.
     */
    public static function normalize(?string $input, ?int $year = null): ?string
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        if (preg_match('/^\d{1,6}$/', $input)) {
            return sprintf('%03d/%d/%s', (int) $input, $year ?? (int) now()->format('Y'), self::SUFFIX);
        }

        return self::isValid($input) ? $input : null;
    }

    /** Mã đã thuộc về đối tác khác (kể cả đã xoá mềm)? Mã không được trùng và không tái sử dụng. */
    public static function isTaken(string $code, ?string $exceptPartnerId = null): bool
    {
        return Partner::withTrashed()->withoutGlobalScopes()
            ->where('contract_code', $code)
            ->when($exceptPartnerId, fn ($q) => $q->where('id', '!=', $exceptPartnerId))
            ->exists();
    }

    /**
     * Lý do mã nhập tay không dùng được, null nếu hợp lệ. Để trống là hợp lệ (hệ thống tự cấp khi tạo hợp đồng).
     * Hợp đồng đã có hiệu lực (đã ký) thì không đổi mã nữa.
     */
    public static function inputError(?string $input, ?Partner $partner = null): ?string
    {
        if (trim((string) $input) === '') {
            return null;
        }

        $code = self::normalize($input);

        if ($code === null) {
            return 'Mã số hợp đồng gồm số thứ tự (vd 010) hoặc đủ định dạng 010/2026/' . self::SUFFIX . '.';
        }

        if ($partner && $partner->contract_code === $code) {
            return null;
        }

        if ($partner && $partner->contract_status === 'active') {
            return 'Hợp đồng đã có hiệu lực — không đổi mã số được nữa.';
        }

        return self::isTaken($code, $partner?->id) ? "Mã số {$code} đã được dùng cho hợp đồng khác." : null;
    }

    /** Mã đúng định dạng chuẩn? (dùng cho kiểm tra/test) */
    public static function isValid(?string $code): bool
    {
        return (bool) preg_match('~^\d{3,}/\d{4}/' . preg_quote(self::SUFFIX, '~') . '$~u', (string) $code);
    }
}
