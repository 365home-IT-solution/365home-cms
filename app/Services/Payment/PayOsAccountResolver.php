<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Config;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerPayOsAccount;
use PayOS\PayOS;
use RuntimeException;

// Chọn tài khoản PayOS cho đơn Homestay, theo thứ tự:
//   1. Ghi đè theo CHI NHÁNH (BranchPayOsAccount: orders.category_id, dò ngược lên chi nhánh gốc nếu là
//      khu vực con) — chỉ dùng khi một chi nhánh có pháp nhân/tài khoản khác với đối tác.
//   2. Kênh RIÊNG CỦA ĐỐI TÁC sở hữu chi nhánh của đơn (PartnerPayOsAccount) — nơi cấu hình CHÍNH, áp
//      dụng cho mọi chi nhánh của đối tác; tiền vào thẳng tài khoản đối tác.
//   3. Tài khoản CHUNG của 365home (payment_configurations, nạp vào config('payos.*') ở
//      PaymentServiceProvider::boot()) — đối tác chưa cấu hình kênh thì 365home thu hộ như cũ.
// Mọi chỗ tạo/tra/huỷ link PayOS của Order PHẢI đi qua đây — không tự đọc config('payos.*') nữa, nếu
// không link sẽ rơi nhầm về tài khoản chung.
class PayOsAccountResolver
{
    // Đủ sâu cho cây chi nhánh -> khu vực hiện có (2 cấp), chặn vòng lặp nếu parent_id bị trỏ vòng.
    private const MAX_PARENT_DEPTH = 5;

    /** @return array{0: string, 1: string, 2: string}|null */
    public static function globalCredentials(): ?array
    {
        $credentials = [
            (string) Config::get('payos.client_id'),
            (string) Config::get('payos.api_key'),
            (string) Config::get('payos.checksum_key'),
        ];

        return in_array('', $credentials, true) ? null : $credentials;
    }

    /**
     * Tài khoản PayOS riêng áp dụng cho chi nhánh/khu vực này — lấy của chính nó, không có thì
     * của chi nhánh cha gần nhất.
     *
     * @param  bool  $activeOnly  false = lấy cả tài khoản đang tắt (chỉ dùng làm dự phòng tra link cũ)
     */
    public static function branchAccountFor(?int $categoryId, bool $activeOnly = true): ?BranchPayOsAccount
    {
        $depth = 0;

        while ($categoryId && $depth++ < self::MAX_PARENT_DEPTH) {
            $account = BranchPayOsAccount::where('category_id', $categoryId)->first();

            if ($account && $account->isComplete() && (! $activeOnly || $account->is_active)) {
                return $account;
            }

            $categoryId = Category::whereKey($categoryId)->value('parent_id');
        }

        return null;
    }

    /** Đối tác sở hữu chi nhánh/khu vực này (partner_id của chính nó, không có thì của chi nhánh cha gần nhất). */
    public static function partnerIdForCategory(?int $categoryId): ?string
    {
        $depth = 0;

        while ($categoryId && $depth++ < self::MAX_PARENT_DEPTH) {
            $category = Category::query()->whereKey($categoryId)->first(['id', 'parent_id', 'partner_id']);

            if (! $category) {
                return null;
            }

            if ($category->partner_id) {
                return (string) $category->partner_id;
            }

            $categoryId = $category->parent_id;
        }

        return null;
    }

    // Đối tác nhận tiền của đơn: chủ chi nhánh của đơn; đơn không gắn chi nhánh thì theo orders.partner_id.
    public static function partnerIdForOrder(?Order $order): ?string
    {
        if (! $order) {
            return null;
        }

        return self::partnerIdForCategory($order->category_id ? (int) $order->category_id : null)
            ?? ($order->partner_id ? (string) $order->partner_id : null);
    }

    /**
     * Kênh PayOS riêng của đối tác.
     *
     * @param  bool  $activeOnly  false = lấy cả kênh đang tắt (chỉ dùng làm dự phòng tra link cũ)
     */
    public static function partnerAccountFor(?string $partnerId, bool $activeOnly = true): ?PartnerPayOsAccount
    {
        if (! $partnerId) {
            return null;
        }

        $account = PartnerPayOsAccount::where('partner_id', $partnerId)->first();

        return $account && $account->isComplete() && (! $activeOnly || $account->is_active) ? $account : null;
    }

    public static function forCategory(?int $categoryId, ?string $partnerId = null): ?PayOsGateway
    {
        $partnerId ??= self::partnerIdForCategory($categoryId);

        $branch  = self::branchAccountFor($categoryId);
        $partner = self::partnerAccountFor($partnerId);
        $global  = self::globalCredentials();

        $primary = $branch?->credentials() ?? $partner?->credentials() ?? $global;

        if (! $primary) {
            return null;
        }

        // Dự phòng để tra/huỷ/xác thực link tạo TRƯỚC khi đổi tài khoản: kênh đối tác (khi chi nhánh
        // vừa bật ghi đè), tài khoản chung (link tạo trước khi có kênh riêng) và các kênh riêng đã
        // tắt — bỏ trùng theo client_id.
        $fallbacks = [];
        $seen      = [$primary[0] => true];

        foreach ([
            $partner?->credentials(),
            $global,
            self::branchAccountFor($categoryId, activeOnly: false)?->credentials(),
            self::partnerAccountFor($partnerId, activeOnly: false)?->credentials(),
        ] as $credentials) {
            if ($credentials && ! isset($seen[$credentials[0]])) {
                $seen[$credentials[0]] = true;
                $fallbacks[]           = new PayOS(...$credentials);
            }
        }

        return new PayOsGateway($primary[0], $primary[1], $primary[2], $fallbacks, $branch?->id, $branch ? null : $partner?->partner_id);
    }

    public static function forOrder(?Order $order): ?PayOsGateway
    {
        return self::forCategory($order?->category_id ? (int) $order->category_id : null, self::partnerIdForOrder($order));
    }

    public static function forOrderOrFail(?Order $order): PayOsGateway
    {
        return self::forOrder($order) ?? throw new RuntimeException('Cổng thanh toán PayOS chưa được cấu hình.');
    }

    /**
     * Tìm đơn sở hữu 1 mã PayOS bất kỳ (mã đơn gốc, mã retry, mã tiền còn lại, mã cọc lại, mã phát
     * sinh). Bỏ global scope vì được gọi từ webhook/trang công khai, không phải panel admin.
     */
    public static function findOrderByPayOsCode(string|int|null $code): ?Order
    {
        if (! $code) {
            return null;
        }

        return Order::withoutGlobalScopes()
            ->where(fn ($q) => $q
                ->where('order_code', (string) $code)
                ->orWhere('current_payos_code', $code)
                ->orWhere('remaining_payos_code', $code)
                ->orWhere('deposit_retry_payos_code', $code)
                ->orWhere('extra_charge_payos_code', $code))
            ->first();
    }

    /**
     * Checksum key được phép dùng để xác thực webhook của 1 đơn: tài khoản chính của đơn + dự phòng.
     * CHỈ những key này — không nhận key của chi nhánh khác, nếu không chủ nhà A (biết checksum key
     * của mình) có thể tự ký webhook giả đánh dấu "đã thanh toán" cho đơn của chi nhánh B.
     *
     * @return string[]
     */
    public static function checksumKeysForOrder(Order $order): array
    {
        $categoryId = $order->category_id ? (int) $order->category_id : null;
        $partnerId  = self::partnerIdForOrder($order);

        return array_values(array_unique(array_filter([
            self::branchAccountFor($categoryId)?->checksum_key,
            self::partnerAccountFor($partnerId)?->checksum_key,
            self::globalCredentials()[2] ?? null,
            self::branchAccountFor($categoryId, activeOnly: false)?->checksum_key,
            self::partnerAccountFor($partnerId, activeOnly: false)?->checksum_key,
        ])));
    }

    /**
     * Checksum key của MỌI tài khoản PayOS riêng đang bật — chỉ để nhận diện request thử của PayOS
     * (bấm "Lưu webhook" trên dashboard PayOS của chủ nhà, orderCode không thuộc đơn nào) và trả 200
     * cho PayOS chấp nhận URL. TUYỆT ĐỐI không dùng để xử lý đơn.
     *
     * @return string[]
     */
    public static function allBranchChecksumKeys(): array
    {
        return collect(BranchPayOsAccount::where('is_active', true)->get())
            ->concat(PartnerPayOsAccount::where('is_active', true)->get())
            ->filter(fn (BranchPayOsAccount|PartnerPayOsAccount $account) => $account->isComplete())
            ->map(fn (BranchPayOsAccount|PartnerPayOsAccount $account) => $account->checksum_key)
            ->unique()
            ->values()
            ->all();
    }
}
