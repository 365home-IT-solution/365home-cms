<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Services\PortalBroadcastDispatchService;

// Nhân viên soạn + gửi thông báo đẩy hàng loạt cho khách thuê — mirror ĐÚNG
// App\Http\Controllers\Api\Admin\PushNotificationController (Home): index/show/store/update/resend,
// hỗ trợ gửi ngay hoặc lên lịch (cron xem Modules\Minihouse\App\Console\Commands\
// SendScheduledPortalBroadcastsCommand). Khác Home ở tầng lưu trữ (xem PortalBroadcast) — người dùng
// cuối thấy hành vi giống hệt.
class PushNotificationController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/push-notification?search=&sent_for=&status=pending|sent&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem thông báo.'], 403);
        }

        $query = PortalBroadcast::query();

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->string('search') . '%');
        }

        if ($request->filled('sent_for')) {
            $query->where('sent_for', $request->string('sent_for'));
        }

        if ($request->input('status') === 'pending') {
            $query->whereNotNull('scheduled_at')->whereNull('sent_at');
        } elseif ($request->input('status') === 'sent') {
            $query->whereNotNull('sent_at');
        }

        $items = $query->orderByDesc('created_at')->paginate((int) $request->integer('per_page', 20));

        $items->getCollection()->transform(fn (PortalBroadcast $b) => $this->toListItem($b));

        return response()->json($items);
    }

    // GET /api/admin/minihouse/push-notification/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem thông báo.'], 403);
        }

        $broadcast = PortalBroadcast::find($id);

        if (! $broadcast) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($broadcast)]);
    }

    // POST /api/admin/minihouse/push-notification
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_tenants')) {
            return response()->json(['message' => 'Không có quyền gửi thông báo.'], 403);
        }

        $data = $request->validate($this->rules());

        $tenantIds = $this->resolveTenantIds($request, $data['sent_for'], $data['tenant_ids'] ?? []);
        $scheduledAt = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $broadcast = PortalBroadcast::create([
            'title'           => $data['title'],
            'body'            => $data['body'],
            'link'            => $data['link'] ?? null,
            'sent_for'        => $data['sent_for'],
            'tenant_ids'      => $isScheduled ? $tenantIds : null,
            'scheduled_at'    => $scheduledAt,
            'created_by'      => $request->user()->id,
            'recipient_count' => count($tenantIds),
        ]);

        if (! $isScheduled) {
            PortalBroadcastDispatchService::dispatchNow($broadcast, $tenantIds);
        }

        return response()->json(['data' => $this->toDetailItem($broadcast->fresh())], 201);
    }

    // PUT/PATCH /api/admin/minihouse/push-notification/{id} — chỉ sửa được khi CHƯA gửi.
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_tenants')) {
            return response()->json(['message' => 'Không có quyền sửa thông báo.'], 403);
        }

        $broadcast = PortalBroadcast::whereNotNull('created_by')->find($id);

        if (! $broadcast) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        if ($broadcast->sent_at !== null) {
            return response()->json(['message' => 'Thông báo này đã gửi — không thể sửa, hãy dùng "Gửi lại".'], 422);
        }

        $data = $request->validate($this->rules());

        $tenantIds = $this->resolveTenantIds($request, $data['sent_for'], $data['tenant_ids'] ?? []);
        $scheduledAt = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $broadcast->update([
            'title'           => $data['title'],
            'body'            => $data['body'],
            'link'            => $data['link'] ?? null,
            'sent_for'        => $data['sent_for'],
            'tenant_ids'      => $isScheduled ? $tenantIds : null,
            'scheduled_at'    => $scheduledAt,
            'recipient_count' => count($tenantIds),
        ]);

        if (! $isScheduled) {
            PortalBroadcastDispatchService::dispatchNow($broadcast, $tenantIds);
        }

        return response()->json(['data' => $this->toDetailItem($broadcast->fresh())]);
    }

    // POST /api/admin/minihouse/push-notification/{id}/resend — tạo BẢN GHI MỚI, giữ nguyên lịch sử
    // bản gốc (mirror Home). Không truyền tenant_ids mới thì tự suy lại theo đúng tiêu chí cũ.
    public function resend(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_tenants')) {
            return response()->json(['message' => 'Không có quyền gửi lại thông báo.'], 403);
        }

        $source = PortalBroadcast::find($id);

        if (! $source) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $data = $request->validate([
            'title'          => 'sometimes|string|max:255',
            'body'           => 'sometimes|string|max:1000',
            'link'           => 'sometimes|nullable|string|max:500',
            'sent_for'       => ['sometimes', Rule::in([PortalBroadcast::SENT_FOR_ALL, PortalBroadcast::SENT_FOR_TENANTS])],
            'tenant_ids'     => 'sometimes|array',
            'tenant_ids.*'   => 'integer|exists:minihouse_tenants,id',
        ]);

        $sentFor = $data['sent_for'] ?? $source->sent_for;
        $tenantIds = $data['tenant_ids'] ?? PortalBroadcastDispatchService::sourceTenantIds($source);

        if ($sentFor === PortalBroadcast::SENT_FOR_TENANTS && empty($tenantIds)) {
            return response()->json(['message' => 'Không tìm được danh sách khách của lần gửi trước — vui lòng tự chọn tenant_ids.'], 422);
        }

        $resolvedIds = $this->resolveTenantIds($request, $sentFor, $tenantIds);

        $broadcast = PortalBroadcast::create([
            'title'           => $data['title'] ?? $source->title,
            'body'            => $data['body'] ?? $source->body,
            'link'            => $data['link'] ?? $source->link,
            'sent_for'        => $sentFor,
            'tenant_ids'      => null,
            'scheduled_at'    => null,
            'created_by'      => $request->user()->id,
            'recipient_count' => count($resolvedIds),
        ]);

        PortalBroadcastDispatchService::dispatchNow($broadcast, $resolvedIds);

        return response()->json(['data' => $this->toDetailItem($broadcast->fresh())], 201);
    }

    private function rules(): array
    {
        return [
            'title'        => 'required|string|max:255',
            'body'         => 'required|string|max:1000',
            'link'         => 'sometimes|nullable|string|max:500',
            'sent_for'     => ['required', Rule::in([PortalBroadcast::SENT_FOR_ALL, PortalBroadcast::SENT_FOR_TENANTS])],
            'tenant_ids'   => 'required_if:sent_for,' . PortalBroadcast::SENT_FOR_TENANTS . '|array|min:1',
            'tenant_ids.*' => 'integer|exists:minihouse_tenants,id',
            'scheduled_at' => 'nullable|date',
        ];
    }

    // "all" = mọi khách thuê đang có ít nhất 1 thiết bị đăng ký — mirror ĐÚNG
    // Api\Admin\PushNotificationController::resolveCustomerIds() (Home: "all" lọc theo
    // whereNotNull(token_device)->where(status,ACTIVE), còn "users" TIN THẲNG customer_ids admin tự
    // chọn, không lọc lại theo token). LUÔN tự giới hạn theo đúng phạm vi toà nhà tài khoản đang gọi
    // được quản lý — cùng nguyên tắc ScopesToMinihouseBuilding áp dụng cho mọi controller MiniHouse
    // khác. Logic thật nằm ở PortalBroadcastDispatchService (dùng chung với PortalBroadcastResource
    // trong panel), ở đây chỉ chuyển permittedBuildingIds($request) sang.
    private function resolveTenantIds(Request $request, string $sentFor, array $tenantIds): array
    {
        return PortalBroadcastDispatchService::resolveTenantIds($this->permittedBuildingIds($request), $sentFor, $tenantIds);
    }

    private function toListItem(PortalBroadcast $b): array
    {
        return [
            'id'               => $b->id,
            'title'            => $b->title,
            'sent_for'         => $b->sent_for,
            'delivery_status'  => $b->isPending() ? 'scheduled' : ($b->sent_at ? 'sent' : 'draft'),
            'recipient_count'  => $b->recipient_count,
            'scheduled_at'     => $b->scheduled_at?->toIso8601String(),
            'sent_at'          => $b->sent_at?->toIso8601String(),
            'created_at'       => $b->created_at->toIso8601String(),
        ];
    }

    private function toDetailItem(PortalBroadcast $b): array
    {
        return array_merge($this->toListItem($b), [
            'body'       => $b->body,
            'link'       => $b->link,
            'tenant_ids' => $b->tenant_ids,
            'creator'    => $b->creator ? ['id' => $b->creator->id, 'fullname' => $b->creator->fullname ?? $b->creator->email] : null,
        ]);
    }
}
