<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VoucherBlock;
use App\Services\VoucherBlockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;

/**
 * Lịch ngừng nhận voucher — cùng dữ liệu với Filament "Cấu hình web › Voucher theo đối tác"
 * (App\Filament\Resources\PartnerVoucherResource; dòng all_days = bước ②). CHỈ Super Admin: mọi đối tác (gửi partner_id), hoặc 1 đối tác
 * khi gửi X-Partner-Id. Chủ đối tác / nhân viên đối tác: 403.
 */
class VoucherBlockController extends Controller
{
    // GET /api/admin/voucher-blocks?active=1&per_page=
    public function index(Request $request): JsonResponse
    {
        $user = $this->authorizeUser($request);

        $page = $this->scoped($user)
            ->with('partner:id,name,common_name')
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($page->through(fn (VoucherBlock $b) => $this->format($b))->toArray() + ['meta_options' => $this->options()]);
    }

    // GET /api/admin/voucher-blocks/options — nhóm voucher, thứ, chi nhánh được chọn (?partner_id= cho Super Admin).
    public function options(?Request $request = null): array|JsonResponse
    {
        $base = [
            'voucher_groups' => collect(VoucherBlock::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'hint' => VoucherBlock::GROUP_HINTS[$key]])->values(),
            'weekdays'       => collect(VoucherBlock::WEEKDAYS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'mixed_modes'    => [
                ['value' => VoucherBlock::MIXED_ANY, 'label' => 'Không nhận voucher cho cả đơn (có 1 đêm bị chặn là chặn)'],
                ['value' => VoucherBlock::MIXED_ALL, 'label' => 'Chỉ chặn khi tất cả các đêm đều rơi vào ngày ngừng nhận'],
            ],
        ];

        if ($request === null) {
            return $base;
        }

        $user      = $this->authorizeUser($request);
        $partnerId = $this->partnerIdFor($user, $request->input('partner_id'));

        return response()->json(['data' => $base + [
            'branches' => $partnerId ? collect($this->branchOptions($user, $partnerId))->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values() : [],
        ]]);
    }

    // GET /api/admin/voucher-blocks/holidays?partner_id=&category_ids[]= — ngày lễ sắp tới trong bảng giá (xem trước).
    public function holidays(Request $request, VoucherBlockService $service): JsonResponse
    {
        $user      = $this->authorizeUser($request);
        $partnerId = $this->partnerIdFor($user, $request->input('partner_id'));

        if (! $partnerId) {
            return response()->json(['message' => 'Cần partner_id.'], 422);
        }

        return response()->json(['data' => $service->upcomingHolidays($partnerId, array_map('intval', (array) $request->input('category_ids', [])))]);
    }

    // GET /api/admin/voucher-blocks/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $block = $this->scoped($this->authorizeUser($request))->with('partner:id,name,common_name')->findOrFail($id);

        return response()->json(['data' => $this->format($block)]);
    }

    // POST /api/admin/voucher-blocks
    public function store(Request $request): JsonResponse
    {
        $user  = $this->authorizeUser($request);
        $block = VoucherBlock::create($this->validated($request, $user) + ['created_by' => $user->id]);

        return response()->json(['message' => 'Đã tạo lịch ngừng nhận voucher.', 'data' => $this->format($block->load('partner'))], 201);
    }

    // PUT/POST /api/admin/voucher-blocks/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $user  = $this->authorizeUser($request);
        $block = $this->scoped($user)->findOrFail($id);
        $block->update($this->validated($request, $user, $block));

        return response()->json(['message' => 'Đã cập nhật lịch ngừng nhận voucher.', 'data' => $this->format($block->fresh('partner'))]);
    }

    // DELETE /api/admin/voucher-blocks/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->scoped($this->authorizeUser($request))->findOrFail($id)->delete();

        return response()->json(['message' => 'Đã xoá lịch ngừng nhận voucher.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function authorizeUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user && $user->isSuperAdmin(), 403, 'Chỉ Super Admin được quản lý lịch ngừng nhận voucher.');

        return $user;
    }

    private function scoped(User $user)
    {
        return $user->seesAllPartners() ? VoucherBlock::query() : VoucherBlock::query()->where('partner_id', $user->partner_id ?? '-');
    }

    private function partnerIdFor(User $user, ?string $requested): ?string
    {
        return $user->seesAllPartners() ? ($requested ?: null) : $user->partner_id;
    }

    private function branchOptions(User $user, string $partnerId): array
    {
        $ids = $user->rootProductCategoryIds();

        return Category::query()->withoutGlobalScopes()
            ->whereNull('parent_id')->where('category_type', 'product')->where('partner_id', $partnerId)
            ->when(! $user->seesAllPartners(), fn ($q) => $q->whereIn('id', $ids ?: [-1]))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Body: name, is_active?, partner_id (Super Admin), category_ids[] (trống = mọi chi nhánh), all_days?, use_price_board_holidays?,
     * date_ranges[]{name?, from: Y-m-d, to: Y-m-d}, weekdays[] (1=T2 … 7=CN), recurring_ranges[]{name?, from, to} (dd/mm hoặc MM-DD),
     * valid_from?, valid_to?, except_dates[] (Y-m-d), blocked_groups[] (mặc định mọi nhóm), mixed_mode (any|all), note?
     */
    private function validated(Request $request, User $user, ?VoucherBlock $existing = null): array
    {
        $data = $request->validate([
            'name'                       => ($existing ? 'sometimes|' : '') . 'required|string|max:150',
            'is_active'                  => 'sometimes|boolean',
            'partner_id'                 => 'sometimes|nullable|string|exists:partners,id',
            'category_ids'               => 'sometimes|array',
            'category_ids.*'             => 'integer',
            'all_days'                   => 'sometimes|boolean',
            'use_price_board_holidays'   => 'sometimes|boolean',
            'date_ranges'                => 'sometimes|array',
            'date_ranges.*.name'         => 'nullable|string|max:100',
            'date_ranges.*.from'         => 'required|date',
            'date_ranges.*.to'           => 'required|date',
            'weekdays'                   => 'sometimes|array',
            'weekdays.*'                 => 'integer|between:1,7',
            'recurring_ranges'           => 'sometimes|array',
            'recurring_ranges.*.name'    => 'nullable|string|max:100',
            'recurring_ranges.*.from'    => 'required|string|max:5',
            'recurring_ranges.*.to'      => 'required|string|max:5',
            'valid_from'                 => 'sometimes|nullable|date',
            'valid_to'                   => 'sometimes|nullable|date|after_or_equal:valid_from',
            'except_dates'               => 'sometimes|array',
            'except_dates.*'             => 'date',
            'blocked_groups'             => 'sometimes|array|min:1',
            'blocked_groups.*'           => ['string', Rule::in(array_keys(VoucherBlock::GROUPS))],
            'mixed_mode'                 => ['sometimes', Rule::in([VoucherBlock::MIXED_ANY, VoucherBlock::MIXED_ALL])],
            'note'                       => 'sometimes|nullable|string|max:1000',
        ]);

        $partnerId = $user->seesAllPartners()
            ? ($data['partner_id'] ?? $existing?->partner_id ?? $user->partner_id)
            : $user->partner_id;

        if (! $partnerId) {
            throw ValidationException::withMessages(['partner_id' => ['Chọn đối tác.']]);
        }
        $data['partner_id'] = $partnerId;

        if (array_key_exists('category_ids', $data)) {
            $allowed = array_map('intval', array_keys($this->branchOptions($user, $partnerId)));
            $invalid = array_diff(array_map('intval', $data['category_ids']), $allowed);
            if ($invalid) {
                throw ValidationException::withMessages(['category_ids' => ['Chi nhánh không thuộc đối tác hoặc ngoài quyền: ' . implode(', ', $invalid) . '.']]);
            }
            $data['category_ids'] = array_values(array_map('intval', $data['category_ids']));
        }

        if (array_key_exists('date_ranges', $data)) {
            foreach ($data['date_ranges'] as $i => $r) {
                if (Carbon::parse($r['to'])->lt(Carbon::parse($r['from']))) {
                    throw ValidationException::withMessages(["date_ranges.{$i}.to" => ['Ngày kết thúc phải >= ngày bắt đầu.']]);
                }
            }
            $data['date_ranges'] = collect($data['date_ranges'])->map(fn ($r) => [
                'name' => $r['name'] ?? null, 'from' => Carbon::parse($r['from'])->toDateString(), 'to' => Carbon::parse($r['to'])->toDateString(),
            ])->values()->all();
        }

        if (array_key_exists('recurring_ranges', $data)) {
            $data['recurring_ranges'] = collect($data['recurring_ranges'])->map(function ($r, $i) {
                $from = VoucherBlock::normalizeMonthDay($r['from']);
                $to   = VoucherBlock::normalizeMonthDay($r['to']);
                if (! $from || ! $to) {
                    throw ValidationException::withMessages(["recurring_ranges.{$i}" => ['Ngày/tháng không hợp lệ (dd/mm hoặc MM-DD).']]);
                }

                return ['name' => $r['name'] ?? null, 'from' => $from, 'to' => $to];
            })->values()->all();
        }

        if (array_key_exists('weekdays', $data)) {
            $data['weekdays'] = array_values(array_unique(array_map('intval', $data['weekdays'])));
        }
        if (array_key_exists('except_dates', $data)) {
            $data['except_dates'] = collect($data['except_dates'])->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all();
        }
        if (! $existing && ! array_key_exists('blocked_groups', $data)) {
            $data['blocked_groups'] = array_keys(VoucherBlock::GROUPS);
        }

        $merged      = array_merge($existing?->only(['all_days', 'use_price_board_holidays', 'date_ranges', 'weekdays', 'recurring_ranges']) ?? [], $data);
        $hasSchedule = ! empty($merged['all_days']) || ! empty($merged['use_price_board_holidays'])
            || ! empty($merged['date_ranges']) || ! empty($merged['weekdays']) || ! empty($merged['recurring_ranges']);

        if (! $hasSchedule) {
            throw ValidationException::withMessages(['all_days' => ['Chọn ít nhất 1 mục ngừng nhận: all_days, use_price_board_holidays, date_ranges, weekdays hoặc recurring_ranges.']]);
        }

        return $data;
    }

    private function format(VoucherBlock $b): array
    {
        return [
            'id'                       => $b->id,
            'name'                     => $b->name,
            'is_active'                => $b->is_active,
            'partner'                  => $b->partner ? ['id' => $b->partner->id, 'name' => $b->partner->common_name ?: $b->partner->name] : null,
            'category_ids'             => (array) $b->category_ids,
            'branches'                 => $b->category_ids ? Category::query()->withoutGlobalScopes()->whereIn('id', $b->category_ids)->get(['id', 'name'])->toArray() : [],
            'all_branches'             => empty($b->category_ids),
            'all_days'                 => $b->all_days,
            'use_price_board_holidays' => $b->use_price_board_holidays,
            'date_ranges'              => (array) $b->date_ranges,
            'weekdays'                 => array_map('intval', (array) $b->weekdays),
            'recurring_ranges'         => collect((array) $b->recurring_ranges)->map(fn ($r) => $r + ['label' => VoucherBlock::dm($r['from'] ?? null) . ' → ' . VoucherBlock::dm($r['to'] ?? null)])->all(),
            'valid_from'               => $b->valid_from?->toDateString(),
            'valid_to'                 => $b->valid_to?->toDateString(),
            'except_dates'             => (array) $b->except_dates,
            'blocked_groups'           => (array) $b->blocked_groups,
            'blocked_groups_label'     => collect((array) $b->blocked_groups)->map(fn ($g) => VoucherBlock::GROUPS[$g] ?? $g)->values(),
            'mixed_mode'               => $b->mixed_mode,
            'schedule_summary'         => $b->scheduleSummary(),
            'note'                     => $b->note,
            'updated_at'               => $b->updated_at?->toDateTimeString(),
        ];
    }
}
