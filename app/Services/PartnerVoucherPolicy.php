<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\VoucherBlock;
use App\Settings\VoucherScopeSettings;
use App\Support\VoucherScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * "Voucher theo đối tác" — 1 màn hình cho cả 2 tầng đang có:
 *  ② Khách được dùng loại voucher nào ở đối tác (mọi ngày):
 *     - Quà điểm danh / trò chơi: ghi vào App\Settings\VoucherScopeSettings (gắn phạm vi khi TẶNG mã — App\Support\VoucherScope)
 *     - Loại bị bỏ tick: 1 dòng voucher_blocks "all_days" (chặn khi DÙNG mã — kể cả mã đã tặng trước đó).
 *  ③ Đợt ngừng nhận: các dòng voucher_blocks còn lại (all_days = false) của đối tác.
 * Logic chặn khi áp mã vẫn ở App\Services\VoucherBlockService.
 */
class PartnerVoucherPolicy
{
    /** Nhóm voucher quà tặng của 365home ↔ phạm vi trong VoucherScopeSettings. */
    public const GIFT_SCOPES = [
        VoucherBlock::GROUP_CHECKIN => VoucherScope::CHECKIN,
        VoucherBlock::GROUP_GAME    => VoucherScope::MINIGAME,
    ];

    public const BASE_NAME = 'Không nhận (thiết lập ở bước ②)';

    public const LONG_PERIOD_DAYS = 60;

    /** @var array<string, array> */
    private static array $overview = [];

    // ── Dữ liệu cơ bản ──────────────────────────────────────────────────────

    /** @return array<int, string> chi nhánh gốc của đối tác (id => tên). */
    public function branches(string $partnerId): array
    {
        return Category::query()->withoutGlobalScopes()
            ->whereNull('parent_id')->where('category_type', 'product')->where('partner_id', $partnerId)
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** 1 phòng của đối tác (trong các chi nhánh đã chọn) — để đọc ngày lễ bảng giá khi xem trước. */
    public function sampleRoom(string $partnerId, array $categoryIds = []): Product
    {
        return Product::withoutGlobalScopes()
            ->where('partner_id', $partnerId)
            ->whereNull('deleted_at')
            ->when($categoryIds !== [], fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)->orWhereIn('categories.parent_id', $categoryIds)))
            ->orderBy('name')
            ->first() ?? (new Product())->forceFill(['partner_id' => $partnerId, 'name' => 'Phòng']);
    }

    public function giftAccepted(string $scope, string $partnerId, array $branchIds): bool
    {
        if (! VoucherScope::isConfigured($scope)) {
            return true; // chưa chọn đối tác nào = mọi đối tác đều nhận
        }

        $settings = app(VoucherScopeSettings::class);

        return in_array($partnerId, (array) $settings->{"{$scope}_partner_ids"}, true)
            || array_intersect(array_map('intval', (array) $settings->{"{$scope}_category_ids"}), $branchIds) !== [];
    }

    // ── DB → form ───────────────────────────────────────────────────────────

    public function load(Partner $partner): array
    {
        $branchIds = array_map('intval', array_keys($this->branches($partner->id)));
        $settings  = app(VoucherScopeSettings::class);
        $blocks    = VoucherBlock::query()->where('partner_id', $partner->id)->orderBy('id')->get();
        $base      = $blocks->where('all_days', true);
        $periods   = $blocks->where('all_days', false);

        $giftCats = collect(self::GIFT_SCOPES)
            ->flatMap(fn ($scope) => array_intersect(array_map('intval', (array) $settings->{"{$scope}_category_ids"}), $branchIds))
            ->unique()->values()->all();
        $blockCats = $blocks->flatMap(fn (VoucherBlock $b) => array_map('intval', (array) $b->category_ids))->unique()->values()->all();
        $cats      = $giftCats ?: array_values(array_intersect($blockCats, $branchIds));

        $baseGroups = $base->where('is_active', true)->flatMap(fn (VoucherBlock $b) => (array) $b->blocked_groups)->unique()->all();
        $accept     = collect(array_keys(VoucherBlock::GROUPS))
            ->reject(fn ($g) => in_array($g, $baseGroups, true))
            ->reject(fn ($g) => isset(self::GIFT_SCOPES[$g]) && ! $this->giftAccepted(self::GIFT_SCOPES[$g], $partner->id, $branchIds))
            ->values()->all();

        return [
            'branch_mode'  => $cats ? 'some' : 'all',
            'category_ids' => array_map('strval', $cats),
            'accept'       => $accept,
            'periods'      => $periods->mapWithKeys(fn (VoucherBlock $b) => [(string) Str::uuid() => $this->periodToForm($b)])->all(),
        ];
    }

    private function periodToForm(VoucherBlock $b): array
    {
        $key = fn () => (string) Str::uuid();

        return [
            'id'                       => $b->id,
            'name'                     => $b->name,
            'is_active'                => (bool) $b->is_active,
            'ranges'                   => collect((array) $b->date_ranges)->mapWithKeys(fn ($r) => [$key() => [
                'name' => ($r['name'] ?? null) === $b->name ? null : ($r['name'] ?? null),
                'from' => $r['from'] ?? null,
                'to'   => $r['to'] ?? null,
            ]])->all(),
            'blocked_groups'           => array_values((array) $b->blocked_groups),
            'use_price_board_holidays' => (bool) $b->use_price_board_holidays,
            'weekdays'                 => array_map('strval', (array) $b->weekdays),
            'recurring_ranges'         => collect((array) $b->recurring_ranges)->mapWithKeys(fn ($r) => [$key() => [
                'name' => $r['name'] ?? null,
                'from' => VoucherBlock::dm($r['from'] ?? null),
                'to'   => VoucherBlock::dm($r['to'] ?? null),
            ]])->all(),
            'except_dates'             => collect((array) $b->except_dates)->mapWithKeys(fn ($d) => [$key() => $d])->all(),
            'valid_from'               => $b->valid_from?->toDateString(),
            'valid_to'                 => $b->valid_to?->toDateString(),
            'mixed_mode'               => $b->mixed_mode ?: VoucherBlock::MIXED_ANY,
            'note'                     => $b->note,
        ];
    }

    // ── form → quy tắc (dùng cho cả lưu lẫn xem trước) ─────────────────────

    /** Chi nhánh đã chọn ở bước ① (rỗng = mọi chi nhánh), chỉ giữ chi nhánh của đối tác. */
    public function selectedBranches(Partner $partner, array $state): array
    {
        if (($state['branch_mode'] ?? 'all') !== 'some') {
            return [];
        }

        return array_values(array_intersect(
            array_map('intval', (array) ($state['category_ids'] ?? [])),
            array_map('intval', array_keys($this->branches($partner->id))),
        ));
    }

    /**
     * Quy tắc (chưa lưu) dựng từ form: dòng "không nhận" của bước ② + các đợt ở bước ③. Dữ liệu dở dang bị bỏ qua.
     *
     * @return Collection<int, VoucherBlock>
     */
    public function rulesFromState(Partner $partner, array $state, bool $includeBase = true): Collection
    {
        $cats  = $this->selectedBranches($partner, $state);
        $rules = collect();

        $unticked = array_values(array_diff(array_keys(VoucherBlock::GROUPS), (array) ($state['accept'] ?? [])));
        if ($includeBase && $unticked !== []) {
            $rules->push(new VoucherBlock([
                'partner_id' => $partner->id, 'name' => self::BASE_NAME, 'is_active' => true, 'all_days' => true,
                'category_ids' => $cats, 'blocked_groups' => $unticked,
            ]));
        }

        foreach ((array) ($state['periods'] ?? []) as $period) {
            $rules->push(new VoucherBlock(['partner_id' => $partner->id, 'category_ids' => $cats] + $this->periodAttributes((array) $period)));
        }

        return $rules;
    }

    /** Thuộc tính voucher_blocks của 1 đợt (đã chuẩn hoá, bỏ dòng dở dang). */
    public function periodAttributes(array $p): array
    {
        $name = trim((string) ($p['name'] ?? '')) ?: 'Đợt ngừng nhận';

        return [
            'name'                     => $name,
            'is_active'                => (bool) ($p['is_active'] ?? true),
            'all_days'                 => false,
            'use_price_board_holidays' => (bool) ($p['use_price_board_holidays'] ?? false),
            'date_ranges'              => collect((array) ($p['ranges'] ?? []))
                ->filter(fn ($r) => ! empty($r['from']) && ! empty($r['to']))
                ->map(fn ($r) => [
                    'name' => trim((string) ($r['name'] ?? '')) ?: $name,
                    'from' => Carbon::parse($r['from'])->toDateString(),
                    'to'   => Carbon::parse($r['to'])->toDateString(),
                ])
                ->filter(fn ($r) => $r['from'] <= $r['to'])
                ->values()->all(),
            'weekdays'                 => array_values(array_map('intval', (array) ($p['weekdays'] ?? []))),
            'recurring_ranges'         => collect((array) ($p['recurring_ranges'] ?? []))
                ->map(fn ($r) => [
                    'name' => trim((string) ($r['name'] ?? '')) ?: $name,
                    'from' => VoucherBlock::normalizeMonthDay((string) ($r['from'] ?? '')),
                    'to'   => VoucherBlock::normalizeMonthDay((string) ($r['to'] ?? '')),
                ])
                ->filter(fn ($r) => $r['from'] && $r['to'])
                ->values()->all(),
            'except_dates'             => collect((array) ($p['except_dates'] ?? []))->filter()->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all(),
            'valid_from'               => ! empty($p['valid_from']) ? Carbon::parse($p['valid_from'])->toDateString() : null,
            'valid_to'                 => ! empty($p['valid_to']) ? Carbon::parse($p['valid_to'])->toDateString() : null,
            'blocked_groups'           => array_values(array_intersect((array) ($p['blocked_groups'] ?? []), array_keys(VoucherBlock::GROUPS))),
            'mixed_mode'               => ($p['mixed_mode'] ?? null) === VoucherBlock::MIXED_ALL ? VoucherBlock::MIXED_ALL : VoucherBlock::MIXED_ANY,
            'note'                     => $p['note'] ?? null,
        ];
    }

    // ── Kiểm tra (chặn lưu) ─────────────────────────────────────────────────

    /** @return array<string, string> lỗi theo khoá form (data.…). */
    public function errors(Partner $partner, array $state): array
    {
        $errors = [];
        $today  = now()->toDateString();

        if (($state['branch_mode'] ?? 'all') === 'some' && $this->selectedBranches($partner, $state) === []) {
            $errors['data.category_ids'] = 'Chọn ít nhất 1 chi nhánh (hoặc chọn "Mọi chi nhánh").';
        }

        // Bỏ tick quà tặng ở đối tác cuối cùng → danh sách trống = quà tặng dùng được MỌI nơi.
        foreach (self::GIFT_SCOPES as $group => $scope) {
            if (in_array($group, (array) ($state['accept'] ?? []), true) || ! VoucherScope::isConfigured($scope)) {
                continue;
            }
            [$pids, $cids] = $this->giftListsWithout($partner, $scope);
            if ($pids === [] && $cids === []) {
                $label                 = VoucherBlock::GROUPS[$group];
                $errors['data.accept'] = "{$this->partnerName($partner)} đang là đối tác DUY NHẤT nhận \"{$label}\". Bỏ chọn thì danh sách trống — "
                    . "\"{$label}\" sẽ dùng được ở MỌI đối tác. Hãy thiết lập cho đối tác khác nhận trước.";
            }
        }

        foreach ((array) ($state['periods'] ?? []) as $key => $p) {
            $attrs = $this->periodAttributes((array) $p);
            $path  = "data.periods.{$key}";

            if (trim((string) ($p['name'] ?? '')) === '') {
                $errors["{$path}.name"] = 'Đặt tên cho đợt (VD: Festival Hoa, Tết Nguyên Đán).';
            }

            foreach ((array) ($p['ranges'] ?? []) as $rk => $r) {
                if (empty($r['from']) || empty($r['to'])) {
                    $errors["{$path}.ranges.{$rk}." . (empty($r['from']) ? 'from' : 'to')] = 'Chọn đủ từ ngày – đến ngày.';
                } elseif (Carbon::parse($r['to'])->lt(Carbon::parse($r['from']))) {
                    $errors["{$path}.ranges.{$rk}.to"] = '"Đến ngày" phải sau hoặc bằng "Từ ngày".';
                } elseif (empty($p['id']) && Carbon::parse($r['to'])->toDateString() < $today) {
                    $errors["{$path}.ranges.{$rk}.to"] = 'Khoảng ngày này đã qua — đợt mới phải kết thúc từ hôm nay trở đi.';
                }
            }

            foreach ((array) ($p['recurring_ranges'] ?? []) as $rk => $r) {
                foreach (['from', 'to'] as $f) {
                    if (VoucherBlock::normalizeMonthDay((string) ($r[$f] ?? '')) === null) {
                        $errors["{$path}.recurring_ranges.{$rk}.{$f}"] = 'Nhập ngày/tháng dạng dd/mm, VD 24/12.';
                    }
                }
            }

            $hasSchedule = $attrs['date_ranges'] || $attrs['weekdays'] || $attrs['recurring_ranges'] || $attrs['use_price_board_holidays'];
            if (! $hasSchedule && ! isset($errors["{$path}.ranges"])) {
                $errors["{$path}.ranges"] = 'Nhập ít nhất 1 khoảng ngày (hoặc chọn thứ / ngày lặp lại / ngày lễ bảng giá ở "Tuỳ chọn nâng cao").';
            }

            if ($attrs['blocked_groups'] === []) {
                $errors["{$path}.blocked_groups"] = 'Chọn ít nhất 1 loại voucher bị ngừng.';
            }

            if ($attrs['valid_from'] && $attrs['valid_to'] && $attrs['valid_to'] < $attrs['valid_from']) {
                $errors["{$path}.valid_to"] = 'Phải sau hoặc bằng ngày bắt đầu.';
            }
        }

        return $errors;
    }

    /**
     * Cảnh báo (không chặn lưu) cho 1 đợt: đã qua, dài bất thường, chồng ngày với đợt khác, đã có đơn dùng mã trong đợt.
     *
     * @return list<string>
     */
    public function periodWarnings(Partner $partner, array $period, array $allPeriods, ?string $selfKey = null): array
    {
        $attrs    = $this->periodAttributes($period);
        $today    = now()->toDateString();
        $warnings = [];

        if ($attrs['date_ranges'] !== [] && collect($attrs['date_ranges'])->every(fn ($r) => $r['to'] < $today)
            && ! $attrs['weekdays'] && ! $attrs['recurring_ranges'] && ! $attrs['use_price_board_holidays']) {
            $warnings[] = 'Đợt này đã qua — có thể xoá cho gọn.';
        }

        foreach ($attrs['date_ranges'] as $r) {
            $days = Carbon::parse($r['from'])->diffInDays(Carbon::parse($r['to'])) + 1;
            if ($days > self::LONG_PERIOD_DAYS) {
                $warnings[] = 'Khoảng ' . VoucherBlock::dmy($r['from']) . ' → ' . VoucherBlock::dmy($r['to']) . " dài {$days} ngày — kiểm tra lại có gõ nhầm năm không.";
            }
        }

        foreach ($allPeriods as $key => $other) {
            if ((string) $key === (string) $selfKey) {
                continue;
            }
            $o = $this->periodAttributes((array) $other);
            foreach ($attrs['date_ranges'] as $r) {
                foreach ($o['date_ranges'] as $or) {
                    if ($r['from'] <= $or['to'] && $or['from'] <= $r['to']) {
                        $warnings[] = "Trùng ngày với đợt \"{$o['name']}\" (" . VoucherBlock::dmy($or['from']) . ' → ' . VoucherBlock::dmy($or['to']) . ').';
                        break 2;
                    }
                }
            }
        }

        foreach ($attrs['date_ranges'] as $r) {
            if ($r['to'] < $today) {
                continue;
            }
            $orders = $this->ordersUsingVoucher($partner->id, $r['from'], $r['to']);
            if ($orders > 0) {
                $warnings[] = "Đã có {$orders} đơn dùng voucher nhận phòng trong " . VoucherBlock::dmy($r['from']) . ' → ' . VoucherBlock::dmy($r['to'])
                    . ' — các đơn này giữ nguyên, chỉ đơn đặt mới bị chặn.';
            }
        }

        return array_values(array_unique($warnings));
    }

    /** Đơn còn hiệu lực (đã / chờ thanh toán) có dùng mã, nhận phòng trong khoảng ngày. */
    public function ordersUsingVoucher(string $partnerId, string $from, string $to): int
    {
        return DB::table('orders')
            ->where('orders.partner_id', $partnerId)
            ->whereIn('orders.status', ['paid', 'pending'])
            ->whereNull('orders.cancelled_at')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('orders.coupon_code')->where('orders.coupon_code', '!=', ''))
                ->orWhere(fn ($q) => $q->whereNotNull('orders.coupon_codes')->whereNotIn('orders.coupon_codes', ['[]', 'null', ''])))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('order_items')
                ->whereColumn('order_items.order_id', 'orders.id')
                ->whereDate('order_items.checkin_date', '>=', $from)
                ->whereDate('order_items.checkin_date', '<=', $to))
            ->count();
    }

    // ── Lưu ─────────────────────────────────────────────────────────────────

    public function save(Partner $partner, array $state, ?string $userId = null): void
    {
        if ($errors = $this->errors($partner, $state)) {
            throw ValidationException::withMessages($errors);
        }

        $cats   = $this->selectedBranches($partner, $state);
        $accept = array_values(array_intersect((array) ($state['accept'] ?? []), array_keys(VoucherBlock::GROUPS)));

        DB::transaction(function () use ($partner, $state, $cats, $accept, $userId) {
            // ② Loại không nhận → 1 dòng "mọi ngày" (chặn cả mã đã tặng trước đây).
            $unticked = array_values(array_diff(array_keys(VoucherBlock::GROUPS), $accept));
            $base     = VoucherBlock::query()->where('partner_id', $partner->id)->where('all_days', true)->orderBy('id')->get();
            if ($unticked === []) {
                $base->each->delete();
            } else {
                $row = $base->shift() ?? new VoucherBlock(['partner_id' => $partner->id, 'created_by' => $userId]);
                $row->fill([
                    'name' => self::BASE_NAME, 'is_active' => true, 'all_days' => true, 'use_price_board_holidays' => false,
                    'category_ids' => $cats, 'date_ranges' => [], 'weekdays' => [], 'recurring_ranges' => [], 'except_dates' => [],
                    'valid_from' => null, 'valid_to' => null, 'blocked_groups' => $unticked, 'mixed_mode' => VoucherBlock::MIXED_ANY,
                ])->save();
                $base->each->delete();
            }

            // ③ Đợt ngừng nhận.
            $keep = [];
            foreach ((array) ($state['periods'] ?? []) as $p) {
                $row = ! empty($p['id'])
                    ? VoucherBlock::query()->where('partner_id', $partner->id)->where('all_days', false)->find($p['id'])
                    : null;
                $row ??= new VoucherBlock(['partner_id' => $partner->id, 'created_by' => $userId]);
                $row->fill(['category_ids' => $cats] + $this->periodAttributes((array) $p))->save();
                $keep[] = $row->id;
            }
            VoucherBlock::query()->where('partner_id', $partner->id)->where('all_days', false)->whereNotIn('id', $keep ?: [0])->get()->each->delete();

            // ② Quà tặng 365home: danh sách nơi nhận (chỉ đụng tới khi danh sách đang được dùng — trống = mọi nơi). Ghi CUỐI CÙNG:
            // cms_settings là MyISAM, không rollback theo transaction.
            $settings = app(VoucherScopeSettings::class);
            foreach (self::GIFT_SCOPES as $group => $scope) {
                if (! VoucherScope::isConfigured($scope)) {
                    continue;
                }
                [$pids, $cids] = $this->giftListsWithout($partner, $scope);
                if (in_array($group, $accept, true)) {
                    $cats ? array_push($cids, ...$cats) : $pids[] = $partner->id;
                }
                $settings->{"{$scope}_partner_ids"}  = array_values(array_unique(array_map('strval', $pids)));
                $settings->{"{$scope}_category_ids"} = array_values(array_unique(array_map('intval', $cids)));
            }
            $settings->save();
        });

        unset(self::$overview[$partner->id]);
        app(VoucherBlockService::class)->clearCache();
    }

    /**
     * Chỉ đổi bước ② (loại voucher khách dùng được mọi ngày) — giữ nguyên chi nhánh và các đợt. Dùng cho thao tác nhanh /
     * hàng loạt ở danh sách. $mode: enable = thêm $groups, disable = bỏ $groups, set = đúng bằng $groups.
     */
    public function updateAccept(Partner $partner, array $groups, string $mode = 'set', ?string $userId = null): void
    {
        $state  = $this->load($partner);
        $groups = array_values(array_unique(array_intersect($groups, array_keys(VoucherBlock::GROUPS))));

        $state['accept'] = match ($mode) {
            'enable'  => array_values(array_unique(array_merge($state['accept'], $groups))),
            'disable' => array_values(array_diff($state['accept'], $groups)),
            default   => $groups,
        };

        $this->save($partner, $state, $userId);
    }

    /** Danh sách nơi nhận quà $scope sau khi gỡ đối tác này ra (cả mức đối tác lẫn chi nhánh). */
    private function giftListsWithout(Partner $partner, string $scope): array
    {
        $settings  = app(VoucherScopeSettings::class);
        $branchIds = array_map('intval', array_keys($this->branches($partner->id)));

        return [
            array_values(array_diff(array_map('strval', (array) $settings->{"{$scope}_partner_ids"}), [$partner->id])),
            array_values(array_diff(array_map('intval', (array) $settings->{"{$scope}_category_ids"}), $branchIds)),
        ];
    }

    // ── Tổng quan cho danh sách ─────────────────────────────────────────────

    /**
     * @return array{rooms: int, branches: int, accept: list<string>, upcoming: list<string>, today: array{0: string, 1: string}}
     */
    public function overview(Partner $partner): array
    {
        if (isset(self::$overview[$partner->id])) {
            return self::$overview[$partner->id];
        }

        $state  = $this->load($partner);
        $cats   = $this->selectedBranches($partner, $state);
        $rules  = $this->rulesFromState($partner, $state, includeBase: false)->where('is_active', true);
        $room   = $this->sampleRoom($partner->id, $cats);
        $today  = now()->toDateString();
        $svc    = app(VoucherBlockService::class);

        $upcoming = $rules->map(function (VoucherBlock $b) use ($today) {
            $next = collect((array) $b->date_ranges)->filter(fn ($r) => $r['to'] >= $today)->sortBy('from')->first();
            if ($next) {
                return ['sort' => $next['from'], 'label' => "{$b->name}: " . VoucherBlock::dmy($next['from']) . ' → ' . VoucherBlock::dmy($next['to'])];
            }
            if ($b->weekdays || $b->recurring_ranges || $b->use_price_board_holidays) {
                return ['sort' => '9999', 'label' => "{$b->name}: " . $b->scheduleSummary()];
            }

            return null;
        })->filter()->sortBy('sort')->pluck('label')->values()->all();

        $accept = $state['accept'];
        if ($accept === []) {
            $todayState = ['danger', 'Không nhận voucher nào'];
        } else {
            $hit = $rules->first(fn (VoucherBlock $b) => $svc->matchDate($b, $room, $today));
            if ($hit) {
                $match = $svc->matchDate($hit, $room, $today);
                $todayState = ['danger', "Đang ngừng: {$hit->name}" . (! empty($match['to']) ? ' (đến ' . Carbon::parse($match['to'])->format('d/m') . ')' : '')];
            } else {
                $todayState = ['success', 'Đang nhận'];
            }
        }

        return self::$overview[$partner->id] = [
            'rooms'    => Product::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNull('deleted_at')->count(),
            'branches' => count($this->branches($partner->id)),
            'accept'   => $accept,
            'upcoming' => $upcoming,
            'today'    => $todayState,
        ];
    }

    public function partnerName(Partner $partner): string
    {
        return (string) ($partner->common_name ?: $partner->name);
    }

    public static function flushOverview(): void
    {
        self::$overview = [];
    }
}
