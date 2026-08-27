<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Services\NotificationFcmService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gửi push notification thủ công cho khách hàng (bảng notification_fcm/notification_fcm_recipients)
 * — API tương đương form "Push Notification" ở Filament (App\Filament\Resources\NotificationFcmResource),
 * dùng cho admin app riêng (Bearer token) thay vì CMS.
 *
 * Gửi ngay nếu không có scheduled_at hoặc scheduled_at đã ở quá khứ/hiện tại; nếu scheduled_at ở
 * tương lai thì chỉ lưu record kèm recipient_ids, App\Console\Commands\SendScheduledNotificationsCommand
 * (chạy mỗi phút qua scheduler) sẽ gửi khi đến giờ.
 *
 * update() chỉ cho sửa thông báo CHƯA gửi (đang lên lịch chờ) — đã gửi rồi thì dùng resend() để
 * gửi lại (tạo bản ghi mới, có thể chỉnh nội dung/người nhận trước khi gửi lại).
 */
class PushNotificationController extends Controller
{
    /**
     * GET /api/admin/push-notification
     * Query: search (theo tiêu đề), sent_for (all|users), status (pending|sent), per_page.
     * Chỉ trả về thông báo admin tự tạo (created_by khác null) — bỏ qua thông báo hệ thống tự
     * động gửi cho khách (booking, checkin_reminder, checkout_warning, membership_auto_coupon...
     * xem App\Services\NotificationFcmService) vì các thông báo đó không gán created_by.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = NotificationFcm::query()
            ->whereNotNull('created_by')
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('sent_for'), fn ($q) => $q->where('sent_for', $request->input('sent_for')))
            ->when($request->input('status') === 'pending', fn ($q) => $q->whereNotNull('scheduled_at')->whereNull('sent_at'))
            ->when($request->input('status') === 'sent', fn ($q) => $q->whereNotNull('sent_at'))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20))
            ->through(fn (NotificationFcm $n) => $this->toItem($n));

        return response()->json($notifications);
    }

    /**
     * GET /api/admin/push-notification/{id}
     */
    public function show(string $id): JsonResponse
    {
        $notification = $this->findAdminNotification($id);

        if (! $notification) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($notification)]);
    }

    /**
     * POST /api/admin/push-notification
     * Body: title, body, sent_for (all|users), customer_ids[] (bắt buộc nếu sent_for=users),
     * scheduled_at (tuỳ chọn — để trống hoặc quá khứ = gửi ngay), url (tuỳ chọn — đường dẫn/deep-link
     * app mở khi khách bấm vào thông báo, xem NotificationFcmResource của CMS để cùng convention).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $customerIds = $this->resolveCustomerIds($data['sent_for'], $data['customer_ids'] ?? null);
        $scheduledAt = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $notification = NotificationFcm::create([
            'title'         => $data['title'],
            'body'          => $data['body'],
            'url'           => $data['url'] ?? null,
            'type'          => 'manual',
            'sent_for'      => $data['sent_for'],
            'scheduled_at'  => $scheduledAt,
            'created_by'    => $request->user()->id,
            'recipient_ids' => $isScheduled ? $customerIds : null,
        ]);

        $this->dispatchNow($notification, $customerIds, $isScheduled);

        return response()->json(['data' => $this->toItem($notification->fresh())], 201);
    }

    /**
     * PUT /api/admin/push-notification/{id}
     * Chỉ sửa được khi thông báo CHƯA gửi (đang lên lịch chờ). Body giống store(); nếu bỏ trống/
     * lùi scheduled_at về quá khứ thì gửi ngay luôn khi lưu.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $notification = $this->findAdminNotification($id);

        if (! $notification) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        if ($notification->sent_at !== null) {
            return response()->json([
                'message' => 'Thông báo đã được gửi, không thể chỉnh sửa. Hãy dùng chức năng gửi lại.',
            ], 422);
        }

        $data = $request->validate($this->rules());

        $customerIds = $this->resolveCustomerIds($data['sent_for'], $data['customer_ids'] ?? null);
        $scheduledAt = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $notification->update([
            'title'         => $data['title'],
            'body'          => $data['body'],
            'url'           => $data['url'] ?? null,
            'sent_for'      => $data['sent_for'],
            'scheduled_at'  => $scheduledAt,
            'recipient_ids' => $isScheduled ? $customerIds : null,
        ]);

        $this->dispatchNow($notification, $customerIds, $isScheduled);

        return response()->json(['data' => $this->toItem($notification->fresh())]);
    }

    /**
     * POST /api/admin/push-notification/{id}/resend
     * Gửi lại 1 thông báo (đã gửi hoặc đang chờ) — tạo BẢN GHI MỚI, giữ nguyên lịch sử bản gốc.
     * Body (tất cả tuỳ chọn, bỏ trống = giữ nguyên như bản gốc): title, body, sent_for,
     * customer_ids[], scheduled_at, url.
     */
    public function resend(Request $request, string $id): JsonResponse
    {
        $source = $this->findAdminNotification($id);

        if (! $source) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        $data = $request->validate([
            'title'           => 'sometimes|string|max:255',
            'body'            => 'sometimes|string|max:1000',
            'sent_for'        => 'sometimes|in:all,users',
            'customer_ids'    => 'sometimes|array|min:1',
            'customer_ids.*'  => 'string|exists:customers,id',
            'scheduled_at'    => 'nullable|date',
            'url'             => 'sometimes|nullable|string|max:500',
        ]);

        $sentFor = $data['sent_for'] ?? $source->sent_for;

        if (isset($data['customer_ids'])) {
            $customerIdsInput = $data['customer_ids'];
        } elseif ($sentFor === 'users') {
            $customerIdsInput = $this->sourceCustomerIds($source);

            if (empty($customerIdsInput)) {
                return response()->json([
                    'message' => 'Không xác định được danh sách người nhận cũ, vui lòng chọn customer_ids.',
                ], 422);
            }
        } else {
            $customerIdsInput = null;
        }

        $customerIds = $this->resolveCustomerIds($sentFor, $customerIdsInput);
        $scheduledAt = array_key_exists('scheduled_at', $data) && $data['scheduled_at'] !== null
            ? Carbon::parse($data['scheduled_at'])
            : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $notification = NotificationFcm::create([
            'title'         => $data['title'] ?? $source->title,
            'body'          => $data['body'] ?? $source->body,
            'url'           => array_key_exists('url', $data) ? $data['url'] : $source->url,
            'type'          => 'manual',
            'sent_for'      => $sentFor,
            'scheduled_at'  => $scheduledAt,
            'created_by'    => $request->user()->id,
            'recipient_ids' => $isScheduled ? $customerIds : null,
        ]);

        $this->dispatchNow($notification, $customerIds, $isScheduled);

        return response()->json(['data' => $this->toItem($notification->fresh())], 201);
    }

    /**
     * Chỉ tìm thông báo admin tự tạo (created_by khác null) — thông báo hệ thống tự động gửi
     * (booking, checkin_reminder...) không thuộc phạm vi quản lý của API này.
     */
    private function findAdminNotification(string $id): ?NotificationFcm
    {
        return NotificationFcm::whereNotNull('created_by')->find($id);
    }

    private function rules(): array
    {
        return [
            'title'           => 'required|string|max:255',
            'body'            => 'required|string|max:1000',
            'sent_for'        => 'required|in:all,users',
            'customer_ids'    => 'required_if:sent_for,users|array|min:1',
            'customer_ids.*'  => 'string|exists:customers,id',
            'scheduled_at'    => 'nullable|date',
            // URL/deep-link app mở khi khách bấm vào thông báo — không bắt buộc, cùng field 'url'
            // đã dùng ở NotificationFcmResource (CMS) và NotificationFcmService::sendToCustomer/
            // sendToMany. Không gửi -> lưu null, thông báo không điều hướng đi đâu.
            'url'             => 'sometimes|nullable|string|max:500',
        ];
    }

    private function resolveCustomerIds(string $sentFor, ?array $customerIds): array
    {
        return $sentFor === 'all'
            ? Customer::whereNotNull('token_device')
                ->where('status', Customer::STATUS_ACTIVE)
                ->pluck('id')
                ->toArray()
            : ($customerIds ?? []);
    }

    /**
     * Danh sách customer_id của lần gửi trước — dùng cho resend() khi không truyền customer_ids mới.
     * Nếu bản gốc là gửi ngay (không lên lịch) thì recipient_ids không được lưu, phải tra ngược
     * qua bảng notification_fcm_recipients.
     */
    private function sourceCustomerIds(NotificationFcm $source): array
    {
        if (! empty($source->recipient_ids)) {
            return $source->recipient_ids;
        }

        return $source->recipients()
            ->whereNotNull('customer_id')
            ->pluck('customer_id')
            ->toArray();
    }

    private function dispatchNow(NotificationFcm $notification, array $customerIds, bool $isScheduled): void
    {
        if ($isScheduled) {
            return;
        }

        $customers = Customer::whereIn('id', $customerIds)
            ->where('status', Customer::STATUS_ACTIVE)
            ->get();

        app(NotificationFcmService::class)->sendToExisting($notification, $customers);
    }

    private function toItem(NotificationFcm $n): array
    {
        return [
            'id'            => $n->id,
            'title'         => $n->title,
            'body'          => $n->body,
            'url'           => $n->url,
            'sent_for'      => $n->sent_for,
            'is_scheduled'  => $n->isPending(),
            'scheduled_at'  => optional($n->scheduled_at)->toIso8601String(),
            'sent_at'       => optional($n->sent_at)->toIso8601String(),
            'sent_count'    => $n->sent_count,
            'fail_count'    => $n->fail_count,
            'created_at'    => $n->created_at->toIso8601String(),
        ];
    }

    private function toDetailItem(NotificationFcm $n): array
    {
        return array_merge($this->toItem($n), [
            'customer_ids' => $n->sent_for === 'users' ? $this->sourceCustomerIds($n) : null,
        ]);
    }
}
