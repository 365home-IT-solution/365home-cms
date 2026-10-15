<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PriceBoardSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;
use RuntimeException;

/**
 * BẢNG GIÁ (price_boards) của đối tác — cùng luồng với trang Filament "Bảng giá": mỗi bảng thuộc 1
 * đối tác + 1 chi nhánh, có thể có khoảng ngày hiệu lực, và ghi đè giá các phòng đã chọn khi đang
 * hiệu lực (hết hạn thì tự khôi phục) — logic ghi nằm ở App\Services\PriceBoardSyncService, dùng
 * chung với web.
 *
 *  - GET    /api/admin/price-boards            ?branch_id=&search=&is_active=&status=
 *  - GET    /api/admin/price-boards/{id}       chi tiết kèm phòng + giá từng khung giờ
 *  - POST   /api/admin/price-boards            tạo
 *  - PUT|PATCH /api/admin/price-boards/{id}    sửa (gửi `items`/`product_ids` = thay toàn bộ phòng của bảng)
 *  - DELETE /api/admin/price-boards/{id}       xoá, các phòng được tính lại giá
 *  - POST   /api/admin/price-boards/{id}/apply áp NGAY xuống phòng, bất kể ngày hiệu lực
 *
 * Hai kiểu bảng (pricing_mode): `override` — nhập giá cụ thể từng khung giờ của từng phòng (items[]);
 * `adjustment` — 1 mức % / số tiền cộng trừ lên giá gốc (product_ids[]).
 *
 * Super_admin gửi X-Partner-Id để thao tác hộ 1 đối tác.
 */
class PriceBoardController extends Controller
{
    public function __construct(private PriceBoardSyncService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'branch_id' => 'nullable|integer',
            'status'    => ['nullable', Rule::in(['active', 'pending', 'expired', 'inactive'])],
        ]);

        $boards = PriceBoard::visibleTo($user)
            ->with('branch:id,name')
            ->withCount('items')
            ->when($request->filled('branch_id'), fn ($q) => $q->where('category_id', (int) $request->input('branch_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->string('search') . '%'))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PriceBoard $b) => $this->toItem($b))
            ->when($request->filled('status'), fn ($c) => $c->where('status', $request->input('status')))
            ->values();

        return response()->json(['data' => $boards]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        return response()->json(['data' => $this->toItem($board, true)]);
    }

    /**
     * POST /api/admin/price-boards
     * Body:
     *  - name (required), note, branch_id (required — chi nhánh áp dụng), is_active (mặc định true)
     *  - start_date, end_date (Y-m-d; bỏ trống = không giới hạn)
     *  - pricing_mode: override (mặc định) | adjustment
     *  - override   : items[] — { product_id, full_booking_discount, bulk_discount_rules[{slots,discount}],
     *                 room_config{max_free_guests,extra_guest_fee}, time_slots[{timeslot_id,price,over_night,status}] }
     *                 (phòng theo ngày: price, default_checkin, default_checkout, deposit_*). Field bỏ trống
     *                 Khung giờ lấy từ POST /api/admin/time-slots/random (random khung giờ TRƯỚC, rồi mới tạo bảng giá).
     *  - adjustment : adjustment_type (percentage|fixed), adjustment_value (+/-), product_ids[]
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate($this->rules(creating: true));

        return $this->save($user, null, $data);
    }

    /** PUT|PATCH /api/admin/price-boards/{id} — như POST nhưng mọi field đều tuỳ chọn. */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $board = $this->find($user, $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate($this->rules(creating: false));

        return $this->save($user, $board, $data);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        // Phải chụp danh sách phòng TRƯỚC khi xoá — xoá bảng là cascade mất luôn các item.
        $affected = $this->service->productsAffectedByBoard($board);

        DB::transaction(function () use ($board, $affected) {
            $board->delete();
            $this->service->resyncProductsAfterBoardDeleted($affected);
        });

        return response()->json(['message' => 'Đã xoá bảng giá.']);
    }

    /** POST /api/admin/price-boards/{id}/apply — áp ngay xuống các phòng của bảng (bất kể ngày hiệu lực). */
    public function apply(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        $this->service->applyBoard($board);

        return response()->json(['message' => 'Đã áp dụng bảng giá cho ' . $board->items()->count() . ' phòng.']);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name'                              => [$req, 'string', 'max:255'],
            'note'                              => 'nullable|string',
            'branch_id'                         => [$req, 'integer'],
            'is_active'                         => 'nullable|boolean',
            'start_date'                        => 'nullable|date_format:Y-m-d',
            'end_date'                          => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'pricing_mode'                      => ['nullable', Rule::in([PriceBoard::MODE_OVERRIDE, PriceBoard::MODE_ADJUSTMENT])],
            'adjustment_type'                   => ['nullable', Rule::in(['percentage', 'fixed'])],
            'adjustment_value'                  => 'nullable|numeric',

            'product_ids'                       => 'nullable|array',
            'product_ids.*'                     => 'string',

            'items'                             => 'nullable|array',
            'items.*.product_id'                => 'required_with:items|string',
            'items.*.full_booking_discount'     => 'nullable|string|max:50',
            'items.*.bulk_discount_rules'       => 'nullable|array',
            'items.*.bulk_discount_rules.*.slots'    => 'required|integer|min:1',
            'items.*.bulk_discount_rules.*.discount' => 'required|numeric|min:0|max:100',
            'items.*.room_config'               => 'nullable|array',
            'items.*.room_config.max_free_guests' => 'nullable|integer|min:0',
            'items.*.room_config.extra_guest_fee' => 'nullable|numeric|min:0',
            'items.*.price'                     => 'nullable|numeric|min:0',
            'items.*.default_checkin'           => 'nullable|date_format:H:i',
            'items.*.default_checkout'          => 'nullable|date_format:H:i',
            'items.*.deposit_min_nights'        => 'nullable|integer|min:1',
            'items.*.deposit_multi_night'       => 'nullable|integer|min:0|max:100',
            'items.*.time_slots'                => 'nullable|array',
            'items.*.time_slots.*.timeslot_id'  => 'required|integer',
            'items.*.time_slots.*.price'        => 'required|integer|min:0',
            'items.*.time_slots.*.over_night'   => 'nullable|boolean',
            'items.*.time_slots.*.status'       => 'nullable|string|max:30',
        ];
    }

    private function save(User $user, ?PriceBoard $board, array $data): JsonResponse
    {
        $partnerId = (string) $user->partner_id;

        // Chi nhánh: bắt buộc thuộc đối tác (và nằm trong chi nhánh nhân viên được giao).
        $branchId = array_key_exists('branch_id', $data) ? (int) $data['branch_id'] : (int) $board?->category_id;
        $branch   = $this->branches($user)->firstWhere('id', $branchId);

        if (! $branch) {
            return response()->json(['message' => 'Chi nhánh không thuộc đối tác của bạn.'], 422);
        }

        $mode = $data['pricing_mode'] ?? $board?->pricing_mode ?? PriceBoard::MODE_OVERRIDE;

        if ($mode === PriceBoard::MODE_ADJUSTMENT) {
            $type  = $data['adjustment_type'] ?? $board?->adjustment_type;
            $value = $data['adjustment_value'] ?? $board?->adjustment_value;

            if (! $type || $value === null) {
                return response()->json(['message' => 'Bảng kiểu điều chỉnh cần adjustment_type và adjustment_value.'], 422);
            }
        }

        $isAdjustment = $mode === PriceBoard::MODE_ADJUSTMENT;
        $hasRooms     = $isAdjustment ? array_key_exists('product_ids', $data) : array_key_exists('items', $data);

        if (! $board && ! $hasRooms) {
            return response()->json(['message' => $isAdjustment ? 'Chọn phòng áp dụng (product_ids).' : 'Chọn phòng áp dụng (items).'], 422);
        }

        // Phòng + khung giờ phải thuộc đối tác và (với phòng) đúng chi nhánh của bảng.
        $payload    = null;
        $productIds = collect();

        if ($hasRooms) {
            $requested  = $isAdjustment
                ? collect($data['product_ids'] ?? [])
                : collect($data['items'] ?? [])->pluck('product_id');
            $requested  = $requested->filter()->unique()->values();
            $products   = $this->roomsOf($user, $branchId, $requested->all());

            if ($products->count() !== $requested->count()) {
                $missing = $requested->diff($products->keys());

                return response()->json(['message' => 'Phòng không thuộc chi nhánh/đối tác của bạn: ' . $missing->implode(', ') . '.'], 422);
            }

            $productIds = $requested;

            if ($isAdjustment) {
                $payload = ['product_ids' => $requested->all()];
            } else {
                try {
                    $payload = ['items' => $this->buildItems($partnerId, $data['items'] ?? [], $products)];
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
            }
        }

        $fields = collect($data)->only(['name', 'note', 'start_date', 'end_date', 'is_active', 'pricing_mode', 'adjustment_type', 'adjustment_value'])->all();
        $fields['category_id'] = $branchId;

        $candidate = $board ?? new PriceBoard();
        $candidate->fill($fields);

        try {
            $this->service->assertNoOverlap($candidate, $hasRooms ? $productIds : ($board ? $board->items()->pluck('product_id') : collect()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $saved = DB::transaction(function () use ($board, $fields, $partnerId, $payload) {
            if ($board) {
                $board->update($fields);
            } else {
                $board = PriceBoard::create($fields + ['partner_id' => $partnerId, 'is_default' => false]);
            }

            if ($payload !== null) {
                $this->service->saveItems($board, $payload);
            }

            $this->service->resyncBoardProducts($board);

            return $board;
        });

        return response()->json(['data' => $this->toItem($saved->fresh(), true)], $board ? 200 : 201);
    }

    /**
     * Chuyển body API sang dạng PriceBoardSyncService::saveOverrideItems() đọc (cùng form Filament).
     * Field bỏ trống lấy theo giá trị hiện tại của phòng để không vô tình xoá điều kiện giảm giá.
     */
    private function buildItems(string $partnerId, array $items, $products): array
    {
        $usable = TimeSlot::usableBy($partnerId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $out    = [];

        foreach ($items as $row) {
            /** @var Product $product */
            $product = $products[$row['product_id']];
            $style   = (int) ($product->styles ?? 1);
            $config  = array_merge((array) $product->room_config, (array) ($row['room_config'] ?? []));

            $item = [
                'product_id'                  => $product->id,
                'full_booking_discount'       => array_key_exists('full_booking_discount', $row) ? $row['full_booking_discount'] : $product->full_booking_discount,
                'bulk_discount_rules'         => array_key_exists('bulk_discount_rules', $row) ? $row['bulk_discount_rules'] : $product->bulk_discount_rules,
                'room_config_max_free_guests' => $config['max_free_guests'] ?? 2,
                'room_config_extra_guest_fee' => $config['extra_guest_fee'] ?? 0,
            ];

            if ($style === 2) {
                foreach (['price', 'default_checkin', 'default_checkout', 'deposit_min_nights', 'deposit_multi_night'] as $key) {
                    $item[$key] = $row[$key] ?? $product->{$key};
                }

                $out[] = $item;
                continue;
            }

            if (array_key_exists('time_slots', $row)) {
                $slots = $row['time_slots'] ?? [];
            } else {
                $slots = $product->roomTimeSlots()->whereNull('date')->get()
                    ->map(fn ($s) => ['timeslot_id' => $s->timeslot_id, 'price' => (int) $s->price, 'over_night' => $s->over_night, 'status' => $s->status])
                    ->all();
            }

            $foreign = collect($slots)->pluck('timeslot_id')->map(fn ($v) => (int) $v)->diff($usable);
            if ($foreign->isNotEmpty()) {
                throw new RuntimeException('Khung giờ #' . $foreign->implode(', #') . ' không thuộc đối tác của bạn.');
            }

            $item['roomTimeSlots'] = array_values($slots);
            $out[] = $item;
        }

        return $out;
    }

    private function toItem(PriceBoard $board, bool $detail = false): array
    {
        $today  = now()->startOfDay();
        $status = match (true) {
            ! $board->is_active                                   => 'inactive',
            $board->coversDate($today)                            => 'active',
            $board->start_date && $today->lt($board->start_date)  => 'pending',
            default                                               => 'expired',
        };

        $out = [
            'id'               => $board->id,
            'name'             => $board->name,
            'note'             => $board->note,
            'partner_id'       => $board->partner_id,
            'branch_id'        => $board->category_id,
            'branch'           => $board->relationLoaded('branch') || $detail ? ($board->branch ? ['id' => $board->branch->id, 'name' => $board->branch->name] : null) : null,
            'start_date'       => $board->start_date?->toDateString(),
            'end_date'         => $board->end_date?->toDateString(),
            'is_active'        => (bool) $board->is_active,
            'status'           => $status,
            'pricing_mode'     => $board->pricing_mode,
            'adjustment_type'  => $board->adjustment_type,
            'adjustment_value' => $board->adjustment_value === null ? null : (float) $board->adjustment_value,
            'rooms_count'      => $board->items_count ?? $board->items()->count(),
        ];

        if (! $detail) {
            return $out;
        }

        $items = $board->items()->with(['timeSlots.timeslot', 'product'])->get();

        if ($board->isAdjustment()) {
            $out['product_ids'] = $items->pluck('product_id')->values();
            $out['rooms']       = $items->map(fn (PriceBoardItem $i) => ['id' => $i->product_id, 'name' => $i->product?->name])->values();

            return $out;
        }

        $out['items'] = $items->map(fn (PriceBoardItem $i) => [
            'product_id'            => $i->product_id,
            'room_name'             => $i->product?->name,
            'styles'                => (int) ($i->product?->styles ?? 1),
            'price'                 => $i->price === null ? null : (float) $i->price,
            'full_booking_discount' => $i->full_booking_discount,
            'bulk_discount_rules'   => $i->bulk_discount_rules ?? [],
            'room_config'           => $i->room_config,
            'default_checkin'       => $i->default_checkin,
            'default_checkout'      => $i->default_checkout,
            'deposit_min_nights'    => $i->deposit_min_nights,
            'deposit_multi_night'   => $i->deposit_multi_night,
            'time_slots'            => $i->timeSlots
                ->sortBy(fn ($s) => $s->timeslot?->start_time ?? '99:99:99')
                ->map(fn ($s) => [
                    'timeslot_id' => $s->timeslot_id,
                    'label'       => $s->timeslot?->label,
                    'start_time'  => $s->timeslot?->start_time,
                    'end_time'    => $s->timeslot?->end_time,
                    'price'       => $s->price === null ? null : (int) $s->price,
                    'over_night'  => (bool) $s->over_night,
                    'status'      => $s->status,
                ])->values(),
        ])->values();

        return $out;
    }

    private function find(User $user, int $id): ?PriceBoard
    {
        return PriceBoard::visibleTo($user)->with('branch:id,name')->find($id);
    }

    /** Tài khoản đối tác dùng đối tác của mình; super_admin phải chọn đối tác (X-Partner-Id). */
    private function partnerError(User $user): ?JsonResponse
    {
        if ($user->seesAllPartners()) {
            return response()->json(['message' => 'Chọn đối tác (header X-Partner-Id) để tạo/sửa bảng giá cho đối tác đó.'], 422);
        }

        if (empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản chưa thuộc đối tác nào.'], 403);
        }

        return null;
    }

    /** Chi nhánh của đối tác — nhân viên chỉ được giao vài chi nhánh thì chỉ những chi nhánh đó. */
    private function branches(User $user)
    {
        $allowed = $user->allowedCategoryIds();

        return Category::withoutGlobalScopes()
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->where('partner_id', $user->partner_id)
            ->when(! empty($allowed), fn ($q) => $q->whereIn('id', $allowed))
            ->get(['id', 'name', 'partner_id']);
    }

    /** Phòng đối tác nằm trong chi nhánh $branchId, key theo id. */
    private function roomsOf(User $user, int $branchId, array $ids)
    {
        if (! $ids) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->where('partner_id', $user->partner_id)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', PriceBoard::branchCategoryIds($branchId)))
            ->get()
            ->keyBy('id');
    }
}
