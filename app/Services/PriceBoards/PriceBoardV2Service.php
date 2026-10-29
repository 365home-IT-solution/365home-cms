<?php

declare(strict_types=1);

namespace App\Services\PriceBoards;

use App\Models\User;
use App\Services\PriceBoardConflictException;
use App\Services\PriceBoardSyncService;
use App\Services\RoomSlotRandomizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardSheet;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;

/**
 * BẢNG GIÁ v2 (giai đoạn 1) — nghiệp vụ dùng chung cho API (PriceBoardController) và admin web:
 *  - chuẩn hoá body v1 (branch_id + items[] / product_ids[]) và v2 (scope + sheets[]) về cùng 1 "spec";
 *  - kiểm tra + tính (không ghi) qua PriceBoardResolver: phòng nào nhận bộ giá nào, xung đột, summary;
 *  - lưu bảng/bộ giá rồi SINH price_board_items cho từng phòng và gọi động cơ ghi giá có sẵn
 *    (PriceBoardSyncService) — nên đồng bộ theo lịch, khôi phục khi hết hạn, giá gốc... giữ nguyên v1;
 *  - xem trước, nhân bản, bảng dựng sẵn (preset).
 *
 * Giai đoạn 1: days_of_week / priority bị từ chối (422) — chỉ mở khi giá được tính theo ngày khách ở.
 */
class PriceBoardV2Service
{
    private const GD2_FIELDS = ['days_of_week', 'priority'];

    public function __construct(
        private PriceBoardSyncService $sync,
        private PriceBoardResolver $resolver,
        private RoomSlotRandomizer $slots,
    ) {
    }

    // ════════════════════════════════════════════════════════════════════
    // 1. Chuẩn hoá body → spec
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param array<string,mixed> $data  body đã qua validate của controller
     * @param string $partnerId          đối tác sở hữu bảng
     * @param PriceBoard|null $existing  bảng đang sửa (null = tạo mới)
     * @param bool $dryRun               true = xem trước: không tạo khung giờ mới
     * @return array<string,mixed> spec
     *
     * @throws ValidationException
     */
    public function specFromRequest(array $data, string $partnerId, User $user, ?PriceBoard $existing = null, bool $dryRun = false): array
    {
        $phase2 = PriceBoard::phase2Enabled();

        foreach (self::GD2_FIELDS as $field) {
            if (! $phase2 && array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== []) {
                throw ValidationException::withMessages([
                    $field => ['Chưa hỗ trợ ở giai đoạn này — chỉ mở khi giá được tính theo ngày khách ở.'],
                ]);
            }
        }

        if ($phase2 && ! empty($data['days_of_week'])) {
            $days = array_values(array_unique(array_map('intval', (array) $data['days_of_week'])));

            if (array_diff($days, [1, 2, 3, 4, 5, 6, 7])) {
                throw ValidationException::withMessages(['days_of_week' => ['Thứ chỉ nhận 1 (Thứ 2) … 7 (Chủ nhật).']]);
            }

            sort($days);
        }

        if ($phase2 && isset($data['priority']) && ((int) $data['priority'] < 0 || (int) $data['priority'] > 100)) {
            throw ValidationException::withMessages(['priority' => ['Độ ưu tiên từ 0 đến 100.']]);
        }

        $isV1 = ! array_key_exists('scope', $data) && ! array_key_exists('sheets', $data)
            && (array_key_exists('items', $data) || array_key_exists('product_ids', $data) || array_key_exists('branch_id', $data));

        $base = $existing ? $this->specFromBoard($existing) : [
            'id' => null, 'partner_id' => $partnerId, 'name' => null, 'note' => null,
            'scope' => PriceBoard::SCOPE_ROOMS, 'branch_ids' => [], 'product_ids' => [], 'category_id' => null,
            'is_active' => $isV1, 'start_date' => null, 'end_date' => null, 'sheets' => [], 'source_preset' => null,
        ];

        $spec = $base;

        if ($phase2) {
            if (array_key_exists('days_of_week', $data)) {
                $spec['days_of_week'] = $days ?? [];
            }

            if (array_key_exists('priority', $data) && $data['priority'] !== null) {
                $spec['priority'] = (int) $data['priority'];
            }
        }

        foreach (['name', 'note', 'start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $spec[$field] = $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $spec['is_active'] = (bool) $data['is_active'];
        }

        // ── Phạm vi ────────────────────────────────────────────────────────
        $allowed = $user->allowedCategoryIds();

        if (array_key_exists('scope', $data)) {
            $spec['scope'] = $data['scope'];
        }

        if ($isV1 && array_key_exists('branch_id', $data)) {
            $spec['category_id'] = (int) $data['branch_id'];
            $spec['scope']       = PriceBoard::SCOPE_ROOMS;
            $spec['branch_ids']  = [];
        }

        if (array_key_exists('branch_ids', $data) || ($spec['scope'] === PriceBoard::SCOPE_BRANCHES && ! $existing)) {
            $spec['branch_ids'] = array_values(array_unique(array_map('intval', $data['branch_ids'] ?? [])));
        }

        if ($spec['scope'] === PriceBoard::SCOPE_BRANCHES) {
            if (! $spec['branch_ids']) {
                throw ValidationException::withMessages(['branch_ids' => ['Chọn ít nhất 1 chi nhánh cho phạm vi "branches".']]);
            }

            $spec['category_id'] = count($spec['branch_ids']) === 1 ? $spec['branch_ids'][0] : null;
        } elseif ($spec['scope'] === PriceBoard::SCOPE_PARTNER) {
            $spec['branch_ids']  = [];
            $spec['category_id'] = null;
        } elseif (! $isV1 && array_key_exists('scope', $data)) {
            $spec['category_id'] = null;
        }

        $branchIdsToCheck = array_values(array_filter(array_merge($spec['branch_ids'], $spec['category_id'] ? [$spec['category_id']] : [])));
        if ($branchIdsToCheck) {
            $valid = Category::withoutGlobalScopes()
                ->whereIn('id', $branchIdsToCheck)
                ->whereNull('parent_id')
                ->where('category_type', 'product')
                ->whereNull('deleted_at')
                ->where('partner_id', $partnerId)
                ->when(! empty($allowed), fn ($q) => $q->whereIn('id', $allowed))
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $invalid = array_diff($branchIdsToCheck, $valid);
            if ($invalid) {
                throw ValidationException::withMessages([
                    $spec['scope'] === PriceBoard::SCOPE_BRANCHES && ! $isV1 ? 'branch_ids' : 'branch_id' => ['Chi nhánh không thuộc đối tác của bạn: ' . implode(', ', array_unique($invalid)) . '.'],
                ]);
            }
        }

        if (array_key_exists('product_ids', $data) && ! $isV1) {
            $spec['product_ids'] = array_values(array_unique(array_map('strval', $data['product_ids'] ?? [])));
        }

        // ── Bộ giá ─────────────────────────────────────────────────────────
        if ($isV1 && (array_key_exists('items', $data) || array_key_exists('product_ids', $data))) {
            $spec['sheets']      = $this->sheetsFromV1($data, $spec, $partnerId, $dryRun);
            $spec['product_ids'] = [];
        } elseif (array_key_exists('sheets', $data)) {
            $existingById = collect($spec['sheets'])->keyBy('id');
            $sheets       = [];

            foreach (array_values($data['sheets'] ?? []) as $i => $raw) {
                $sheets[] = $this->normalizeSheet($raw, $i, $partnerId, $dryRun, $existingById->get($raw['id'] ?? null));
            }

            $spec['sheets'] = $sheets;
        }

        return $spec;
    }

    /**
     * Ghi đè bộ giá của spec (dùng khi "dùng preset" kèm sheets[]): khớp theo name rồi theo thứ tự.
     *
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function applySheetOverrides(array $spec, array $overrides, string $partnerId): array
    {
        foreach (array_values($overrides) as $i => $raw) {
            $index = null;

            foreach ($spec['sheets'] as $k => $sheet) {
                if (isset($raw['name']) && $sheet['name'] === $raw['name']) {
                    $index = $k;
                    break;
                }
            }

            $index ??= isset($spec['sheets'][$i]) ? $i : null;

            if ($index === null) {
                $spec['sheets'][] = $this->normalizeSheet($raw, count($spec['sheets']), $partnerId, false);
                continue;
            }

            $current = $spec['sheets'][$index];
            $merged = $this->normalizeSheet($raw + ['name' => $current['name']], $index, $partnerId, false, $current);
            $merged['product_ids'] = array_key_exists('product_ids', $raw) ? $merged['product_ids'] : $current['product_ids'];
            $merged['id'] = null;

            $spec['sheets'][$index] = $merged;
        }

        return $spec;
    }

    /** Bộ giá trong body v2 → mảng spec. Nhận cả { timeslot_id } lẫn { start_time, end_time }. */
    private function normalizeSheet(array $raw, int $index, string $partnerId, bool $dryRun, ?array $current = null): array
    {
        $mode   = $raw['pricing_mode'] ?? ($current['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE);
        $style  = array_key_exists('room_style', $raw) ? ($raw['room_style'] === null ? null : (int) $raw['room_style']) : ($current['room_style'] ?? null);
        $errors = [];

        if ($mode === PriceBoard::MODE_OVERRIDE && ! in_array($style, [1, 2], true)) {
            $errors["sheets.{$index}.room_style"] = ['Bộ giá kiểu override bắt buộc có room_style (1 = theo giờ, 2 = theo ngày).'];
        }

        if ($mode === PriceBoard::MODE_ADJUSTMENT) {
            $type  = $raw['adjustment_type'] ?? ($current['adjustment_type'] ?? null);
            $value = array_key_exists('adjustment_value', $raw) ? $raw['adjustment_value'] : ($current['adjustment_value'] ?? null);

            if (empty($type) || $value === null) {
                $errors["sheets.{$index}.adjustment_type"] = ['Bộ giá điều chỉnh cần adjustment_type và adjustment_value.'];
            }
        }

        $slots = [];
        if ($mode === PriceBoard::MODE_OVERRIDE && $style === 1) {
            if (empty($raw['time_slots']) && ! ($current['time_slots'] ?? [])) {
                $errors["sheets.{$index}.time_slots"] = ['Bộ giá theo giờ cần ít nhất 1 khung giờ.'];
            }

            [$slots, $slotErrors] = $this->normalizeSlots($raw['time_slots'] ?? ($current['time_slots'] ?? []), $partnerId, "sheets.{$index}.time_slots", $dryRun);
            $errors += $slotErrors;
        }

        if (array_key_exists('full_day_price', $raw) && $raw['full_day_price'] !== null && ($mode !== PriceBoard::MODE_OVERRIDE || $style !== 1)) {
            $errors["sheets.{$index}.full_day_price"] = ['Giá cả ngày chỉ dùng cho bộ giá theo giờ (room_style = 1, pricing_mode = override).'];
        }

        // Giá theo thứ (chỉ bộ giá "Theo ngày"): thay cho một giá cho mọi ngày. null/[] = một giá cho mọi ngày.
        $weekday = null;
        $rawWeekday = array_key_exists('weekday_prices', $raw) ? $raw['weekday_prices'] : ($current['weekday_prices'] ?? null);

        if (! array_key_exists('weekday_prices', $raw) && ! empty($rawWeekday) && $mode === PriceBoard::MODE_OVERRIDE && $style === 2) {
            // Request không nhắc tới giá theo thứ: giữ đúng giá đã lưu (đã chuẩn hoá), kể cả khi giai đoạn 2 đang tắt.
            $weekday = array_map('intval', (array) $rawWeekday);
        } elseif (! empty($rawWeekday)) {
            if ($mode !== PriceBoard::MODE_OVERRIDE || $style !== 2) {
                $errors["sheets.{$index}.weekday_prices"] = ['Giá theo thứ chỉ dùng cho bộ giá theo ngày (room_style = 2, pricing_mode = override).'];
            } elseif (! PriceBoard::phase2Enabled()) {
                $errors["sheets.{$index}.weekday_prices"] = ['Giá theo thứ chỉ dùng được khi bật giai đoạn 2 của bảng giá (PRICE_BOARDS_PHASE2).'];
            } else {
                [$weekday, $weekdayErrors] = $this->normalizeWeekdayPrices((array) $rawWeekday, "sheets.{$index}.weekday_prices");
                $errors += $weekdayErrors;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $declared = [];
        foreach (PriceBoardSheet::FIELDS_SET_KEYS as $key) {
            if ($key === 'full_day_price') {
                // Gửi số (0 = bảng này xoá giá cả ngày của phòng) thì khai báo; gửi null thì bỏ khai báo; không gửi thì giữ như cũ.
                if (array_key_exists($key, $raw) ? $raw[$key] !== null : ($current && in_array($key, (array) ($current['fields_set'] ?? []), true))) {
                    $declared[] = $key;
                }
            } elseif (array_key_exists($key, $raw)) {
                $declared[] = $key;
            } elseif ($current && in_array($key, (array) ($current['fields_set'] ?? []), true)) {
                $declared[] = $key;
            }
        }

        $pick = fn (string $key, $default = null) => array_key_exists($key, $raw) ? $raw[$key] : ($current[$key] ?? $default);

        return [
            'id'            => $raw['id'] ?? null,
            'name'          => $raw['name'] ?? ($current['name'] ?? 'Bộ giá ' . ($index + 1)),
            'pricing_mode'  => $mode,
            'room_style'    => $style,
            'is_default'    => (bool) $pick('is_default', false),
            'sort_order'    => (int) ($raw['sort_order'] ?? $index),
            'product_ids'   => array_values(array_unique(array_map('strval', $pick('product_ids', [])))),
            'time_slots'    => $slots,
            // Giai đoạn 2 BẬT và có giá theo thứ: price = giá thấp nhất trong tuần ("giá từ" của phòng).
            // Giai đoạn 2 TẮT: giá theo thứ chỉ được giữ lại (chưa có tác dụng), price là một giá cho mọi ngày do người dùng nhập — không bị đè.
            'price'         => ($weekday && PriceBoard::phase2Enabled()) ? min($weekday) : $pick('price'),
            'weekday_prices' => $weekday,
            'default_checkin'  => $pick('default_checkin'),
            'default_checkout' => $pick('default_checkout'),
            'deposit_1_night'     => $pick('deposit_1_night'),
            'deposit_multi_night' => $pick('deposit_multi_night'),
            'deposit_min_nights'  => $pick('deposit_min_nights'),
            'full_booking_discount' => $pick('full_booking_discount'),
            'full_day_price'        => $pick('full_day_price'),
            'bulk_discount_rules'   => $pick('bulk_discount_rules'),
            'room_config'           => $pick('room_config'),
            'fields_set'            => $declared,
            'adjustment_type'       => $pick('adjustment_type'),
            'adjustment_value'      => $pick('adjustment_value'),
            'adjustment_timeslot_ids' => array_map('intval', $pick('adjustment_timeslot_ids', []) ?: []),
        ];
    }

    /** Tên thứ theo ISO (1 = Thứ 2 … 7 = Chủ nhật) để báo lỗi dễ đọc. */
    public const MAX_WEEKDAY_PRICE = 999999999;

    public const WEEKDAY_LABELS = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'];

    /**
     * Giá theo thứ: phải đủ 7 thứ (khoá 1…7), mỗi thứ là số nguyên không âm.
     *
     * @param array<int|string,mixed> $raw
     * @return array{0: array<int,int>|null, 1: array<string,list<string>>}
     */
    private function normalizeWeekdayPrices(array $raw, string $path): array
    {
        $errors = [];
        $out    = [];

        foreach (array_keys($raw) as $key) {
            if (! array_key_exists((int) $key, self::WEEKDAY_LABELS) || (string) (int) $key !== (string) $key) {
                $errors[$path][] = 'Khoá giá theo thứ chỉ nhận 1 (Thứ 2) … 7 (Chủ nhật).';
                break;
            }
        }

        foreach (self::WEEKDAY_LABELS as $day => $label) {
            $value = $raw[$day] ?? $raw[(string) $day] ?? null;

            if ($value === null || $value === '') {
                $errors["{$path}.{$day}"][] = "Thiếu giá {$label}.";
            } elseif (! is_numeric($value) || (float) $value < 0 || (float) $value != (int) $value) {
                $errors["{$path}.{$day}"][] = "Giá {$label} phải là số nguyên không âm.";
            } elseif ((float) $value > self::MAX_WEEKDAY_PRICE) {
                $errors["{$path}.{$day}"][] = "Giá {$label} không được lớn hơn " . number_format(self::MAX_WEEKDAY_PRICE, 0, ',', '.') . ' đ.';
            } else {
                $out[$day] = (int) $value;
            }
        }

        return [$errors ? null : $out, $errors];
    }

    /**
     * Khung giờ của bộ giá: gửi timeslot_id hoặc { start_time, end_time }. Gửi giờ thì tìm khung trùng giờ
     * của đối tác / hệ thống, chưa có thì tạo (không tạo ở chế độ xem trước). Khung phải dùng được với đối tác.
     *
     * @return array{0: list<array<string,mixed>>, 1: array<string,list<string>>}
     */
    private function normalizeSlots(array $raw, string $partnerId, string $path, bool $dryRun): array
    {
        $out    = [];
        $errors = [];
        $seen   = [];

        foreach (array_values($raw) as $j => $row) {
            $row  = (array) $row;
            $id   = $row['timeslot_id'] ?? null;
            $over = array_key_exists('over_night', $row) ? (bool) $row['over_night'] : null;
            $meta = ['label' => null, 'start_time' => null, 'end_time' => null, 'will_create' => false];

            if (! $id) {
                if (empty($row['start_time']) || empty($row['end_time'])) {
                    $errors["{$path}.{$j}.timeslot_id"][] = 'Gửi timeslot_id hoặc cặp start_time + end_time.';
                    continue;
                }

                $start = substr((string) $row['start_time'], 0, 5);
                $end   = substr((string) $row['end_time'], 0, 5);
                $over ??= $end <= $start;

                $found = $this->slots->findSlot($partnerId, $start, $end, $over);

                if ($found) {
                    $id = $found->id;
                } elseif ($dryRun) {
                    $meta = ['label' => "{$start} - {$end}" . ($over ? ' (Qua đêm)' : ''), 'start_time' => $start, 'end_time' => $end, 'will_create' => true];
                    $id   = null;
                } else {
                    $id = $this->slots->findOrCreateSlot($partnerId, $start, $end, $over)->id;
                }
            }

            if ($id) {
                $slot = TimeSlot::usableBy($partnerId)->find($id);

                if (! $slot) {
                    $errors["{$path}.{$j}.timeslot_id"][] = "Khung giờ #{$id} không thuộc đối tác của bạn.";
                    continue;
                }

                if (isset($seen[$slot->id])) {
                    $errors["{$path}.{$j}.timeslot_id"][] = "Khung giờ #{$id} bị trùng trong cùng bộ giá.";
                    continue;
                }

                $seen[$slot->id] = true;
                $over ??= (bool) $slot->over_night;
                $meta = ['label' => $slot->label, 'start_time' => $slot->start_time, 'end_time' => $slot->end_time, 'will_create' => false];
            }

            if (! array_key_exists('price', $row) || $row['price'] === null || $row['price'] === '') {
                $errors["{$path}.{$j}.price"][] = 'Khung giờ cần có giá.';
                continue;
            }

            $out[] = [
                'timeslot_id' => $id ? (int) $id : null,
                'price'       => (int) $row['price'],
                'over_night'  => (bool) $over,
                'status'      => $row['status'] ?? 'available',
            ] + $meta;
        }

        return [$out, $errors];
    }

    /** Body v1 (items[] / product_ids[]) → bộ giá: phòng cùng cấu hình gộp thành 1 bộ. */
    private function sheetsFromV1(array $data, array $spec, string $partnerId, bool $dryRun): array
    {
        $mode = $data['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE;

        if ($mode === PriceBoard::MODE_ADJUSTMENT) {
            if (empty($data['adjustment_type']) || ! array_key_exists('adjustment_value', $data) || $data['adjustment_value'] === null) {
                throw ValidationException::withMessages([
                    'adjustment_type' => ['Bảng kiểu điều chỉnh cần adjustment_type và adjustment_value.'],
                ]);
            }

            return [[
                'id' => null, 'name' => 'Điều chỉnh giá', 'pricing_mode' => PriceBoard::MODE_ADJUSTMENT, 'room_style' => null,
                'is_default' => false, 'sort_order' => 0,
                'product_ids' => array_values(array_unique(array_map('strval', $data['product_ids'] ?? []))),
                'time_slots' => [], 'price' => null, 'default_checkin' => null, 'default_checkout' => null,
                'deposit_1_night' => null, 'deposit_multi_night' => null, 'deposit_min_nights' => null,
                'full_booking_discount' => null, 'full_day_price' => null, 'bulk_discount_rules' => null, 'room_config' => null, 'fields_set' => [],
                'adjustment_type' => $data['adjustment_type'] ?? null,
                'adjustment_value' => $data['adjustment_value'] ?? null,
                'adjustment_timeslot_ids' => [],
            ]];
        }

        $products = Product::query()
            ->where('partner_id', $partnerId)
            ->whereIn('id', collect($data['items'] ?? [])->pluck('product_id')->filter()->all() ?: ['-'])
            ->get()
            ->keyBy('id');

        $groups = [];
        $errors = [];

        foreach (array_values($data['items'] ?? []) as $i => $row) {
            $product = $products->get($row['product_id'] ?? null);

            if (! $product) {
                // Phòng sai đối tác: để resolver báo lỗi chi tiết (liệt kê id).
                $groups['missing:' . ($row['product_id'] ?? $i)] = ['product_ids' => [(string) ($row['product_id'] ?? '')], 'sheet' => null];
                continue;
            }

            $style = (int) ($product->styles ?? 1);
            $sheet = [
                'id' => null, 'name' => $style === 2 ? 'Phòng theo ngày' : 'Phòng theo giờ', 'pricing_mode' => PriceBoard::MODE_OVERRIDE,
                'room_style' => $style, 'is_default' => false, 'sort_order' => 0, 'product_ids' => [(string) $product->id],
                'time_slots' => [], 'price' => null, 'default_checkin' => null, 'default_checkout' => null,
                'deposit_1_night' => null, 'deposit_multi_night' => null, 'deposit_min_nights' => null,
                'full_booking_discount' => null, 'full_day_price' => null, 'bulk_discount_rules' => null, 'room_config' => null, 'fields_set' => [],
                'adjustment_type' => null, 'adjustment_value' => null, 'adjustment_timeslot_ids' => [],
            ];

            foreach (PriceBoardSheet::FIELDS_SET_KEYS as $key) {
                if (array_key_exists($key, $row)) {
                    $sheet[$key]        = $row[$key];
                    $sheet['fields_set'][] = $key;
                }
            }

            if ($style === 2) {
                foreach (['price', 'default_checkin', 'default_checkout', 'deposit_min_nights', 'deposit_multi_night'] as $key) {
                    $sheet[$key] = $row[$key] ?? $product->{$key};
                }
            } else {
                $raw = array_key_exists('time_slots', $row)
                    ? ($row['time_slots'] ?? [])
                    : $product->roomTimeSlots()->whereNull('date')->get()
                        ->map(fn ($s) => ['timeslot_id' => $s->timeslot_id, 'price' => (int) $s->price, 'over_night' => $s->over_night, 'status' => $s->status])
                        ->all();

                [$slots, $slotErrors] = $this->normalizeSlots($raw, $partnerId, 'items.' . $i . '.time_slots', $dryRun);
                $errors += $slotErrors;
                $sheet['time_slots'] = $slots;
            }

            $signature = md5(json_encode([$sheet['room_style'], $sheet['time_slots'], $sheet['price'], $sheet['default_checkin'],
                $sheet['default_checkout'], $sheet['deposit_min_nights'], $sheet['deposit_multi_night'], $sheet['full_booking_discount'], $sheet['full_day_price'] ?? null,
                $sheet['bulk_discount_rules'], $sheet['room_config'], $sheet['fields_set']]));

            if (isset($groups[$signature])) {
                $groups[$signature]['sheet']['product_ids'][] = (string) $product->id;
            } else {
                $groups[$signature] = ['sheet' => $sheet];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $sheets = [];
        $order  = 0;
        $strays = [];

        foreach ($groups as $key => $group) {
            if (str_starts_with($key, 'missing:')) {
                $strays = array_merge($strays, $group['product_ids']);
                continue;
            }

            $group['sheet']['sort_order'] = $order++;
            $group['sheet']['name']      .= $order > 1 || count($groups) > 1 ? ' ' . $order : '';
            $sheets[] = $group['sheet'];
        }

        if ($strays) {
            // Giữ phòng sai trong 1 bộ để PriceBoardResolver báo lỗi "phòng không thuộc đối tác" đúng id.
            $sheets[] = [
                'id' => null, 'name' => 'Phòng không hợp lệ', 'pricing_mode' => PriceBoard::MODE_ADJUSTMENT, 'room_style' => null,
                'is_default' => false, 'sort_order' => $order, 'product_ids' => $strays, 'time_slots' => [], 'price' => null,
                'default_checkin' => null, 'default_checkout' => null, 'deposit_1_night' => null, 'deposit_multi_night' => null,
                'deposit_min_nights' => null, 'full_booking_discount' => null, 'full_day_price' => null, 'bulk_discount_rules' => null, 'room_config' => null,
                'fields_set' => [], 'adjustment_type' => 'percentage', 'adjustment_value' => 0, 'adjustment_timeslot_ids' => [],
            ];
        }

        return $sheets;
    }

    // ════════════════════════════════════════════════════════════════════
    // 2. Spec của bảng đã lưu
    // ════════════════════════════════════════════════════════════════════

    /** @return array<string,mixed> */
    public function specFromBoard(PriceBoard $board): array
    {
        $board->loadMissing(['sheets.slots', 'sheets.products', 'scopeBranches', 'declaredRooms']);

        return [
            'id'            => $board->id,
            'partner_id'    => (string) $board->partner_id,
            'name'          => $board->name,
            'note'          => $board->note,
            'scope'         => $board->scope ?: PriceBoard::SCOPE_ROOMS,
            'branch_ids'    => $board->scopeBranches->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'product_ids'   => $board->declaredRooms->pluck('product_id')->map(fn ($v) => (string) $v)->all(),
            'category_id'   => $board->category_id ? (int) $board->category_id : null,
            'is_active'     => (bool) $board->is_active,
            'start_date'    => $board->start_date?->toDateString(),
            'end_date'      => $board->end_date?->toDateString(),
            'source_preset' => $board->source_preset,
            'priority'      => (int) $board->priority,
            'days_of_week'  => array_values(array_map('intval', (array) $board->days_of_week)),
            'sheets'        => $board->sheets->map(fn (PriceBoardSheet $s) => [
                'id'            => $s->id,
                'name'          => $s->name,
                'pricing_mode'  => $s->pricing_mode,
                'room_style'    => $s->room_style,
                'is_default'    => (bool) $s->is_default,
                'sort_order'    => (int) $s->sort_order,
                'product_ids'   => $s->products->pluck('product_id')->map(fn ($v) => (string) $v)->all(),
                'time_slots'    => $s->slots->map(fn ($sl) => [
                    'timeslot_id' => (int) $sl->timeslot_id, 'price' => (int) $sl->price,
                    'over_night'  => (bool) $sl->over_night, 'status' => $sl->status,
                ])->all(),
                'price'            => $s->price === null ? null : (float) $s->price,
                'weekday_prices'   => $s->weekday_prices ?: null,
                'default_checkin'  => $s->default_checkin,
                'default_checkout' => $s->default_checkout,
                'deposit_1_night'     => $s->deposit_1_night,
                'deposit_multi_night' => $s->deposit_multi_night,
                'deposit_min_nights'  => $s->deposit_min_nights,
                'full_booking_discount' => $s->full_booking_discount,
                'full_day_price'        => $s->full_day_price === null ? null : (int) $s->full_day_price,
                'bulk_discount_rules'   => $s->bulk_discount_rules,
                'room_config'           => $s->room_config,
                'fields_set'            => (array) $s->fields_set,
                'adjustment_type'       => $s->adjustment_type,
                'adjustment_value'      => $s->adjustment_value === null ? null : (float) $s->adjustment_value,
                'adjustment_timeslot_ids' => array_map('intval', (array) $s->adjustment_timeslot_ids),
            ])->all(),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // 3. Đánh giá (không ghi): lỗi, xung đột, summary
    // ════════════════════════════════════════════════════════════════════

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed> ['resolve', 'errors', 'conflicts', 'summary']
     */
    public function evaluate(array $spec, ?PriceBoard $existing = null): array
    {
        $errors = [];

        if (empty($spec['name'])) {
            $errors['name'][] = 'Tên bảng giá là bắt buộc.';
        }

        if (empty($spec['sheets'])) {
            $errors['sheets'][] = 'Bảng giá cần ít nhất 1 bộ giá.';
        }

        $resolve = $this->resolver->resolve($spec);
        $errors  = array_merge_recursive($errors, $resolve['errors']);

        $conflicts = [];

        if (! $errors && ! empty($spec['is_active'])) {
            $probe = $existing ? clone $existing : new PriceBoard();
            $probe->forceFill([
                'is_active'    => true,
                'scope'        => $spec['scope'] ?? PriceBoard::SCOPE_ROOMS,
                'priority'     => (int) ($spec['priority'] ?? 0),
                'days_of_week' => $spec['days_of_week'] ?? [],
                'start_date' => $spec['start_date'] ?? null,
                'end_date'   => $spec['end_date'] ?? null,
            ]);

            $conflicts = array_map(fn (array $c) => [
                'board'       => ['id' => $c['board']->id, 'name' => $c['board']->name, 'priority' => (int) $c['board']->priority],
                'product_ids' => array_values($c['product_ids']),
            ], $this->sync->overlapConflicts($probe, collect(array_keys($resolve['assignments']))));
        }

        return [
            'resolve'   => $resolve,
            'errors'    => $errors,
            'conflicts' => $conflicts,
            'summary'   => $this->summary($spec, $resolve),
        ];
    }

    /**
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $resolve
     * @return array<string,mixed>
     */
    public function summary(array $spec, array $resolve): array
    {
        $sheets  = array_values($spec['sheets'] ?? []);
        $bySheet = [];

        foreach ($sheets as $i => $sheet) {
            $bySheet[] = [
                'sheet_id' => $sheet['id'] ?? null,
                'name'     => $sheet['name'] ?? null,
                'rooms'    => (int) ($resolve['per_sheet'][$i] ?? 0),
            ];
        }

        $today        = Carbon::today();
        $coversToday  = (! ($spec['start_date'] ?? null) || $today->gte(Carbon::parse($spec['start_date'])))
            && (! ($spec['end_date'] ?? null) || $today->lte(Carbon::parse($spec['end_date'])));
        $appliedNow   = (! empty($spec['is_active']) && $coversToday) ? count($resolve['assignments']) : 0;

        return [
            'rooms_total'       => count($resolve['assignments']) + count($resolve['unmatched']),
            'rooms_by_sheet'    => $bySheet,
            'rooms_unmatched'   => count($resolve['unmatched']),
            'rooms_applied_now' => $appliedNow,
            'slot_conflicts'    => $this->slotConflicts($spec, $resolve),
        ];
    }

    /**
     * Khung giờ SẼ BỊ GỠ khỏi phòng mà còn đơn chưa tới ngày (chỉ bộ override theo giờ đổi bộ khung).
     *
     * @return array{rooms:int, orders:int, items:list<array<string,mixed>>}
     */
    public function slotConflicts(array $spec, array $resolve): array
    {
        $sheets = array_values($spec['sheets'] ?? []);
        $items  = [];

        foreach ($resolve['assignments'] as $productId => $assign) {
            $sheet = $sheets[$assign['sheet']] ?? null;

            if (! $sheet || ($sheet['pricing_mode'] ?? '') !== PriceBoard::MODE_OVERRIDE || (int) ($sheet['room_style'] ?? 0) !== 1) {
                continue;
            }

            $keep = collect($sheet['time_slots'] ?? [])->pluck('timeslot_id')->filter()->map(fn ($v) => (int) $v)->all();

            $current = RoomTimeSlot::with('timeSlot')
                ->where('room_id', $productId)
                ->whereNull('date')
                ->whereNotIn('timeslot_id', $keep ?: [0])
                ->get()
                ->filter(fn ($s) => $s->timeSlot && ($s->timeSlot->type ?? null) !== 'date');

            if ($current->isEmpty()) {
                continue;
            }

            $product = $resolve['products']->get($productId);
            $orders  = $this->sync->futureOrdersByLabel($product, $current->map(fn ($s) => (string) $s->timeSlot->label)->all());

            foreach ($current as $rts) {
                $label = (string) $rts->timeSlot->label;

                if (! isset($orders[$label])) {
                    continue;
                }

                $items[] = [
                    'product_id'    => $productId,
                    'room_name'     => $product->name,
                    'timeslot_id'   => (int) $rts->timeslot_id,
                    'label'         => $label,
                    'future_orders' => $orders[$label]['orders'],
                    'last_date'     => $orders[$label]['last_date'],
                ];
            }
        }

        return [
            'rooms'  => collect($items)->pluck('product_id')->unique()->count(),
            'orders' => (int) collect($items)->sum('future_orders'),
            'items'  => $items,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // 4. Lưu + sinh item + ghi giá
    // ════════════════════════════════════════════════════════════════════

    /**
     * Lưu bảng (tạo mới hoặc sửa). Đã kiểm tra bằng evaluate() ở nơi gọi.
     *
     * @param array<string,mixed> $spec
     */
    public function persist(array $spec, ?PriceBoard $existing, ?array $templateAttrs = null): PriceBoard
    {
        // Duyệt trước khi đăng (App\Services\ApprovalService): đối tác thường sửa bảng giá ĐÃ duyệt → bản sửa vào hàng chờ, bảng đang chạy giữ nguyên.
        // Tạo mới / sửa bảng chưa duyệt vẫn lưu như thường nhưng ở trạng thái chờ duyệt (không bật được) — xem phần cuối hàm.
        $gate    = app(\App\Services\ApprovalService::class);
        $guarded = $templateAttrs === null && $gate->guards();
        $actor   = auth()->user();

        if ($guarded && $existing && $existing->isApproved()) {
            $gate->submitBoardUpdate($existing, $spec, $actor);

            return $existing->fresh(['sheets.slots', 'sheets.products', 'scopeBranches', 'declaredRooms']);
        }

        $previousProducts = $existing ? $existing->items()->pluck('product_id')->all() : [];

        $board = DB::transaction(function () use ($spec, $existing, $templateAttrs) {
            $isAdjustmentOnly = $spec['sheets'] && collect($spec['sheets'])->every(fn ($s) => ($s['pricing_mode'] ?? '') === PriceBoard::MODE_ADJUSTMENT);
            $first            = $spec['sheets'][0] ?? [];

            $attrs = [
                'name'             => $spec['name'],
                'note'             => $spec['note'] ?? null,
                'scope'            => $spec['scope'],
                'category_id'      => $spec['category_id'],
                'is_active'        => (bool) ($spec['is_active'] ?? false),
                'start_date'       => $spec['start_date'] ?? null,
                'end_date'         => $spec['end_date'] ?? null,
                'is_default'       => false,
                'pricing_mode'     => $isAdjustmentOnly ? PriceBoard::MODE_ADJUSTMENT : PriceBoard::MODE_OVERRIDE,
                'adjustment_type'  => $isAdjustmentOnly ? ($first['adjustment_type'] ?? null) : null,
                'adjustment_value' => $isAdjustmentOnly ? ($first['adjustment_value'] ?? null) : null,
                'source_preset'    => $spec['source_preset'] ?? null,
            ];

            if (PriceBoard::phase2Enabled()) {
                $attrs['priority']     = (int) ($spec['priority'] ?? 0);
                $attrs['days_of_week'] = $spec['days_of_week'] ?? [];
            }

            if ($templateAttrs) {
                $attrs = array_merge($attrs, $templateAttrs);
            }

            if ($existing) {
                $existing->update($attrs);
                $board = $existing;
            } else {
                $board = PriceBoard::create($attrs + ['partner_id' => $spec['partner_id']]);
            }

            $this->persistSheets($board, $spec['sheets']);

            $board->scopeBranches()->sync($spec['scope'] === PriceBoard::SCOPE_BRANCHES ? $spec['branch_ids'] : []);

            $board->declaredRooms()->delete();
            if ($spec['scope'] === PriceBoard::SCOPE_ROOMS) {
                foreach (($spec['product_ids'] ?? []) as $productId) {
                    $board->declaredRooms()->create(['product_id' => $productId]);
                }
            }

            return $board->fresh();
        });

        $this->rematerialize($board, null, $previousProducts);

        if ($guarded) {
            // Bảng mới (pending) hoặc bảng bị từ chối được sửa lại → gửi / gửi lại duyệt. Bảng được tạo ở trạng thái bật thì chỉ bật sau khi duyệt.
            if ($board->approval_status === 'rejected') {
                $board->forceFill(['approval_status' => 'pending'])->save();
            }

            $gate->submitBoardCreate($board, (bool) ($spec['is_active'] ?? false), $actor);
        }

        return $board->fresh(['sheets.slots', 'sheets.products', 'scopeBranches', 'declaredRooms']);
    }

    /**
     * Lưu BẢNG GIÁ DỰNG SẴN (preset) — bảng ở trạng thái mẫu: không gắn phòng/đối tác, không sinh item,
     * không bao giờ ghi giá. Khung giờ lưu theo GIỜ (slot_times) để dùng cho mọi đối tác.
     *
     * @param array<string,mixed> $spec   spec đã chuẩn hoá (khung giờ hệ thống)
     * @param array<string,mixed> $attrs  code, description, default_scope, requires_dates, tags
     */
    public function persistTemplate(array $spec, array $attrs, ?PriceBoard $existing = null): PriceBoard
    {
        $sheets = array_map(function (array $sheet) {
            $times = [];

            foreach ($sheet['time_slots'] ?? [] as $slot) {
                $timeSlot = ! empty($slot['timeslot_id']) ? TimeSlot::find($slot['timeslot_id']) : null;

                if (! $timeSlot) {
                    continue;
                }

                $times[] = [
                    'start'      => substr((string) $timeSlot->start_time, 0, 5),
                    'end'        => substr((string) $timeSlot->end_time, 0, 5),
                    'over_night' => (bool) ($slot['over_night'] ?? $timeSlot->over_night),
                    'price'      => (int) $slot['price'],
                ];
            }

            $sheet['slot_times']  = $times ?: null;
            $sheet['time_slots']  = [];
            $sheet['product_ids'] = [];

            return $sheet;
        }, $spec['sheets']);

        return DB::transaction(function () use ($spec, $attrs, $existing, $sheets) {
            $columns = [
                'name'           => $spec['name'],
                'note'           => $spec['note'] ?? null,
                'scope'          => $attrs['default_scope'] ?? PriceBoard::SCOPE_PARTNER,
                'category_id'    => null,
                'is_active'      => false,
                'is_default'     => false,
                'is_template'    => true,
                'partner_id'     => null,
                'start_date'     => null,
                'end_date'       => null,
                'pricing_mode'   => PriceBoard::MODE_OVERRIDE,
                'code'           => $attrs['code'] ?? null,
                'description'    => $attrs['description'] ?? null,
                'default_scope'  => $attrs['default_scope'] ?? PriceBoard::SCOPE_PARTNER,
                'requires_dates' => (bool) ($attrs['requires_dates'] ?? false),
                'tags'           => $attrs['tags'] ?? null,
            ];

            if ($existing) {
                $existing->update($columns);
                $board = $existing;
            } else {
                $board = PriceBoard::create($columns);
            }

            $this->persistSheets($board, $sheets);

            return $board->fresh(['sheets.slots', 'sheets.products']);
        });
    }

    /** Bộ giá có id = sửa, không id = mới, vắng mặt = xoá. */
    private function persistSheets(PriceBoard $board, array $sheets): void
    {
        $keepIds = [];

        foreach (array_values($sheets) as $i => $sheet) {
            $columns = [
                'name'         => $sheet['name'],
                'pricing_mode' => $sheet['pricing_mode'],
                'room_style'   => $sheet['room_style'],
                'is_default'   => (bool) $sheet['is_default'],
                'sort_order'   => $sheet['sort_order'] ?? $i,
                'price'        => $sheet['price'],
                'weekday_prices' => $sheet['weekday_prices'] ?? null,
                'default_checkin'  => $this->hm($sheet['default_checkin']),
                'default_checkout' => $this->hm($sheet['default_checkout']),
                'deposit_1_night'     => $sheet['deposit_1_night'],
                'deposit_multi_night' => $sheet['deposit_multi_night'],
                'deposit_min_nights'  => $sheet['deposit_min_nights'],
                'full_booking_discount' => $sheet['full_booking_discount'],
                'full_day_price'        => $sheet['full_day_price'] ?? null,
                'bulk_discount_rules'   => $sheet['bulk_discount_rules'],
                'room_config'           => $sheet['room_config'],
                'fields_set'            => $sheet['fields_set'],
                'adjustment_type'       => $sheet['adjustment_type'],
                'adjustment_value'      => $sheet['adjustment_value'],
                'adjustment_timeslot_ids' => $sheet['adjustment_timeslot_ids'],
                'slot_times'              => $sheet['slot_times'] ?? null,
            ];

            $model = ! empty($sheet['id']) ? $board->sheets()->whereKey($sheet['id'])->first() : null;

            if ($model) {
                $model->update($columns);
            } else {
                $model = $board->sheets()->create($columns);
            }

            $keepIds[] = $model->id;

            $model->slots()->delete();
            foreach (array_values($sheet['time_slots'] ?? []) as $j => $slot) {
                if (empty($slot['timeslot_id'])) {
                    continue; // khung chưa tồn tại (chỉ có ở xem trước) không bao giờ tới đây
                }

                $model->slots()->create([
                    'timeslot_id' => $slot['timeslot_id'], 'price' => $slot['price'],
                    'over_night'  => $slot['over_night'], 'status' => $slot['status'] ?? 'available', 'sort_order' => $j,
                ]);
            }

            $model->products()->delete();
            foreach (array_values(array_unique($sheet['product_ids'] ?? [])) as $productId) {
                $model->products()->create(['product_id' => $productId]);
            }
        }

        $board->sheets()->whereNotIn('id', $keepIds ?: [0])->get()->each->delete();
    }

    private function hm(mixed $value): ?string
    {
        return $value ? substr((string) $value, 0, 5) : null;
    }

    /**
     * Sinh lại price_board_items của $board từ các bộ giá rồi ghi giá (động cơ hiện có). $only = chỉ các
     * phòng này (hook phòng mới); $alsoResync = phòng cũ vừa rời bảng cần tính lại giá.
     *
     * @param list<string>|null $only
     * @param list<string> $alsoResync
     * @return list<string> product_id đã tính lại giá
     */
    public function rematerialize(PriceBoard $board, ?array $only = null, array $alsoResync = []): array
    {
        if ($board->is_template || $board->is_default) {
            return [];
        }

        $spec    = $this->specFromBoard($board);
        $resolve = $this->resolver->resolve($spec);
        $sheets  = $board->sheets()->with('slots')->get()->values();

        $keep    = [];
        $touched = [];

        foreach ($resolve['assignments'] as $productId => $assign) {
            if ($only !== null && ! in_array($productId, $only, true)) {
                continue;
            }

            /** @var PriceBoardSheet|null $sheet */
            $sheet   = $sheets->get($assign['sheet']);
            $product = $resolve['products']->get($productId);

            if (! $sheet || ! $product) {
                continue;
            }

            [$fields, $slots] = $this->itemPayload($sheet, $product);

            $item   = $this->sync->upsertItem($board, $product, $fields, $slots, $sheet->id);
            $keep[] = $item->id;
            $touched[] = $productId;
        }

        $removed = [];

        if ($only === null) {
            $removed = $this->sync->pruneItems($board, $keep);
        } else {
            // Chỉ các phòng được yêu cầu: gỡ item của phòng đó nếu không còn khớp.
            foreach ($only as $productId) {
                if (! isset($resolve['assignments'][$productId])) {
                    $board->items()->where('product_id', $productId)->get()->each(function ($item) use (&$removed) {
                        $removed[] = $item->product_id;
                        $item->timeSlots()->delete();
                        $item->delete();
                    });
                }
            }
        }

        $targets = array_values(array_unique(array_merge($touched, $removed, $alsoResync)));

        foreach (Product::query()->whereIn('id', $targets ?: ['-'])->get() as $product) {
            $this->sync->applyForProduct($product);
        }

        if ($targets) {
            app(\App\Services\SlotRealtimeService::class)->broadcastPriceChanged($targets);
        }

        return $targets;
    }

    /**
     * Cột giá của item + khung giờ, theo bộ giá và loại phòng.
     *
     * @return array{0: array<string,mixed>, 1: list<array<string,mixed>>}
     */
    private function itemPayload(PriceBoardSheet $sheet, Product $product): array
    {
        if ($sheet->isAdjustment()) {
            return [[], []];
        }

        $config = (array) $product->room_config;

        $fields = [
            'full_booking_discount' => $sheet->declares('full_booking_discount') ? $sheet->full_booking_discount : $product->full_booking_discount,
            'bulk_discount_rules'   => $sheet->declares('bulk_discount_rules') ? $sheet->bulk_discount_rules : $product->bulk_discount_rules,
            'room_config'           => $sheet->declares('room_config') ? $sheet->room_config : ($config ?: null),
        ];

        if ((int) ($product->styles ?? 1) === 2) {
            return [$fields + [
                'price'               => $sheet->price,
                'weekday_prices'      => $sheet->weekday_prices ?: null,
                'default_checkin'     => $sheet->default_checkin ?: '14:00',
                'default_checkout'    => $sheet->default_checkout ?: '12:00',
                'deposit_min_nights'  => $sheet->deposit_min_nights ?? 2,
                'deposit_multi_night' => $sheet->deposit_multi_night ?? 50,
                'deposit_1_night'     => 100,
            ], []];
        }

        // Giá cả ngày (phòng theo giờ): bộ giá khai báo thì lấy giá của bộ (0 = xoá), không thì giữ giá hiện tại của phòng. 0 = "không dùng".
        $fullDay = $sheet->declares('full_day_price') ? $sheet->full_day_price : $product->full_day_price;
        $fields['full_day_price'] = $fullDay !== null && (float) $fullDay > 0 ? (float) $fullDay : 0;

        return [$fields, $sheet->slots->map(fn ($s) => [
            'timeslot_id' => (int) $s->timeslot_id, 'price' => (int) $s->price,
            'over_night' => (bool) $s->over_night, 'status' => $s->status ?: 'available',
        ])->all()];
    }

    /**
     * Hook khi phòng mới tạo / đổi chi nhánh: các bảng phạm vi chi nhánh / toàn đối tác của đối tác nhận
     * phòng này (kể cả bảng đang tắt — để danh sách phòng đúng).
     */
    public function syncRoom(Product $room): void
    {
        if (empty($room->partner_id)) {
            return;
        }

        PriceBoard::query()
            ->where('partner_id', $room->partner_id)
            ->where('is_template', false)
            ->where('is_default', false)
            ->whereIn('scope', [PriceBoard::SCOPE_BRANCHES, PriceBoard::SCOPE_PARTNER])
            ->get()
            ->each(fn (PriceBoard $board) => $this->rematerialize($board, [(string) $room->id]));
    }

    /** Job đêm: bắt kịp phòng mới / phòng đổi chi nhánh trước khi áp giá theo lịch. */
    public function rematerializeScoped(): int
    {
        $count = 0;

        PriceBoard::query()
            ->where('is_template', false)
            ->where('is_default', false)
            ->whereIn('scope', [PriceBoard::SCOPE_BRANCHES, PriceBoard::SCOPE_PARTNER])
            ->whereNotNull('partner_id')
            ->orderBy('id')
            ->each(function (PriceBoard $board) use (&$count) {
                $this->rematerialize($board);
                $count++;
            });

        return $count;
    }

    // ════════════════════════════════════════════════════════════════════
    // 5. Nhân bản + preset
    // ════════════════════════════════════════════════════════════════════

    /**
     * Nhân bản bảng $source thành bảng mới của $partnerId (dùng cho duplicate và "dùng preset").
     *
     * @param array<string,mixed> $overrides name, note, start_date, end_date, is_active, scope, branch_ids, product_ids, source_preset
     */
    public function cloneBoard(PriceBoard $source, string $partnerId, array $overrides, bool $resolvePresetTimes = false): array
    {
        $spec = $this->specFromBoard($source);

        $spec['id']          = null;
        $spec['partner_id']  = $partnerId;
        $spec['name']        = $overrides['name'] ?? $spec['name'];
        $spec['note']        = $overrides['note'] ?? $spec['note'];
        $spec['start_date']  = $overrides['start_date'] ?? ($source->is_template ? null : $spec['start_date']);
        $spec['end_date']    = $overrides['end_date'] ?? ($source->is_template ? null : $spec['end_date']);
        $spec['is_active']   = (bool) ($overrides['is_active'] ?? false);
        $spec['source_preset'] = $overrides['source_preset'] ?? ($source->is_template ? $source->code : $source->source_preset);

        if ($source->is_template) {
            $spec['scope']       = $overrides['scope'] ?? ($source->default_scope ?: PriceBoard::SCOPE_PARTNER);
            $spec['branch_ids']  = array_values(array_map('intval', $overrides['branch_ids'] ?? []));
            $spec['product_ids'] = array_values(array_map('strval', $overrides['product_ids'] ?? []));
            $spec['category_id'] = $spec['scope'] === PriceBoard::SCOPE_BRANCHES && count($spec['branch_ids']) === 1 ? $spec['branch_ids'][0] : null;
        } else {
            foreach (['scope', 'branch_ids', 'product_ids'] as $key) {
                if (array_key_exists($key, $overrides)) {
                    $spec[$key] = $overrides[$key];
                }
            }
        }

        if ($source->is_template && PriceBoard::phase2Enabled()) {
            if (isset($overrides['priority'])) {
                $spec['priority'] = (int) $overrides['priority'];
            }
            if (isset($overrides['days_of_week'])) {
                $spec['days_of_week'] = array_values(array_map('intval', (array) $overrides['days_of_week']));
            }
        }

        $created = [];

        if ($resolvePresetTimes) {
            $sheetModels = $source->sheets()->get()->values();

            foreach ($spec['sheets'] as $i => &$sheet) {
                $sheet['id'] = null;
                $times       = (array) ($sheetModels[$i]->slot_times ?? []);

                if ($times) {
                    $slots = [];

                    foreach ($times as $t) {
                        $over  = (bool) ($t['over_night'] ?? false);
                        $found = $this->slots->findSlot($partnerId, $t['start'], $t['end'], $over);

                        if (! $found) {
                            $found     = $this->slots->findOrCreateSlot($partnerId, $t['start'], $t['end'], $over);
                            $created[] = ['id' => $found->id, 'label' => $found->label];
                        }

                        $slots[] = ['timeslot_id' => (int) $found->id, 'price' => (int) $t['price'], 'over_night' => $over, 'status' => 'available'];
                    }

                    if (($sheet['pricing_mode'] ?? '') === PriceBoard::MODE_ADJUSTMENT) {
                        // Bảng điều chỉnh: chỉ chỉnh đúng các khung này, không chốt giá cố định.
                        $sheet['adjustment_timeslot_ids'] = array_column($slots, 'timeslot_id');
                        $sheet['time_slots']              = [];
                    } else {
                        $sheet['time_slots'] = $slots;
                    }
                }
            }
            unset($sheet);
        } else {
            foreach ($spec['sheets'] as &$sheet) {
                $sheet['id'] = null;
            }
            unset($sheet);
        }

        return ['spec' => $spec, 'created_time_slots' => $created];
    }

    /** Preset (bảng mẫu hệ thống) theo code, kèm trạng thái khung giờ (đã có / sẽ tạo) với $partnerId. */
    public function presetDetail(PriceBoard $preset, ?string $partnerId): array
    {
        $sheets = $preset->sheets()->get()->map(function (PriceBoardSheet $sheet) use ($partnerId) {
            $times = collect((array) $sheet->slot_times)->map(function (array $t) use ($partnerId) {
                $over  = (bool) ($t['over_night'] ?? false);
                $found = $partnerId ? $this->slots->findSlot($partnerId, $t['start'], $t['end'], $over) : null;

                return [
                    'timeslot_id' => $found?->id,
                    'label'       => "{$t['start']} - {$t['end']}" . ($over ? ' (Qua đêm)' : ''),
                    'start_time'  => $t['start'],
                    'end_time'    => $t['end'],
                    'price'       => (int) $t['price'],
                    'over_night'  => $over,
                    'status'      => 'available',
                    'will_create' => $found === null,
                ];
            })->values()->all();

            return [
                'name'                  => $sheet->name,
                'pricing_mode'          => $sheet->pricing_mode,
                'room_style'            => $sheet->room_style,
                'is_default'            => (bool) $sheet->is_default,
                'time_slots'            => $times,
                'price'                 => $sheet->price === null ? null : (int) $sheet->price,
                'default_checkin'       => $sheet->default_checkin,
                'default_checkout'      => $sheet->default_checkout,
                'deposit_min_nights'    => $sheet->deposit_min_nights,
                'deposit_multi_night'   => $sheet->deposit_multi_night,
                'full_booking_discount' => $sheet->full_booking_discount,
                'full_day_price'        => $sheet->full_day_price === null ? null : (int) $sheet->full_day_price,
                'bulk_discount_rules'   => $sheet->bulk_discount_rules ?? [],
                'room_config'           => $sheet->room_config,
                'adjustment_timeslot_ids' => $sheet->pricing_mode === PriceBoard::MODE_ADJUSTMENT
                    ? array_values(array_filter(array_column($times, 'timeslot_id')))
                    : [],
                'adjustment_type'       => $sheet->adjustment_type,
                'adjustment_value'      => $sheet->adjustment_value === null ? null : (float) $sheet->adjustment_value,
            ];
        })->all();

        return [
            'code'             => $preset->code,
            'name'             => $preset->name,
            'description'      => $preset->description,
            'tags'             => (array) $preset->tags,
            'default_scope'    => $preset->default_scope ?: PriceBoard::SCOPE_PARTNER,
            'default_priority' => PriceBoard::phase2Enabled() ? (int) $preset->priority : 0,
            'days_of_week'     => PriceBoard::phase2Enabled() ? array_values(array_map('intval', (array) $preset->days_of_week)) : [],
            'requires_dates'   => (bool) $preset->requires_dates,
            'sheets_count'     => count($sheets),
            'sort_order'       => (int) $preset->sort_order,
            'sheets'           => $sheets,
        ];
    }
}
