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
