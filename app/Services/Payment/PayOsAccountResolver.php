<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Config;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\Order;
use PayOS\PayOS;
use RuntimeException;

// Chọn tài khoản PayOS cho đơn Homestay: chi nhánh của đơn (orders.category_id, dò ngược lên chi
// nhánh gốc nếu là khu vực con) có tài khoản PayOS RIÊNG đang bật (BranchPayOsAccount) thì tiền vào
// thẳng tài khoản chủ nhà; không có thì dùng tài khoản CHUNG (payment_configurations, nạp vào
// config('payos.*') ở PaymentServiceProvider::boot()). Mọi chỗ tạo/tra/huỷ link PayOS của Order PHẢI
// đi qua đây — không tự đọc config('payos.*') nữa, nếu không link sẽ rơi nhầm về tài khoản chung.
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

    public static function forCategory(?int $categoryId): ?PayOsGateway
    {
        $active   = self::branchAccountFor($categoryId);
        $inactive = $active ? null : self::branchAccountFor($categoryId, activeOnly: false);
        $global   = self::globalCredentials();

        $primary = $active?->credentials() ?? $global;

        if (! $primary) {
            return null;
        }

        // Dự phòng: tài khoản chung (link tạo trước khi chi nhánh bật PayOS riêng) và tài khoản
        // riêng đã tắt (link tạo trước khi tắt) — bỏ trùng theo client_id.
        $fallbacks = [];
        $seen      = [$primary[0] => true];

        foreach ([$global, $inactive?->credentials()] as $credentials) {
            if ($credentials && ! isset($seen[$credentials[0]])) {
                $seen[$credentials[0]] = true;
                $fallbacks[]           = new PayOS(...$credentials);
            }
        }

        return new PayOsGateway($primary[0], $primary[1], $primary[2], $fallbacks, $active?->id);
    }

    public static function forOrder(?Order $order): ?PayOsGateway
    {
        return self::forCategory($order?->category_id ? (int) $order->category_id : null);
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

        return array_values(array_unique(array_filter([
            self::branchAccountFor($categoryId)?->checksum_key,
            self::globalCredentials()[2] ?? null,
            self::branchAccountFor($categoryId, activeOnly: false)?->checksum_key,
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
        return BranchPayOsAccount::where('is_active', true)->get()
            ->filter(fn (BranchPayOsAccount $account) => $account->isComplete())
            ->map(fn (BranchPayOsAccount $account) => $account->checksum_key)
            ->unique()
            ->values()
            ->all();
    }
}
