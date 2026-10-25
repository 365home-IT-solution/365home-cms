<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BranchServiceSync;
use App\Services\DefaultCatalogImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

/**
 * Dịch vụ đi kèm (bảng additional_services) — CRUD, gắn theo CHI NHÁNH chứ không theo từng phòng:
 * 1 dịch vụ thuộc 1 hoặc nhiều chi nhánh (branch_ids[] — category gốc, category_type=product, xem
 * GET /api/admin/branches), mọi phòng thuộc chi nhánh đó tự có dịch vụ này (App\Services\
 * BranchServiceSync ghi lại room_additional_service_assigns — luồng đặt phòng/tạo đơn vẫn đọc
 * $room->additionalServices như cũ, services[].service_id khi tạo đơn không đổi).
 *
 * Phạm vi: super_admin (không chọn đối tác) xem/sửa tất cả; user thường chỉ dịch vụ của đối tác mình
 * (additional_services.partner_id) — cùng nguyên tắc với RoomController::index().
 */
class ServiceController extends Controller
{
    private const IMAGE_RULE = 'file|mimes:jpg,jpeg,png,webp,avif|max:3072';

    /**
     * GET /api/admin/services
     * Query params:
     *  - is_active : 1/0 — bỏ trống mặc định chỉ lấy dịch vụ đang bật (chỉ dịch vụ đang bật mới dùng
     *                được khi đặt phòng); truyền is_active=0 để xem dịch vụ đã tắt, all=1 để lấy cả 2.
     *  - search    : lọc theo tên dịch vụ
     *  - branch_id : chỉ dịch vụ áp dụng cho chi nhánh này
     *  - room_id   : chỉ dịch vụ phòng này đang có (= dịch vụ của chi nhánh phòng thuộc về)
     *  - order_code: chỉ dịch vụ thêm được vào đơn này (= dịch vụ của phòng trong đơn, tức chi nhánh
     *                đơn đặt) — dùng ở màn chi tiết đơn, khớp đúng danh sách PUT /api/admin/orders/{order_code}
     *                chấp nhận ở services[].service_id.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $query = $this->visibleQuery($user)->with('branches:id,name,slug');

        if ($request->filled('order_code')) {
            $order = Order::query()->with('items:id,order_id,product_id')->where('order_code', (string) $request->string('order_code'))->first();

            if (! $order || (! $user->isSuperAdmin() && $order->partner_id !== $user->partner_id)) {
                return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
            }

            $serviceIds = app(BranchServiceSync::class)
                ->activeServicesQueryForRooms($order->items->pluck('product_id')->all())
                ->pluck('additional_services.id');
            $query->whereIn('additional_services.id', $serviceIds);
        }

        if (! $request->boolean('all')) {
            $query->where('is_active', $request->has('is_active') ? $request->boolean('is_active') : true);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->string('search') . '%');
        }

        if ($request->filled('branch_id')) {
            $query->whereHas('branches', fn ($q) => $q->where('categories.id', $request->integer('branch_id')));
        }

        if ($request->filled('room_id')) {
            $query->whereHas('products', fn ($q) => $q->where('products.id', (string) $request->string('room_id')));
        }

        $services = $query->orderBy('name')->get()->map(fn (AdditionService $s) => $this->toItem($s))->values();

        return response()->json(['data' => $services]);
    }

    /** GET /api/admin/services/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $service = $this->visibleQuery($request->user())->with('branches:id,name,slug')->find($id);

        if (! $service) {
            return response()->json(['message' => 'Không tìm thấy dịch vụ.'], 404);
        }

        return response()->json(['data' => $this->toItem($service)]);
    }

    /**
     * POST /api/admin/services (multipart/form-data nếu gửi kèm ảnh)
     * Body: name (required), price (required, ≥0), branch_ids[] (required, ≥1), is_active (mặc
     * định true), image (file, tuỳ chọn).
     * Dịch vụ thuộc đối tác của người gọi; super_admin không chọn đối tác → thuộc đối tác sở hữu các
     * chi nhánh đã chọn (mọi chi nhánh phải cùng 1 đối tác).
     */
    public function store(Request $request, BranchServiceSync $sync): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'price'        => 'required|integer|min:0',
            'is_active'    => 'nullable|boolean',
            'image'        => 'nullable|' . self::IMAGE_RULE,
            'branch_ids'   => 'required|array|min:1',
            'branch_ids.*' => 'integer|distinct',
        ]);

        [$partnerId, $error] = $this->resolveBranches($user, $data['branch_ids']);
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        $service = DB::transaction(function () use ($request, $data, $partnerId) {
            $service = AdditionService::create([
                'partner_id' => $partnerId,
                'name'       => $data['name'],
                'price'      => $data['price'],
                'is_active'  => $data['is_active'] ?? true,
                'image'      => $request->hasFile('image') ? $request->file('image')->store('addition-services', 'public') : null,
            ]);

            $service->branches()->sync($data['branch_ids']);

            return $service;
        });

        $sync->syncBranches($data['branch_ids']);

        return response()->json(['data' => $this->toItem($service->load('branches:id,name,slug'))], 201);
    }

    /**
     * PUT|POST /api/admin/services/{id} (POST khi cần gửi kèm ảnh)
     * Body như POST, mọi field tuỳ chọn. Có gửi branch_ids thì THAY TOÀN BỘ chi nhánh áp dụng
     * (phòng ở chi nhánh bị bỏ sẽ mất dịch vụ này). remove_image=1 để xoá ảnh.
     */
    public function update(Request $request, int $id, BranchServiceSync $sync): JsonResponse
    {
        /** @var User $user */
        $user    = $request->user();
        $service = $this->visibleQuery($user)->find($id);

        if (! $service) {
            return response()->json(['message' => 'Không tìm thấy dịch vụ.'], 404);
        }

        $data = $request->validate([
            'name'         => 'sometimes|required|string|max:255',
            'price'        => 'sometimes|required|integer|min:0',
            'is_active'    => 'nullable|boolean',
            'image'        => 'nullable|' . self::IMAGE_RULE,
            'remove_image' => 'nullable|boolean',
            'branch_ids'   => 'sometimes|required|array|min:1',
            'branch_ids.*' => 'integer|distinct',
        ]);

        $oldBranchIds = $service->branches()->pluck('categories.id')->all();

        if (array_key_exists('branch_ids', $data)) {
            [$partnerId, $error] = $this->resolveBranches($user, $data['branch_ids']);
            if ($error) {
                return response()->json(['message' => $error], 422);
            }

            if ($service->partner_id !== null && $partnerId !== $service->partner_id) {
                return response()->json(['message' => 'Chi nhánh không thuộc đối tác sở hữu dịch vụ này.'], 422);
            }
        }

        DB::transaction(function () use ($request, $service, $data) {
            $update = collect($data)->only(['name', 'price', 'is_active'])->all();

            if ($request->hasFile('image')) {
                $update['image'] = $request->file('image')->store('addition-services', 'public');
            } elseif (! empty($data['remove_image'])) {
                $update['image'] = app(DefaultCatalogImage::class)->servicePath();
            }

            $service->update($update);

            if (array_key_exists('branch_ids', $data)) {
                $service->branches()->sync($data['branch_ids']);
            }
        });

        if (array_key_exists('branch_ids', $data)) {
            $sync->syncBranches(array_merge($oldBranchIds, $data['branch_ids']));
        }

        return response()->json(['data' => $this->toItem($service->fresh('branches:id,name,slug'))]);
    }

    /**
     * DELETE /api/admin/services/{id}
     * Chặn xoá nếu dịch vụ đã có trong đơn — order_services.service_id có FK ON DELETE CASCADE,
     * xoá dịch vụ sẽ xoá luôn dòng dịch vụ trong các đơn cũ (sai lệch tổng tiền/lịch sử). Khi đó tắt
     * dịch vụ (is_active=0) thay vì xoá.
     */
    public function destroy(Request $request, int $id, BranchServiceSync $sync): JsonResponse
    {
        $service = $this->visibleQuery($request->user())->find($id);

        if (! $service) {
            return response()->json(['message' => 'Không tìm thấy dịch vụ.'], 404);
        }

        if (DB::table('order_services')->where('service_id', $service->id)->exists()) {
            return response()->json(['message' => 'Dịch vụ đã có trong đơn đặt phòng — không thể xoá, hãy tắt dịch vụ (is_active=0).'], 422);
        }

        $branchIds = $service->branches()->pluck('categories.id')->all();

        $service->delete();

        $sync->syncBranches($branchIds);

        return response()->json(['message' => 'Đã xoá dịch vụ.']);
    }

    private function visibleQuery(User $user)
    {
        $query = AdditionService::query();

        if (! $user->seesAllPartners()) {
            $query->where('partner_id', $user->partner_id);
        }

        return $query;
    }

    /**
     * Kiểm tra branch_ids là chi nhánh (category gốc, category_type=product) user được quản lý và
     * cùng 1 đối tác. Trả [partner_id sở hữu, null] hoặc [null, thông báo lỗi].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveBranches(User $user, array $branchIds): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));

        $branches = Category::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->get(['id', 'partner_id']);

        if ($branches->count() !== count($branchIds)) {
            return [null, 'Một hoặc nhiều branch_ids không phải chi nhánh hợp lệ (xem GET /api/admin/branches).'];
        }

        if (! $user->seesAllPartners()) {
            if ($branches->contains(fn ($b) => $b->partner_id !== $user->partner_id)) {
                return [null, 'Một hoặc nhiều chi nhánh không thuộc đối tác của bạn.'];
            }

            return [$user->partner_id, null];
        }

        $partnerIds = $branches->pluck('partner_id')->unique();
        if ($partnerIds->count() !== 1 || $partnerIds->first() === null) {
            return [null, 'Các chi nhánh đã chọn phải cùng thuộc 1 đối tác.'];
        }

        return [$partnerIds->first(), null];
    }

    private function toItem(AdditionService $service): array
    {
        return [
            'id'         => $service->id,
            'partner_id' => $service->partner_id,
            'name'       => $service->name,
            'price'      => $service->price,
            'image'      => $service->image ?: app(DefaultCatalogImage::class)->servicePath(),
            'image_url'  => app(DefaultCatalogImage::class)->serviceUrl($service->image),
            'is_active'  => $service->is_active,
            'branches'   => $service->relationLoaded('branches')
                ? $service->branches->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->values()
                : [],
            'branch_ids' => $service->relationLoaded('branches') ? $service->branches->pluck('id')->values() : [],
        ];
    }
}
