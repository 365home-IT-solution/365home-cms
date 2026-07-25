<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Entities\Order;

class OrderController extends Controller
{
    /**
     * GET /api/admin/orders
     * Danh sách đơn đặt phòng lọc theo đối tác của tài khoản đang đăng nhập — user thuộc đối
     * tác nào (users.partner_id) chỉ thấy đơn của các chi nhánh thuộc đối tác đó
     * (orders.partner_id, gán theo chi nhánh lúc đặt phòng — xem BelongsToPartner/Order::creating).
     * super_admin xem toàn bộ, không lọc.
     *
     * Query params:
     *  - branch_id      : lọc thêm theo 1 chi nhánh cụ thể (category_id) trong đối tác
     *  - status         : pending|paid|deposit|cancelled|failed|refunded|shipped...
     *  - payment_method : PayOS|cod
     *  - search         : order_code / buyer_name / buyer_phone
     *  - from, to       : lọc theo created_at (yyyy-mm-dd)
     *  - per_page       : mặc định 10
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Order::query()->with([
            'items.product:id,name',
            'category:id,name',
            'customer:id,fullname,phone',
        ]);

        if (! $user->isSuperAdmin()) {
            // Tài khoản không thuộc đối tác nào (partner_id null, không phải super_admin) không có
            // dữ liệu hợp lệ để xem — trả rỗng thay vì lộ toàn bộ đơn (WHERE partner_id = NULL sẽ
            // không khớp dòng nào nên về mặt kết quả tương đương, nhưng viết tường minh cho rõ ý).
            if (empty($user->partner_id)) {
                return response()->json($query->whereRaw('1 = 0')->paginate($request->integer('per_page', 10)));
            }

            $query->where('partner_id', $user->partner_id);
        }

        $query
            ->when($request->filled('branch_id'), fn ($q) => $q->where('category_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_method'), fn ($q) => $q->where('payment_method', $request->string('payment_method')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($q2) use ($search) {
                    $q2->where('order_code', 'like', "%{$search}%")
                        ->orWhere('buyer_name', 'like', "%{$search}%")
                        ->orWhere('buyer_phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at');

        $orders = $query->paginate($request->integer('per_page', 10));

        $orders->getCollection()->transform(fn (Order $order) => $this->toListItem($order));

        return response()->json($orders);
    }

    /**
     * PUT /api/admin/orders/{id}
     * Cập nhật đơn (đơn admin/lễ tân tạo qua BookingController hoặc đơn bất kỳ trong phạm vi
     * đối tác của tài khoản đang đăng nhập). Phạm vi CHỈ gồm các trường điều chỉnh tại quầy:
     *   - note_for_admin     : ghi chú nội bộ
     *   - description        : mô tả đầy đủ của đơn
     *   - short_description  : mô tả ngắn (hiển thị danh sách/thông báo)
     *   - status         : chỉ áp dụng cho đơn cod (giống rule ở BookingController::store —
     *                      đơn PayOS luôn đổi trạng thái qua webhook thật, không cho gõ tay đè)
     *   - surcharge      : phụ thu sửa tay (Order.surcharge — cộng thêm ngoài giá phòng/dịch vụ,
     *                      giống hệt field "Phụ thu (có thể sửa tay)" bên CMS OrderForm)
     *   - amount         : tổng tiền đơn sửa tay — admin ghi đè trực tiếp số cuối cùng, giống
     *                      field "Tổng tiền đơn (có thể sửa tay)" bên CMS.
     *
     * 'surcharge' và 'amount' SỬA ĐƯỢC BẤT KỂ trạng thái đơn (kể cả đã paid/deposit) — admin toàn
     * quyền điều chỉnh tại quầy, không giới hạn như CMS. Sửa 'amount' luôn đồng bộ thẳng vào
     * 'full_amount' — LƯU Ý: điều này phá vỡ bất biến "full_amount cố định sau khi thanh toán" mà
     * các chỗ khác (vd Order::depositDueAmount(), báo cáo tài chính) đang dựa vào, là đánh đổi có
     * chủ đích theo yêu cầu nghiệp vụ, không phải sơ suất.
     *
     * LƯU Ý: gửi 'surcharge' không tự động cộng vào 'amount' — 2 field độc lập (giống hệt CMS,
     * nơi 'amount' chỉ tự đồng bộ qua tính toán JS phía client). Muốn tổng tiền phản ánh đúng
     * phụ thu mới, phải tự tính và gửi kèm 'amount' mới trong cùng request.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::query()->with(['items.product:id,name', 'category:id,name', 'customer:id,fullname,phone'])->find($id);

        // Không thuộc phạm vi đối tác của admin -> trả 404 (không phải 403) để không lộ sự tồn
        // tại của đơn đó cho admin không liên quan, cùng quy ước với EmployeeController.
        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $data = $request->validate([
            'note_for_admin'     => 'sometimes|nullable|string|max:500',
            'description'        => 'sometimes|nullable|string|max:500',
            'short_description'  => 'sometimes|nullable|string|max:255',
            'status'             => 'sometimes|in:pending,paid,deposit,failed,cancelled_payment,refunded',
            'surcharge'          => 'sometimes|nullable|numeric|min:0',
            'amount'             => 'sometimes|nullable|numeric|min:0',
        ]);

        $updates = [];

        if (array_key_exists('note_for_admin', $data)) {
            $updates['note_for_admin'] = $data['note_for_admin'];
        }

        if (array_key_exists('description', $data)) {
            $updates['description'] = $data['description'];
        }

        if (array_key_exists('short_description', $data)) {
            $updates['short_description'] = $data['short_description'];
        }

        if (array_key_exists('status', $data) && $order->payment_method !== 'PayOS') {
            $updates['status'] = $data['status'];
        }

        if (array_key_exists('surcharge', $data)) {
            $updates['surcharge'] = (int) ($data['surcharge'] ?? 0);
        }

        if (array_key_exists('amount', $data)) {
            $newAmount = (int) $data['amount'];
            $oldAmount = (int) $order->amount;

            // Sửa được bất kể trạng thái đơn (kể cả đã paid/deposit) — theo yêu cầu nghiệp vụ,
            // khác với CMS (chỉ đồng bộ full_amount khi đơn chưa thanh toán). Luôn đồng bộ thẳng
            // 'full_amount' = 'amount' mới, không phân biệt trạng thái.
            $updates['amount']      = $newAmount;
            $updates['full_amount'] = $newAmount;

            // Giá đổi mà đơn đang có link PayOS cũ -> QR cũ sai số tiền, phải huỷ để tạo lại.
            if ($newAmount !== $oldAmount && $order->checkout_url) {
                $updates['checkout_url']       = null;
                $updates['qr_code']            = null;
                $updates['current_payos_code'] = null;
            }
        }

        if (empty($updates)) {
            return response()->json(['message' => 'Không có trường nào để cập nhật.'], 422);
        }

        $order->update($updates);
        $order->refresh();

        return response()->json(['order' => $this->toListItem($order) + ['surcharge' => (int) $order->surcharge]]);
    }

    private function toListItem(Order $order): array
    {
        return [
            'id'             => $order->id,
            'order_code'     => $order->order_code,
            'status'         => $order->status,
            'payment_status' => $order->payment_status,
            'order_status'   => $order->order_status,
            'payment_method' => $order->payment_method,
            'amount'         => (int) $order->amount,
            'full_amount'    => (int) $order->full_amount,
            'guest_count'    => $order->guest_count,
            'description'       => $order->description,
            'short_description' => $order->short_description,
            'buyer_name'     => $order->buyer_name,
            'buyer_phone'    => $order->buyer_phone,
            'customer'       => $order->customer ? [
                'id'       => $order->customer->id,
                'fullname' => $order->customer->fullname,
                'phone'    => $order->customer->phone,
            ] : null,
            'branch' => $order->category ? [
                'id'   => $order->category->id,
                'name' => $order->category->name,
            ] : null,
            'rooms'        => $order->items->pluck('product.name')->filter()->unique()->values(),
            'checkin_date' => $order->items->pluck('checkin_date')->filter()->min(),
            'checkout_date' => $order->items->pluck('checkout_date')->filter()->max(),
            'created_at'   => $order->created_at,
        ];
    }
}
