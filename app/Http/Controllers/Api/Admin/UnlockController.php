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
     * Vẫn giữ nguyên điều kiện đơn phải 'paid'/'deposit', nhưng BỎ QUA cửa sổ giờ nhận/trả phòng
     * (truyền $bypassTimeWindow = true cho processUnlock()) — admin được phép mở cổng ngay dù ngoài
     * khung giờ đặt, không bị chặn bởi thông báo "Ngoài thời gian đặt phòng..." như khách tự mở.
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

        return $this->processUnlock($order, bypassTimeWindow: true);
    }

    /**
     * POST /api/admin/orders/{order_code}/open-gate
     * "Cho mở cổng tự do" — copy nguyên logic từ Action toggle_unlock_anytime ở bảng đơn hàng
     * (Filament OrderResource, cột/nút "Mở cổng tự do"): BẬT/TẮT cờ unlock_anytime trên đơn.
     *
     * Đây KHÔNG phải admin tự mở cổng ngay — đây là API cấp quyền cho CHÍNH KHÁCH HÀNG của đơn được
     * tự mở cổng qua app (UnlockController::unlock()/unlockGuest() ở lớp cha) vào BẤT KỲ lúc nào,
     * không bị giới hạn theo khung giờ nhận/trả phòng (±30 phút) — dùng khi khách đến sớm hoặc về
     * muộn. Là API TOGGLE: gọi lại lần nữa sẽ khoá lại theo giờ. Muốn admin tự mở cổng ngay lập tức
     * hộ khách thì dùng unlock() ở trên (đã tự động bypass khung giờ cho riêng lần mở đó).
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

        if (! TTLockService::hasAccountForCategory($order->category_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Chi nhánh chưa đăng ký tài khoản TTLock.',
            ], 422);
        }

        $order->update(['unlock_anytime' => ! $order->unlock_anytime]);

        Log::info('AdminUnlockController::openGate (toggle unlock_anytime)', [
            'order_id'       => $order->id,
            'order_code'     => $order->order_code,
            'unlock_anytime' => $order->unlock_anytime,
            'admin'          => $admin->email,
        ]);

        return response()->json([
            'success'        => true,
            'unlock_anytime' => $order->unlock_anytime,
            'message'        => $order->unlock_anytime
                ? 'Đã cho phép mở cổng ngoài giờ check-in.'
                : 'Đã tắt cho phép mở cổng ngoài giờ check-in.',
        ]);
    }

    /**
     * POST /api/admin/orders/{order_code}/manual-checkin
     * Lễ tân xác nhận THỦ CÔNG khách đã nhận phòng — dùng khi phòng không có TTLock hoặc khoá lỗi
     * (không có cách nào khác để cập nhật checked_in_at/order_status trong các trường hợp này, vì
     * AccessCode chỉ cấp mã cho khách tự nhập vào khoá vật lý, không tự báo lại hệ thống). Tái sử
     * dụng finalizeCheckInOut() ở lớp cha — cùng broadcast realtime + báo Telegram/admin như luồng
     * mở khoá qua app, chỉ khác là KHÔNG gọi TTLock.
     */
    public function manualCheckin(Request $request, string $orderCode): JsonResponse
    {
        return $this->manualCheckInOut($request, $orderCode, isCheckin: true);
    }

    /**
     * POST /api/admin/orders/{order_code}/manual-checkout
     * Xem docblock manualCheckin() — cùng cơ chế, chiều trả phòng.
     */
    public function manualCheckout(Request $request, string $orderCode): JsonResponse
    {
        return $this->manualCheckInOut($request, $orderCode, isCheckin: false);
    }

    private function manualCheckInOut(Request $request, string $orderCode, bool $isCheckin): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::with(['items.product', 'accessCodes'])
            ->where('order_code', $orderCode)
            ->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        if (! in_array($order->status, ['paid', 'deposit'], true)) {
            return response()->json(['message' => 'Đơn hàng chưa được thanh toán hoặc đã kết thúc.'], 422);
        }

        if ($isCheckin && $order->checked_in_at) {
            return response()->json(['message' => 'Đơn này đã được nhận phòng trước đó.'], 422);
        }

        if (! $isCheckin && (! $order->checked_in_at || $order->checked_out_at)) {
            return response()->json(['message' => 'Đơn này chưa nhận phòng hoặc đã trả phòng trước đó.'], 422);
        }

        $item    = $order->items->where('extra_fee', 0)->first();
        $product = $item?->product;

        if (! $item || ! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng trong đơn hàng.'], 422);
        }

        $this->finalizeCheckInOut($order, $product, $item, $order->accessCodes->first(), $isCheckin, 'manual');

        Log::info('Manual check-in/out confirmed by admin', [
            'order_id'   => $order->id,
            'order_code' => $order->order_code,
            'is_checkin' => $isCheckin,
            'admin'      => $admin->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isCheckin ? 'Đã xác nhận khách nhận phòng.' : 'Đã xác nhận khách trả phòng.',
            'order_status' => $order->refresh()->order_status,
        ]);
    }
}
