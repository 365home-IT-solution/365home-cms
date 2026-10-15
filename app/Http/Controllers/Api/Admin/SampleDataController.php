<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PartnerSampleDataGenerator;
use App\Services\RoomSlotRandomizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\Product;
use RuntimeException;

/**
 * DỮ LIỆU MẪU của đối tác (nút "Tạo dữ liệu mẫu" trên app) — cùng logic trang quản trị "Dữ liệu mẫu"
 * (App\Services\PartnerSampleDataGenerator).
 *  - POST : sinh đồng bộ trong 1 request đủ để phòng đặt được ngay — tiện ích, dịch vụ đi kèm của chi
 *           nhánh, khung giờ, phòng theo khung giờ đã có giá + điều kiện giảm giá, và 1 bảng giá của chi
 *           nhánh chứa các phòng đó. Cấu trúc khung giờ + giá theo 1 phòng sẵn có của chi nhánh. Chạy
 *           lại nhiều lần không sinh trùng.
 *  - Không có xoá: dữ liệu mẫu là của đối tác, họ sửa tiếp rồi dùng thật.
 *
 * Bắt buộc đã có đối tác + chi nhánh: đối tác dùng chính đối tác của tài khoản; super_admin tạo/xoá hộ
 * thì gửi X-Partner-Id.
 */
class SampleDataController extends Controller
{
    private const DEFAULTS = ['time_slots' => null, 'tags' => 5, 'services' => 4, 'rooms' => 3];

    private const MAX = ['time_slots' => RoomSlotRandomizer::MAX_COUNT, 'tags' => 18, 'services' => 12, 'rooms' => 20];

    public function __construct(private PartnerSampleDataGenerator $generator)
    {
    }

    /**
     * GET /api/admin/sample-data
     * Đủ điều kiện tạo chưa (có chi nhánh chưa), danh sách chi nhánh kèm số phòng hiện có (để app
     * cảnh báo khi chi nhánh đã có phòng) và số lượng mặc định.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $branches = $this->branches($user);
        $rooms    = $this->roomCounts($branches->pluck('id')->all());

        return response()->json(['data' => [
            'partner_id'   => $user->partner_id,
            'can_generate' => $branches->isNotEmpty(),
            'message'      => $branches->isEmpty() ? 'Đối tác chưa có chi nhánh — tạo chi nhánh trước khi tạo dữ liệu mẫu.' : null,
            'branches'     => $branches->map(fn (Category $b) => [
                'id'          => $b->id,
                'name'        => $b->name,
                'slug'        => $b->slug,
                'rooms_count' => (int) ($rooms[$b->id] ?? 0),
            ])->values(),
            'defaults'     => self::DEFAULTS,
            'max'          => self::MAX,
        ]]);
    }

    /**
     * POST /api/admin/sample-data
     * Body (đều tuỳ chọn):
     *  - branch_id : chi nhánh tạo phòng + gắn dịch vụ + áp bảng giá. Bỏ trống thì dùng chi nhánh duy
     *                nhất của đối tác (đối tác có nhiều chi nhánh thì bắt buộc gửi).
     *  - time_slots (2–8): số khung giờ MỖI PHÒNG, BE random trải từ sáng đến tối + 1 khung qua đêm,
     *    cách nhau 15–30 phút. Bỏ trống = theo phòng tham chiếu của chi nhánh (hoặc 5 khung).
     *  - tags (0–18), services (0–12), rooms (0–20): bỏ trống = mặc định
     *    (0, 5, 4, 3). Mục đã có (trùng giờ/tên) bị bỏ qua → số tạo ra có thể ít hơn.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate([
            'branch_id'  => 'nullable|integer',
            'time_slots' => 'nullable|integer|min:' . RoomSlotRandomizer::MIN_COUNT . '|max:' . self::MAX['time_slots'],
            'tags'       => 'nullable|integer|min:0|max:' . self::MAX['tags'],
            'services'   => 'nullable|integer|min:0|max:' . self::MAX['services'],
            'rooms'      => 'nullable|integer|min:0|max:' . self::MAX['rooms'],
        ]);

        $branches = $this->branches($user);

        if ($branches->isEmpty()) {
            return response()->json(['message' => 'Đối tác chưa có chi nhánh — tạo chi nhánh trước khi tạo dữ liệu mẫu.'], 422);
        }

        if (! empty($data['branch_id'])) {
            $branch = $branches->firstWhere('id', (int) $data['branch_id']);
            if (! $branch) {
                return response()->json(['message' => 'Chi nhánh không thuộc đối tác của bạn.'], 422);
            }
        } elseif ($branches->count() === 1) {
            $branch = $branches->first();
        } else {
            return response()->json([
                'message'  => 'Đối tác có nhiều chi nhánh — chọn chi nhánh (branch_id).',
                'branches' => $branches->map(fn (Category $b) => ['id' => $b->id, 'name' => $b->name])->values(),
            ], 422);
        }

        $counts = array_merge(self::DEFAULTS, array_filter(
            collect($data)->except('branch_id')->all(),
            fn ($v) => $v !== null
        ));

        try {
            $result = $this->generator->generate((string) $user->partner_id, (int) $branch->id, $counts);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Đã tạo dữ liệu mẫu.',
            'data'    => array_merge(['branch' => ['id' => $branch->id, 'name' => $branch->name]], $result),
        ], 201);
    }

    /** Bắt buộc biết đối tác: tài khoản đối tác, hoặc super_admin đã chọn đối tác (X-Partner-Id). */
    private function partnerError(User $user): ?JsonResponse
    {
        if ($user->seesAllPartners()) {
            return response()->json(['message' => 'Chọn đối tác (header X-Partner-Id) để thao tác dữ liệu mẫu cho đối tác đó.'], 422);
        }

        if (empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản chưa thuộc đối tác nào.'], 403);
        }

        return null;
    }

    /** @return array<int,int> số phòng theo từng chi nhánh */
    private function roomCounts(array $branchIds): array
    {
        if (! $branchIds) {
            return [];
        }

        $counts = [];
        foreach ($branchIds as $branchId) {
            $counts[$branchId] = Product::withoutGlobalScopes()
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', PriceBoard::branchCategoryIds((int) $branchId)))
                ->count();
        }

        return $counts;
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
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'partner_id']);
    }
}
