<?php

namespace App\Services\Payment;

use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Product\App\Models\Product;

// Đối tác Homestay được Super Admin bật "Bắt buộc PayOS riêng" (partners.require_own_payos) chỉ được ĐĂNG BÁN phòng online (hiện ở danh
// sách/tìm kiếm + nhận đặt phòng trên web/API khách) khi đã LIÊN KẾT tài khoản PayOS: kênh riêng của đối tác (PartnerPayOsAccount) đang bật
// + đủ khoá, hoặc tài khoản riêng của chi nhánh (BranchPayOsAccount) — khi đó chỉ phòng thuộc chi nhánh đó được bán. Đối tác KHÔNG bật vẫn
// bán bằng PayOS chung của 365home như cũ. Đối tác nền tảng/hệ thống, MiniHouse và phòng không gắn đối tác không bị cổng này chặn. Đơn
// admin/lễ tân tạo hộ (trang quản trị, API admin) không đi qua đây. Tắt toàn bộ cổng: PARTNER_REQUIRE_PAYOS_CHANNEL=false.
// Cùng kiểu với tạm ngưng bán do ký quỹ (App\Services\EscrowService::isSalesSuspended()).
class PartnerPayOsSalesGate
{
    public static function enabled(): bool
    {
        return (bool) config('partner_flow.require_payos_channel', true);
    }

    /**
     * Đối tác Homestay bắt buộc PayOS riêng nhưng CHƯA có kênh riêng của đối tác (có thể vẫn có tài khoản ở vài chi nhánh — xem linkedBranchCategoryIds()).
     *
     * @return string[]
     */
    public static function unlinkedPartnerIds(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $required = Partner::query()->withoutGlobalScopes()->where('require_own_payos', true);

        if (! (clone $required)->exists()) {
            return [];
        }

        // Khoá lưu mã hoá nên kiểm tra đủ khoá bằng isComplete(), không lọc được bằng SQL.
        $linked = PartnerPayOsAccount::query()->where('is_active', true)->get()
            ->filter(fn (PartnerPayOsAccount $account) => $account->isComplete())
            ->pluck('partner_id')
            ->all();

        return $required
            ->where(fn ($q) => $q->whereNull('partner_type')->orWhere('partner_type', '!=', Partner::TYPE_MINIHOUSE))
            ->where(fn ($q) => $q->whereNull('is_platform_partner')->orWhere('is_platform_partner', false))
            ->whereKeyNot(\Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID)
            ->whereNotIn('id', $linked)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Chi nhánh/khu vực có tài khoản PayOS riêng đang bật + đủ khoá, kèm MỌI danh mục con cháu (khu vực con, danh mục phòng cùng tên phòng)
     * — cùng phạm vi áp dụng với PayOsAccountResolver::branchAccountFor() (dò ngược lên cha).
     *
     * @return int[]
     */
    public static function linkedBranchCategoryIds(): array
    {
        $ids = BranchPayOsAccount::query()->where('is_active', true)->get()
            ->filter(fn (BranchPayOsAccount $account) => $account->isComplete())
            ->pluck('category_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Đủ sâu cho cây chi nhánh -> khu vực -> danh mục phòng; chặn vòng lặp nếu parent_id bị trỏ vòng.
        for ($level = $ids, $depth = 0; $level !== [] && $depth < 5; $depth++) {
            $level = array_values(array_diff(Category::query()->whereIn('parent_id', $level)->pluck('id')->map(fn ($id) => (int) $id)->all(), $ids));
            $ids   = array_merge($ids, $level);
        }

        return array_values(array_unique($ids));
    }

    /** Lọc danh sách phòng công khai: bỏ phòng của đối tác chưa liên kết PayOS (trừ phòng thuộc chi nhánh đã liên kết). Gọi từ Product::scopeActiveBranch(). */
    public static function applyToRoomQuery($query): void
    {
        $unlinked = self::unlinkedPartnerIds();

        if ($unlinked === []) {
            return;
        }

        $linkedCategoryIds = self::linkedBranchCategoryIds();

        $query->where(function ($q) use ($unlinked, $linkedCategoryIds) {
            $q->whereNull($q->qualifyColumn('partner_id'))->orWhereNotIn($q->qualifyColumn('partner_id'), $unlinked);

            if ($linkedCategoryIds !== []) {
                $q->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $linkedCategoryIds));
            }
        });
    }

    /** Phòng này có được bán online không (đối tác hoặc chi nhánh của phòng đã liên kết PayOS)? */
    public static function canSellRoom(?Product $room): bool
    {
        if (! $room || ! $room->partner_id || ! in_array((string) $room->partner_id, self::unlinkedPartnerIds(), true)) {
            return true;
        }

        $linkedCategoryIds = self::linkedBranchCategoryIds();

        return $linkedCategoryIds !== [] && $room->categories()->whereIn('categories.id', $linkedCategoryIds)->exists();
    }

    /** 423 PARTNER_PAYOS_REQUIRED cho API đặt phòng của khách (cùng kiểu EscrowService::salesBlockResponse()), null = được đặt. */
    public static function blockResponse(?Product $room): ?JsonResponse
    {
        return self::canSellRoom($room)
            ? null
            : response()->json(['message' => self::message(), 'code' => 'PARTNER_PAYOS_REQUIRED'], 423);
    }

    public static function message(): string
    {
        return 'Cơ sở này chưa nhận đặt phòng online. Vui lòng chọn phòng khác.';
    }
}
