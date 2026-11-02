<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Models\User;
use App\Services\PartnerNotifier;
use App\Support\CashPaymentConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

/**
 * Chống huỷ đơn tiền mặt tại quầy để "đi cửa sau" (khách ở thật, trả tiền thẳng cho chủ nhà, đơn trên hệ thống bị huỷ nên không vào đối soát).
 * Mọi đơn tiền mặt đang chờ thu bị huỷ được ghi lúc + nguồn huỷ (partner = tài khoản của đối tác huỷ · admin = Super Admin / 365home huỷ · no_show = hệ thống
 * huỷ vì khách không đến · system = huỷ tự động khác) và đếm RIÊNG hai chiều theo ngày / tháng (lịch giờ VN):
 *  - Đối tác: CHỈ đếm đơn do chính đối tác huỷ. Đạt ngưỡng (cash_payment.partner_day_limit / partner_month_limit) → tạm khoá MỌI phòng đang đăng; đối tác
 *    không tự mở lại được (Product::saving chặn) cho tới khi Super Admin mở khoá — khi đó các phòng đã đăng được mở lại đúng như trước. Báo đối tác + Super Admin.
 *  - Khách (cùng số điện thoại): đếm mọi đơn tiền mặt bị huỷ của khách. Đạt ngưỡng (customer_day_limit / customer_month_limit) → CHỈ thông báo cho Super Admin
 *    và đối tác của đơn, không khoá / không chặn gì.
 */
class CancellationGuardService
{
    public const SOURCE_PARTNER = 'partner';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_NO_SHOW = 'no_show';

    public const SOURCE_SYSTEM = 'system';

    /** Trạng thái coi là "đã huỷ" với đơn tiền mặt (từ chờ thu chuyển sang). */
    public const CANCELLED_STATUSES = ['failed', 'cancelled_payment'];

    public function enabled(): bool
    {
        return (bool) CashPaymentConfig::get('guard_enabled', true);
    }

    /** Gọi khi đơn tiền mặt đang chờ bị huỷ (OrderObserver): ghi lúc / nguồn huỷ rồi kiểm tra ngưỡng của đối tác và khách. */
    public function recordCancellation(Order $order): void
    {
        $user = auth()->user();
        $source = $order->cancel_source ?: match (true) {
            $user instanceof User && $user->isSuperAdmin() => self::SOURCE_ADMIN,
            $user instanceof User && filled($user->partner_id) => self::SOURCE_PARTNER,
            default => self::SOURCE_SYSTEM,
        };
        $order->forceFill(['cancelled_at' => $order->cancelled_at ?: now(), 'cancel_source' => $source])->saveQuietly();

        if (! $this->enabled()) {
            return;
        }

        try {
            // Chỉ đơn do chính đối tác huỷ mới tính vào ngưỡng khoá phòng của đối tác.
            if ($source === self::SOURCE_PARTNER && ($partnerId = PayOsAccountResolver::partnerIdForOrder($order))) {
                $this->checkPartner($partnerId);
            }

            $this->checkCustomer($order);
        } catch (\Throwable $e) {
            Log::error('Kiểm tra tỷ lệ huỷ đơn tiền mặt lỗi', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{day: int, month: int} số đơn tiền mặt do CHÍNH đối tác huỷ hôm nay / tháng này. */
    public function partnerCounts(string $partnerId): array
    {
        return $this->counts(fn ($q) => $q->where('partner_id', $partnerId)->where('cancel_source', self::SOURCE_PARTNER));
    }

    /** @return array{day: int, month: int} số đơn tiền mặt bị huỷ hôm nay / tháng này của khách (cùng số điện thoại). */
    public function customerCounts(?string $phone): array
    {
        $digits = self::digits($phone);

        // So trên số đã bỏ dấu cách / chấm / gạch / "+" (đơn có thể lưu "0912 333 444", "+84912333444"...).
        return $digits === '' ? ['day' => 0, 'month' => 0] : $this->counts(fn ($q) => $q->whereIn(
            \Illuminate\Support\Facades\DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(buyer_phone, ' ', ''), '.', ''), '-', ''), '+', '')"),
            self::phoneVariants($digits),
        ));
    }

    public function isPartnerLocked(?string $partnerId): bool
    {
        return $partnerId !== null && Partner::withTrashed()->whereKey($partnerId)->whereNotNull('cancel_locked_at')->exists();
    }

    private function checkPartner(string $partnerId): void
    {
        $partner = Partner::withTrashed()->find($partnerId);

        if (! $partner || $partner->cancel_locked_at || $partner->isPlatformPartner() || $partner->isSystemPartner()) {
            return;
        }

        $counts = $this->partnerCounts($partnerId);

        if ($this->reached($counts, 'partner')) {
            $this->lock($partner, $counts);
        }
    }

    private function checkCustomer(Order $order): void
    {
        $counts = $this->customerCounts($order->buyer_phone);
        $day = (int) CashPaymentConfig::get('customer_day_limit', 3);
        $month = (int) CashPaymentConfig::get('customer_month_limit', 15);

        // Chỉ thông báo (không khoá / không chặn), đúng một lần ở lần huỷ làm chạm ngưỡng — không báo lại mỗi lần huỷ sau đó.
        if (($day > 0 && $counts['day'] === $day) || ($month > 0 && $counts['month'] === $month)) {
            $body = 'Khách ' . ($order->buyer_name ?: '') . ' (' . $order->buyer_phone . ') có ' . $counts['day'] . ' đơn tiền mặt bị huỷ hôm nay, ' . $counts['month']
                . ' đơn trong tháng (đơn gần nhất #' . $order->order_code . '). Lưu ý khi nhận đặt phòng tiền mặt của khách này.';
            $this->notifySuperAdmins('cash_cancel_customer_limit', 'Khách có nhiều đơn tiền mặt bị huỷ', $body, ['buyer_phone' => $order->buyer_phone, 'order_code' => (string) $order->order_code]);

            $partnerId = PayOsAccountResolver::partnerIdForOrder($order);
            if ($partnerId && ($partner = Partner::withTrashed()->find($partnerId))) {
                app(PartnerNotifier::class)->send($partner, 'cash_cancel_customer_limit', 'Khách có nhiều đơn tiền mặt bị huỷ', $body, ['order_code' => (string) $order->order_code], 'warning', false);
            }
        }
    }

    /** @param array{day: int, month: int} $counts */
    private function reached(array $counts, string $who): bool
    {
        $day = (int) CashPaymentConfig::get($who . '_day_limit', 3);
        $month = (int) CashPaymentConfig::get($who . '_month_limit', 15);

        return ($day > 0 && $counts['day'] >= $day) || ($month > 0 && $counts['month'] >= $month);
    }

    /** @return array{day: int, month: int} */
    private function counts(callable $scope): array
    {
        $now = now();
        $base = fn () => tap(Order::withoutGlobalScopes()->where('payment_method', BookingPaymentPolicy::CASH)->whereNotNull('cancelled_at'), $scope);

        return [
            'day' => $base()->whereBetween('cancelled_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
            'month' => $base()->whereBetween('cancelled_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->count(),
        ];
    }

    /** Các phòng của đối tác (mọi chi nhánh / khu vực của đối tác). */
    private function partnerProducts(Partner $partner)
    {
        $categoryIds = Category::withoutGlobalScopes()->where('partner_id', $partner->id)->pluck('id');
        $categoryIds = $categoryIds->merge(Category::withoutGlobalScopes()->whereIn('parent_id', $categoryIds)->pluck('id'))->unique();

        return Product::withoutGlobalScopes()->whereHas('categories', fn ($q) => $q->withoutGlobalScopes()->whereIn('categories.id', $categoryIds));
    }

    /** @param array{day: int, month: int} $counts */
    public function lock(Partner $partner, array $counts, ?User $by = null): void
    {
        $productIds = $this->partnerProducts($partner)->where('is_activated', true)->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $reason = 'Tỷ lệ huỷ đơn tiền mặt cao: ' . $counts['day'] . ' đơn hôm nay, ' . $counts['month'] . ' đơn trong tháng (ngưỡng '
            . CashPaymentConfig::get('partner_day_limit', 3) . '/ngày, ' . CashPaymentConfig::get('partner_month_limit', 15) . '/tháng).';

        $partner->forceFill(['cancel_locked_at' => now(), 'cancel_lock_reason' => $reason, 'cancel_locked_product_ids' => $productIds])->saveQuietly();
        // Cập nhật thẳng (không qua sự kiện model) để không kích hoạt luồng duyệt phòng.
        Product::withoutGlobalScopes()->whereIn('id', $productIds)->update(['is_activated' => false]);

        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by?->id,
            'note' => 'Tạm khoá ' . count($productIds) . ' phòng đang đăng. ' . $reason,
        ]);

        Log::warning('Khoá phòng đối tác do huỷ đơn tiền mặt cao', ['partner_id' => $partner->id, 'rooms' => count($productIds), 'counts' => $counts]);

        app(PartnerNotifier::class)->send($partner, 'cash_cancel_locked', 'Tạm khoá phòng do huỷ nhiều đơn tiền mặt',
            $reason . ' ' . count($productIds) . ' phòng đang đăng đã bị tạm ẩn và không thể tự mở lại. Vui lòng liên hệ 365 HOME để được xem xét mở khoá.',
            [], 'danger');

        $this->notifySuperAdmins('cash_cancel_locked', 'Đã khoá phòng của đối tác — huỷ nhiều đơn tiền mặt',
            ($partner->legal_name ?: $partner->name) . ': ' . $reason . ' Đã tạm ẩn ' . count($productIds) . ' phòng. Mở khoá ở trang sửa đối tác.',
            ['partner_id' => $partner->id]);
    }

    /** Super Admin mở khoá: mở lại đúng các phòng đã đăng lúc khoá (phòng đã xoá / chưa duyệt thì bỏ qua). @return int số phòng mở lại */
    public function release(Partner $partner, User $by): int
    {
        if (! $partner->cancel_locked_at) {
            return 0;
        }

        $ids = (array) ($partner->cancel_locked_product_ids ?? []);
        $reopened = $ids === [] ? 0 : Product::withoutGlobalScopes()->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'approved'))->update(['is_activated' => true]);

        $partner->forceFill(['cancel_locked_at' => null, 'cancel_lock_reason' => null, 'cancel_locked_product_ids' => null])->saveQuietly();

        PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $by->id,
            'note' => 'Mở khoá phòng (huỷ đơn tiền mặt cao): mở lại ' . $reopened . ' phòng.',
        ]);

        app(PartnerNotifier::class)->send($partner, 'cash_cancel_released', 'Đã mở khoá phòng',
            '365 HOME đã mở khoá: ' . $reopened . ' phòng được đăng lại.', [], 'success');

        return $reopened;
    }

    /** Phòng thuộc đối tác đang bị khoá do huỷ đơn tiền mặt cao (Product::saving không cho bật). */
    public function productLocked(Product $product): bool
    {
        $categoryId = $product->categories()->withoutGlobalScopes()->value('categories.id');

        return $this->isPartnerLocked(PayOsAccountResolver::partnerIdForCategory($categoryId ? (int) $categoryId : null));
    }

    /** @return array<string, mixed> trạng thái cho trang / API đối tác */
    public function partnerStatus(Partner $partner): array
    {
        return [
            'locked' => $partner->cancel_locked_at !== null,
            'locked_at' => $partner->cancel_locked_at?->toIso8601String(),
            'reason' => $partner->cancel_lock_reason,
            'locked_rooms' => count((array) ($partner->cancel_locked_product_ids ?? [])),
            'cancelled_today' => $this->partnerCounts((string) $partner->id)['day'],
            'cancelled_this_month' => $this->partnerCounts((string) $partner->id)['month'],
            'day_limit' => (int) CashPaymentConfig::get('partner_day_limit', 3),
            'month_limit' => (int) CashPaymentConfig::get('partner_month_limit', 15),
        ];
    }

    private function notifySuperAdmins(string $type, string $title, string $body, array $data = []): void
    {
        try {
            app(\App\Services\AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(), $title, $body, ['type' => $type] + $data, 'heroicon-o-shield-exclamation', 'danger',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function digits(?string $phone): string
    {
        return (string) preg_replace('/\D+/', '', (string) $phone);
    }

    /** Số điện thoại lưu ở đơn có thể là 09xx hoặc 849xx: so khớp cả hai dạng. @return string[] */
    private static function phoneVariants(string $digits): array
    {
        $local = str_starts_with($digits, '84') ? '0' . substr($digits, 2) : $digits;
        $intl = str_starts_with($local, '0') ? '84' . substr($local, 1) : $local;

        return array_values(array_unique([$digits, $local, $intl]));
    }
}
