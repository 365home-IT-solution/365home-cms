<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderRealtimeService;
use App\Services\TelegramService;
use App\Services\TTLockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

class UnlockController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
        private OrderRealtimeService $realtime,
    ) {}

    /**
     * Mở cổng cho user đăng nhập.
     * POST /api/orders/{order_code}/unlock
     */
    public function unlock(Request $request, string $orderCode): JsonResponse
    {
        $order = Order::with(['items.product', 'accessCodes'])
            ->where('order_code', $orderCode)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng.'], 404);
        }

        $userId = (string) auth()->id();

        $isOwner = ($order->user_id     && (string) $order->user_id     === $userId)
                || ($order->customer_id && (string) $order->customer_id === $userId);

        if (!$isOwner) {
            return response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
        }

        return $this->processUnlock($order);
    }

    /**
     * Mở cổng cho guest (xác thực bằng số điện thoại).
     * POST /api/guest/orders/{order_code}/unlock
     * Body: phone (bắt buộc)
     */
    public function unlockGuest(Request $request, string $orderCode): JsonResponse
    {
        $request->validate(['phone' => 'required|string']);

        $order = Order::with(['items.product', 'accessCodes'])
            ->where('order_code', $orderCode)
            ->where('buyer_phone', $request->phone)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Không tìm thấy đơn hàng hoặc số điện thoại không khớp.'], 404);
        }

        return $this->processUnlock($order);
    }

    // =========================================================
    // PRIVATE
    // =========================================================

    protected function processUnlock(Order $order, bool $bypassTimeWindow = false): JsonResponse
    {
        if (!in_array($order->status, ['paid', 'deposit'])) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng chưa được thanh toán hoặc đã kết thúc.',
            ], 422);
        }

        $mainItems = $order->items->where('extra_fee', 0);
        $item      = $mainItems->first();
        $product   = $item?->product;

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy phòng trong đơn hàng.',
            ], 422);
        }

        // Kiểm tra cửa sổ thời gian - hỗ trợ nhiều khung giờ (buffer 30 phút trước/sau).
        // $bypassTimeWindow: admin mở hộ được phép bỏ qua cửa sổ giờ (vd hỗ trợ khách vào sớm/muộn) —
        // xem Admin\UnlockController::unlock().
        if (!$order->unlock_anytime && !$bypassTimeWindow) {
            $now          = now();
            $activeItem   = null;
            $earliestItem = null;

            foreach ($mainItems as $it) {
                if (!$it->checkin_date || !$it->checkout_date) {
                    continue;
                }

                $earliest = Carbon::parse($it->checkin_date)->subMinutes(30);
                $latest   = Carbon::parse($it->checkout_date)->addMinutes(30);

                if ($now->between($earliest, $latest)) {
                    $activeItem = $it;
                    break;
                }

                if ($now->lt($earliest)) {
                    if (!$earliestItem || Carbon::parse($it->checkin_date)->lt(Carbon::parse($earliestItem->checkin_date))) {
                        $earliestItem = $it;
                    }
                }
            }

            $datedItems = $mainItems->filter(fn($it) => $it->checkin_date && $it->checkout_date);

            if ($datedItems->isNotEmpty() && !$activeItem) {
                $message = $earliestItem
                    ? 'Ngoài thời gian đặt phòng. Khung giờ tiếp theo bắt đầu lúc '
                      . Carbon::parse($earliestItem->checkin_date)->format('H:i d/m') . '.'
                    : 'Đã hết tất cả khung giờ đặt phòng.';

                return response()->json(['success' => false, 'message' => $message], 422);
            }

            if ($activeItem) {
                $item = $activeItem;
            }
        }

        // Lần đầu → CHECK-IN, đã check-in rồi → CHECK-OUT
        $isCheckin  = is_null($order->checked_in_at);
        $lockId     = $isCheckin ? $product->lock_id : $product->lock_id_checkout;
        $accessCode = $order->accessCodes->first();

        // Chi nhánh TTLock
        if ($lockId) {
            return $this->handleTTLockUnlock($order, $product, $item, $accessCode, (int) $lockId, $isCheckin);
        }

        // Chi nhánh cấp mã thủ công
        if ($accessCode) {
            return response()->json([
                'success'  => false,
                'type'     => 'manual',
                'message'  => 'Vui lòng nhập mã cổng vào khóa.',
                'passcode' => $accessCode->code,
            ]);
        }

        // Chi nhánh không cần checkin
        return response()->json([
            'success' => false,
            'type'    => 'none',
            'message' => 'Chi nhánh này không hỗ trợ mở cổng qua app.',
        ], 422);
    }

    private function handleTTLockUnlock(
        Order       $order,
        Product     $product,
        OrderItem   $item,
        ?AccessCode $accessCode,
        int         $lockId,
        bool        $isCheckin
    ): JsonResponse {
        $ttlock = TTLockService::forCategory($order->category_id);

        if (!$ttlock) {
            return response()->json([
                'success'  => false,
                'type'     => 'manual',
                'message'  => 'Vui lòng nhập mã cổng vào khóa.',
                'passcode' => $accessCode?->code,
            ]);
        }

        $opened = $ttlock->remoteUnlock($lockId);

        Log::info('Remote unlock attempt', [
            'order_id'   => $order->id,
            'order_code' => $order->order_code,
            'lock_id'    => $lockId,
            'is_checkin' => $isCheckin,
            'success'    => $opened,
        ]);

        if ($opened) {
            $checkedInAt = null;
            if ($isCheckin) {
                $checkedInAt = now()->toIso8601String();
                // "nhận phòng" và "bắt đầu ở" xảy ra cùng lúc (không có sự kiện nào tách 2 mốc này) → ghi thẳng order_status = staying.
                $order->update(['checked_in_at' => now(), 'order_status' => 'staying']);
            } else {
                $order->update(['checked_out_at' => now(), 'order_status' => 'checked_out']);
            }

            $this->realtime->broadcastCheckin(
                $order->order_code,
                $isCheckin ? 'checkin' : 'checkout',
                $checkedInAt,
                $order->customer_id ? (int) $order->customer_id : null,
            );

            $this->realtime->broadcastOrderUpdate(
                $order->order_code,
                ['order_status' => $order->order_status, 'payment_status' => $order->payment_status],
                $order->customer_id ? (int) $order->customer_id : null,
            );

            $this->notifyUnlock($order, $product, $item, $accessCode, $isCheckin);

            $msg = $isCheckin
                ? 'Cổng đã được mở. Chào mừng bạn!'
                : 'Cổng checkout đã được mở. Hẹn gặp lại!';

            return response()->json([
                'success' => true,
                'type'    => 'ttlock',
                'message' => $msg,
            ]);
        }

        if ($accessCode?->code) {
            return response()->json([
                'success'  => false,
                'type'     => 'ttlock_fallback',
                'message'  => 'Không thể mở cổng tự động (khóa ngoại tuyến). Vui lòng nhập mã cổng thủ công vào khóa.',
                'passcode' => $accessCode->code,
            ]);
        }

        $this->notifyNoCode($order, $product, $isCheckin);

        return response()->json([
            'success' => false,
            'type'    => 'ttlock_error',
            'message' => 'Không thể mở cổng tự động và chưa có mã dự phòng. Vui lòng liên hệ nhân viên để được hỗ trợ.',
        ], 503);
    }

    // =========================================================
    // TELEGRAM NOTIFICATIONS (format giống LockRecordCallbackController)
    // =========================================================

    private function notifyUnlock(
        Order       $order,
        Product     $product,
        OrderItem   $item,
        ?AccessCode $accessCode,
        bool        $isCheckin
    ): void {
        $prefix = $isCheckin ? 'CHECK-IN' : 'CHECK-OUT';

        $msg = "{$prefix} - <b>{$product->name}</b>\n"
             . now()->format('H:i d/m/Y') . "\n\n"
             . "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
             . "Mã đơn: <code>{$order->order_code}</code>\n";

        if ($isCheckin) {
            if ($item->checkout_date) {
                $msg .= "Checkout: " . Carbon::parse($item->checkout_date)->format('H:i d/m/Y') . "\n";
            }
            if ($item->slot_label ?? null) {
                $msg .= "Khung giờ: {$item->slot_label}\n";
            }
        }

        if ($accessCode?->code) {
            $msg .= "Mã cổng: <code>{$accessCode->code}</code>\n";
        }

        $msg .= "(Mở qua app)";

        try {
            $this->telegram->sendLockMessage(trim($msg));
        } catch (\Exception $e) {
            Log::error('Remote unlock: Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }

    private function notifyNoCode(Order $order, Product $product, bool $isCheckin): void
    {
        $prefix = $isCheckin ? 'CHECK-IN' : 'CHECK-OUT';

        $msg = "⚠️ MỞ CỔNG THẤT BẠI ({$prefix}) - <b>{$product->name}</b>\n"
             . now()->format('H:i d/m/Y') . "\n\n"
             . "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
             . "Mã đơn: <code>{$order->order_code}</code>\n"
             . "Lý do: Gateway offline và không có mã dự phòng.\n"
             . "→ Cần hỗ trợ khách ngay!";

        try {
            $this->telegram->sendLockMessage(trim($msg));
        } catch (\Exception $e) {
            Log::error('Remote unlock: Telegram error notify failed', ['error' => $e->getMessage()]);
        }
    }
}
