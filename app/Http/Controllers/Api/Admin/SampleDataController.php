<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PartnerSampleDataGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;
use RuntimeException;

/**
 * DỮ LIỆU MẪU của đối tác (nút "Tạo dữ liệu mẫu" / "Xoá dữ liệu mẫu" trên app) — cùng logic trang quản
 * trị "Dữ liệu mẫu" (App\Services\PartnerSampleDataGenerator).
 *  - POST   : sinh đồng bộ trong 1 request đủ để phòng đặt được ngay — khung giờ, bảng giá mẫu, tiện
 *             ích, dịch vụ đi kèm của chi nhánh, phòng theo khung giờ đã có giá + điều kiện giảm giá.
 *             Chạy lại nhiều lần không sinh trùng.
 *  - DELETE : xoá đúng những gì POST đã tạo (theo dõi ở sample_data_records); giữ lại mục đã có đơn
 *             hoặc đang được dữ liệu thật dùng, trả về danh sách giữ lại kèm lý do.
 *
 * Bắt buộc đã có đối tác + chi nhánh: đối tác dùng chính đối tác của tài khoản; super_admin tạo/xoá hộ
 * thì gửi X-Partner-Id.
 */
class SampleDataController extends Controller
{
    private const DEFAULTS = ['time_slots' => 4, 'templates' => 2, 'tags' => 5, 'services' => 4, 'rooms' => 3];

    private const MAX = ['time_slots' => 10, 'templates' => 10, 'tags' => 18, 'services' => 12, 'rooms' => 20];

    public function __construct(private PartnerSampleDataGenerator $generator)
    {
    }

    /**
     * GET /api/admin/sample-data
     * Đủ điều kiện tạo chưa (có chi nhánh chưa), số lượng mặc định, và dữ liệu mẫu đang có (để hiện
     * nút "Xoá dữ liệu mẫu").
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $branches = $this->branches($user);
        $existing = $this->generator->summary((string) $user->partner_id);

        return response()->json(['data' => [
            'partner_id'   => $user->partner_id,
            'can_generate' => $branches->isNotEmpty(),
            'message'      => $branches->isEmpty() ? 'Đối tác chưa có chi nhánh — tạo chi nhánh trước khi tạo dữ liệu mẫu.' : null,
            'branches'     => $branches->map(fn (Category $b) => ['id' => $b->id, 'name' => $b->name, 'slug' => $b->slug])->values(),
            'defaults'     => self::DEFAULTS,
            'max'          => self::MAX,
            'existing'     => $existing,
            'has_sample'   => array_sum($existing) > 0,
        ]]);
    }

    /**
     * POST /api/admin/sample-data
     * Body (đều tuỳ chọn):
     *  - branch_id : chi nhánh tạo phòng + gắn dịch vụ. Bỏ trống thì dùng chi nhánh duy nhất của đối tác
     *                (đối tác có nhiều chi nhánh thì bắt buộc gửi).
     *  - time_slots (0–10), templates (0–10), tags (0–18), services (0–12), rooms (0–20): bỏ trống =
     *    mặc định (4, 2, 5, 4, 3). Mục đã có (trùng giờ/tên) bị bỏ qua → số tạo ra có thể ít hơn.
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
            'time_slots' => 'nullable|integer|min:0|max:' . self::MAX['time_slots'],
            'templates'  => 'nullable|integer|min:0|max:' . self::MAX['templates'],
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

    /**
     * DELETE /api/admin/sample-data
     * Xoá toàn bộ dữ liệu mẫu đã tạo cho đối tác (mọi lần bấm tạo). Giữ lại: phòng đã có đơn, dịch vụ đã
     * nằm trong đơn, khung giờ/tiện ích đang được phòng thật dùng — trả về trong `kept` kèm lý do.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $partnerId = (string) $user->partner_id;

        if (array_sum($this->generator->summary($partnerId)) === 0) {
            return response()->json(['message' => 'Đối tác không có dữ liệu mẫu nào để xoá.'], 404);
        }

        $result = $this->generator->purge($partnerId);

        return response()->json([
            'message' => $result['kept']
                ? 'Đã xoá dữ liệu mẫu, giữ lại ' . count($result['kept']) . ' mục đang được sử dụng.'
                : 'Đã xoá toàn bộ dữ liệu mẫu.',
            'data'    => $result,
        ]);
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
