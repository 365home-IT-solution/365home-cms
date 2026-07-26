<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\UnlockController as BaseUnlockController;
use App\Models\User;
use App\Services\TTLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;

class UnlockController extends BaseUnlockController
{
    /**
     * POST /api/admin/orders/{order_code}/unlock
     * Admin/lễ tân mở cổng TTLock hộ khách — CHỈ áp dụng cho đơn thuộc chi nhánh đã đăng ký tài
     * khoản TTLock đang hoạt động (TTLockService::hasAccountForCategory()); chi nhánh cấp mã thủ
     * công hoặc không hỗ trợ mở cổng qua app thì trả lỗi ngay, không gọi qua TTLock.
     *
     * Kế thừa UnlockController (khách hàng/guest) để dùng lại NGUYÊN logic xử lý mở khoá — check-in
     * lần đầu / check-out lần sau, cập nhật order_status, realtime broadcast, thông báo Telegram —
     * xem processUnlock()/handleTTLockUnlock() ở lớp cha. Chỉ khác phần xác thực: thay vì xác thực
     * chủ đơn (owner token hoặc SĐT khách vãng lai), ở đây xác thực bằng quyền admin theo đối tác.
     *
     * Vẫn giữ nguyên điều kiện đơn phải 'paid'/'deposit' và (nếu không unlock_anytime) đúng cửa sổ
     * giờ nhận/trả phòng — admin mở hộ khi khách gặp sự cố với app, không phải để bỏ qua các điều
     * kiện nghiệp vụ đó.
     */
    public function unlock(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::with(['items.product', 'accessCodes'])
            ->where('order_code', $orderCode)
            ->first();

        // Không thuộc phạm vi đối tác của admin -> trả 404, cùng quy ước với các API admin khác.
        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        if (! TTLockService::hasAccountForCategory($order->category_id)) {
            return response()->json([
                'message' => 'Chi nhánh của đơn này chưa đăng ký tài khoản TTLock.',
            ], 422);
        }

        return $this->processUnlock($order);
    }

    /**
     * POST /api/admin/orders/{order_code}/open-gate
     * Mở cổng tự do — copy nguyên logic từ OpenGateAction (nút "Mở cổng tự do" ở bảng đơn hàng,
     * Filament OrderResource): gửi lệnh mở khóa TTLock ngay lập tức, KHÔNG kiểm tra cửa sổ giờ
     * nhận/trả phòng (unlock_anytime) và KHÔNG cập nhật checked_in_at/order_status — dùng để hỗ trợ
     * khách vào phòng ngoài giờ/trước giờ nhận phòng, không phải luồng check-in/check-out chính thức
     * (xem unlock() ở trên cho luồng đó, có cập nhật trạng thái đơn).
     */
    public function openGate(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::with('items.product')
            ->where('order_code', $orderCode)
            ->first();

        // Không thuộc phạm vi đối tác của admin -> trả 404, cùng quy ước với các API admin khác.
        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        if (! in_array($order->status, ['paid', 'deposit'])) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng chưa được thanh toán hoặc đã kết thúc.',
            ], 422);
        }

        $product = $order->items->first()?->product;

        if (! $product || ! $product->lock_id) {
            return response()->json([
                'success' => false,
                'message' => 'Phòng này không có TTLock.',
            ], 422);
        }

        $ttlock = TTLockService::forCategory($order->category_id);

        if (! $ttlock) {
            return response()->json([
                'success' => false,
                'message' => 'Chi nhánh chưa kết nối TTLock.',
            ], 422);
        }

        $success = $ttlock->remoteUnlock((int) $product->lock_id);

        Log::info('AdminUnlockController::openGate', [
            'order_id'   => $order->id,
            'order_code' => $order->order_code,
            'lock_id'    => $product->lock_id,
            'success'    => $success,
            'admin'      => $admin->email,
        ]);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Mở cổng thất bại. TTLock không phản hồi hoặc tính năng Remote Unlock chưa được bật trên khóa.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã mở cổng. Lệnh mở khóa đã được gửi thành công đến TTLock.',
        ]);
    }
}
