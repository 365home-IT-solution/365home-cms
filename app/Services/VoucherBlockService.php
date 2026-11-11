<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VoucherBlock;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;

/**
 * Lịch ngừng nhận voucher (App\Models\VoucherBlock): mã thuộc nhóm bị chặn KHÔNG dùng được cho phòng của chi nhánh
 * trong quy tắc vào những ngày khớp lịch. Gọi ở MỌI chỗ áp mã khi đặt phòng / sửa đơn / kiểm tra mã.
 *
 * $dates là các ngày khách ở: phòng theo ngày = từng ĐÊM (ngày nhận → ngày trước ngày trả), phòng theo giờ = ngày của
 * từng khung. Không có ngày (VD danh sách ví voucher chưa chọn ngày) thì chỉ xét quy tắc "Mọi ngày".
 */
class VoucherBlockService
{
    public const GAME_SOURCES = ['caro', 'scratch_card', 'collection', 'game_points', 'referral'];

    /** @var array<string, Collection<int, VoucherBlock>> */
    private array $rules = [];

    /** @var array<string, array{ids: list<int>, name: string}> */
    private array $branches = [];

    /** @var array<string, Collection> */
    private array $candidates = [];

    /** Nhóm của 1 mã — khớp VoucherBlock::GROUPS. */
    public static function groupOf(Coupon $coupon): string
    {
        if (in_array((string) $coupon->source, self::GAME_SOURCES, true)) {
            return VoucherBlock::GROUP_GAME;
        }

        if ($coupon->auto_issue_tier_id) {
            return VoucherBlock::GROUP_CHECKIN;
        }

        if ($coupon->template_coupon_id || $coupon->membershipTiers()->exists()) {
            return VoucherBlock::GROUP_MEMBERSHIP;
        }

        if ($coupon->partner_id) {
            return VoucherBlock::GROUP_PARTNER;
        }

        return VoucherBlock::GROUP_PLATFORM;
    }

    /** Câu báo cho khách nếu mã bị chặn; null = dùng được. */
    public function blockReason(Coupon $coupon, Product $room, array $dates = []): ?string
    {
        return $this->blockReasonWith($this->rulesFor($room), $coupon, $room, $dates);
    }

    /** Như blockReason() nhưng với danh sách quy tắc cho trước (VD quy tắc chưa lưu — xem trước trên form). */
    public function blockReasonWith(Collection $rules, Coupon $coupon, Product $room, array $dates = []): ?string
    {
        if ($rules->isEmpty()) {
            return null;
        }

        $group = self::groupOf($coupon);
        $dates = collect($dates)->filter()->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->sort()->values()->all();

        foreach ($rules as $rule) {
            if (! in_array($group, (array) $rule->blocked_groups, true)) {
                continue;
            }

            if ($dates === []) {
                // Chưa biết ngày: chỉ "Mọi ngày" còn hiệu lực hôm nay mới chắc chắn bị chặn.
                if ($rule->all_days && $this->inValidity($rule, now()->toDateString())) {
                    return $this->message($rule, $room, null);
                }

                continue;
            }

            $hits = [];
            foreach ($dates as $date) {
                if ($hit = $this->matchDate($rule, $room, $date)) {
                    $hits[$date] = $hit;
                }
            }

            if ($hits === [] || ($rule->mixed_mode === VoucherBlock::MIXED_ALL && count($hits) < count($dates))) {
                continue;
            }

            return $this->message($rule, $room, reset($hits) + ['date' => array_key_first($hits)]);
        }

        return null;
    }

    /**
     * Nhóm voucher bị ngừng nhận vào $date theo $rules (đang bật) — nhóm => tên quy tắc gây chặn.
     *
     * @return array<string, string>
     */
    public function blockedGroupsOn(Collection $rules, Product $room, string $date): array
    {
        $blocked = [];
        foreach ($rules as $rule) {
            if (! $rule->is_active || ! $this->matchDate($rule, $room, $date)) {
                continue;
            }
            foreach ((array) $rule->blocked_groups as $group) {
                $blocked[$group] ??= (string) $rule->name;
            }
        }

        return $blocked;
    }

    /** Ném lỗi validate ở $field nếu mã bị chặn. */
    public function assertAllowed(Coupon $coupon, Product $room, array $dates, string $field): void
    {
        if ($reason = $this->blockReason($coupon, $room, $dates)) {
            throw ValidationException::withMessages([$field => [$reason]]);
        }
    }

    /** Các đêm từ ngày nhận tới trước ngày trả (phòng theo ngày); trả cùng ngày → chính ngày nhận. */
    public static function nights(mixed $checkin, mixed $checkout): array
    {
        if (! $checkin) {
            return [];
        }

        $day = Carbon::parse($checkin)->startOfDay();
        $end = $checkout ? Carbon::parse($checkout)->startOfDay() : $day->copy();

        if ($end->lte($day)) {
            return [$day->toDateString()];
        }

        $dates = [];
        for (; $day->lt($end); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** Ngày ở của 1 đơn đã có (sửa mã ở đơn đang chờ thanh toán). */
    public static function datesFromOrderItems(iterable $items): array
    {
        $dates = [];
        foreach ($items as $item) {
            $checkout = $item->checkout_date ?? null;
            // Khung giờ qua đêm: checkout sang hôm sau nhưng vẫn chỉ tính ngày của khung.
            if ($checkout && $item->checkin_date && Carbon::parse($checkout)->startOfDay()->diffInDays(Carbon::parse($item->checkin_date)->startOfDay()) <= 1) {
                $checkout = null;
            }
            $dates = array_merge($dates, self::nights($item->checkin_date ?? null, $checkout));
        }

        return array_values(array_unique($dates));
    }

    /**
     * Ngày $date có thuộc lịch của $rule không — trả mô tả lý do (để dựng câu báo) hoặc null.
     *
     * @return array{type: string, name?: ?string, from?: string, to?: string}|null
     */
    public function matchDate(VoucherBlock $rule, Product $room, string $date): ?array
    {
        if (! $this->inValidity($rule, $date) || in_array($date, (array) $rule->except_dates, true)) {
            return null;
        }

        if ($rule->all_days) {
            return ['type' => 'all'];
        }

        foreach ((array) $rule->date_ranges as $r) {
            if (! empty($r['from']) && ! empty($r['to']) && $date >= $r['from'] && $date <= $r['to']) {
                return ['type' => 'range', 'name' => $r['name'] ?? null, 'from' => $r['from'], 'to' => $r['to']];
            }
        }

        if ($rule->weekdays && in_array(Carbon::parse($date)->dayOfWeekIso, array_map('intval', (array) $rule->weekdays), true)) {
            return ['type' => 'weekday'];
        }

        $md = substr($date, 5);
        foreach ((array) $rule->recurring_ranges as $r) {
            $from = $r['from'] ?? null;
            $to   = $r['to'] ?? null;
            if ($from && $to && ($from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to))) {
                return ['type' => 'recurring', 'name' => $r['name'] ?? null];
            }
        }

        if ($rule->use_price_board_holidays && $room->exists && ($row = $this->holidayRow($room, $date))) {
            $recurring = ! empty($row['recurring']);
            $year      = substr($date, 0, 4);

            return [
                'type' => 'holiday',
                'name' => $row['name'] ?? null,
                'from' => $recurring ? "{$year}-{$row['from']}" : $row['from'],
                'to'   => $recurring ? "{$year}-{$row['to']}" : $row['to'],
            ];
        }

        return null;
    }

    /**
     * Dòng ngày lễ của bảng giá đang áp cho phòng vào $date (cùng cách chọn bảng với StayPriceResolver). Dòng đúng 1 năm
     * thắng dòng lặp hằng năm — khớp App\Support\HolidayPrices::match().
     */
    public function holidayRow(Product $room, string $date): ?array
    {
        $sync = app(PriceBoardSyncService::class);
        $this->candidates[$room->id] ??= $sync->candidateItems((string) $room->id);
        $item = $sync->pickWinningItem($this->candidates[$room->id], Carbon::parse($date)->startOfDay());

        $md       = substr($date, 5);
        $recurred = null;

        foreach ((array) ($item?->holiday_prices ?? []) as $row) {
            $from = $row['from'] ?? null;
            $to   = $row['to'] ?? null;
            if (! $from || ! $to) {
                continue;
            }

            if (! empty($row['recurring'])) {
                if ($recurred === null && ($from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to))) {
                    $recurred = $row;
                }
            } elseif ($date >= $from && $date <= $to) {
                return $row;
            }
        }

        return $recurred;
    }

    /**
     * Các dòng ngày lễ (chưa qua) trong bảng giá của phòng thuộc đối tác / chi nhánh — để xem trước trên form "Ngày lễ
     * trong bảng giá". Dòng lặp hằng năm hiện theo năm gần nhất.
     *
     * @return list<array{name: string, from: string, to: string, rooms: int}>
     */
    public function upcomingHolidays(string $partnerId, array $categoryIds = []): array
    {
        $rooms = Product::withoutGlobalScopes()
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->when($categoryIds !== [], fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)->orWhereIn('categories.parent_id', $categoryIds)))
            ->pluck('id');

        if ($rooms->isEmpty()) {
            return [];
        }

        $today = now()->toDateString();
        $rows  = [];

        \Modules\Product\App\Models\PriceBoardItem::query()
            ->whereIn('product_id', $rooms)
            ->whereNotNull('holiday_prices')
            ->whereHas('priceBoard', fn ($q) => $q->where('is_template', false)->where('is_active', true))
            ->get(['product_id', 'holiday_prices'])
            ->each(function ($item) use (&$rows, $today) {
                foreach ((array) $item->holiday_prices as $row) {
                    if (empty($row['from']) || empty($row['to'])) {
                        continue;
                    }

                    if (! empty($row['recurring'])) {
                        $year = (int) now()->year;
                        $from = "{$year}-{$row['from']}";
                        $to   = $row['from'] <= $row['to'] ? "{$year}-{$row['to']}" : ($year + 1) . "-{$row['to']}";
                        if ($to < $today) {
                            $from = ($year + 1) . "-{$row['from']}";
                            $to   = $row['from'] <= $row['to'] ? ($year + 1) . "-{$row['to']}" : ($year + 2) . "-{$row['to']}";
                        }
                    } else {
                        [$from, $to] = [$row['from'], $row['to']];
                    }

                    if ($to < $today) {
                        continue;
                    }

                    $key = "{$from}|{$to}";
                    $rows[$key] ??= ['name' => (string) ($row['name'] ?? 'Ngày lễ'), 'from' => $from, 'to' => $to, 'rooms' => []];
                    $rows[$key]['rooms'][$item->product_id] = true;
                }
            });

        return collect($rows)->sortBy('from')->map(fn ($r) => ['rooms' => count($r['rooms'])] + $r)->values()->all();
    }

    /** Quy tắc đang bật áp lên phòng $room (đúng đối tác + chi nhánh). */
    public function rulesFor(Product $room): Collection
    {
        return $this->rules[$room->id] ??= VoucherBlock::query()
            ->where('is_active', true)
            ->where('partner_id', $room->partner_id)
            ->orderBy('id')
            ->get()
            ->filter(function (VoucherBlock $rule) use ($room) {
                $ids = array_map('intval', (array) $rule->category_ids);

                return $ids === [] || array_intersect($ids, $this->branchOf($room)['ids']) !== [];
            })
            ->values();
    }

    public function clearCache(): void
    {
        $this->rules = $this->branches = $this->candidates = [];
    }

    private function inValidity(VoucherBlock $rule, string $date): bool
    {
        return (! $rule->valid_from || $date >= $rule->valid_from->toDateString())
            && (! $rule->valid_to || $date <= $rule->valid_to->toDateString());
    }

    /** @return array{ids: list<int>, name: string} chi nhánh (gồm cả danh mục cha) + tên chi nhánh gốc của phòng. */
    private function branchOf(Product $room): array
    {
        if (isset($this->branches[$room->id])) {
            return $this->branches[$room->id];
        }

        $categories = $room->categories()->with('parent:id,name,parent_id')->get(['categories.id', 'categories.name', 'categories.parent_id']);
        $ids        = $categories->flatMap(fn ($c) => array_filter([(int) $c->id, (int) $c->parent_id]))->unique()->values()->all();
        $root       = $categories->first(fn ($c) => $c->parent_id === null) ?? $categories->first()?->parent ?? $categories->first();

        return $this->branches[$room->id] = ['ids' => $ids, 'name' => $root?->name ?? $room->name];
    }

    private function message(VoucherBlock $rule, Product $room, ?array $hit): string
    {
        $branch = $this->branchOf($room)['name'];

        if ($hit === null || $hit['type'] === 'all') {
            return "Voucher này không áp dụng tại {$branch}.";
        }

        $date = Carbon::parse($hit['date']);
        $when = match ($hit['type']) {
            'range', 'holiday' => ! empty($hit['name'])
                ? "trong dịp {$hit['name']} (" . VoucherBlock::dmy($hit['from']) . ' – ' . VoucherBlock::dmy($hit['to']) . ')'
                : 'từ ' . VoucherBlock::dmy($hit['from']) . ' đến ' . VoucherBlock::dmy($hit['to']),
            'weekday'   => 'vào ' . VoucherBlock::WEEKDAYS[$date->dayOfWeekIso] . ', ' . $date->format('d/m/Y'),
            default     => trim('vào ngày ' . $date->format('d/m/Y') . (! empty($hit['name']) ? " ({$hit['name']})" : '')),
        };

        return "Voucher này không áp dụng cho {$branch} {$when}.";
    }
}
