<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OrderExtraBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Services\AccessCode\AccessCodeService;
use Modules\Payment\Entities\Order;
use PayOS\PayOS;

/**
 * Toàn bộ tính toán "số tiền còn lại" trong controller này là bản RIÊNG của admin, KHÔNG gọi
 * ExtraChargeService::createRemainingPayOS() (đang dùng chung cho khách hàng qua
 * OrderExtraBookingService — xem OrderController/GuestBookingController::addExtra()). Tách hẳn để:
 *   1. Sửa/tối ưu phía admin không bao giờ vô tình đổi hành vi phía khách hàng (và ngược lại).
 *   2. Đảm bảo admin LUÔN dùng đúng công thức: full_amount là tổng giá CỐ ĐỊNH, số cọc đã thu suy
 *      ra qua Order::depositDueAmount() (full_amount * deposit_percent / 100) — KHÔNG suy ngược
 *      tổng thật từ full_amount như code cũ phía khách hàng.
 */
class OrderPaymentController extends Controller
{
    /**
     * POST /api/admin/orders/{order_code}/retry-payment
     * Admin tạo lại QR PayOS cho đơn thanh toán THẤT BẠI/ĐÃ HUỶ (status "failed" hoặc
     * "cancelled_payment") để gửi khách thanh toán lại — đơn tự động chuyển về "pending" ngay khi
     * tạo QR mới thành công. Khi khách quét & trả tiền, webhook thanh toán chung tự động chuyển
     * đơn sang "paid"/"deposit" như bình thường (không cần xử lý gì thêm ở đây).
     *
     * Huỷ link PayOS cũ (nếu có current_payos_code) trước khi tạo link mới với 1 orderCode PayOS
     * MỚI (không dùng lại order_code cũ) — PayOS không cho tạo trùng orderCode chưa được huỷ/hết
     * hạn, đơn failed/cancelled gần như chắc chắn đã có 1 link cũ từ lần thử trước. Tự tính đúng số
     * tiền cần thu qua depositDueAmount() (đơn cọc chỉ thu lại đúng % cọc, không phải full_amount).
     */
    public function retryPayment(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::with('items')->where('order_code', $orderCode)->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        if (! in_array($order->status, ['failed', 'cancelled_payment'], true)) {
            return response()->json(['message' => 'Chỉ áp dụng cho đơn thanh toán thất bại hoặc đã huỷ.'], 422);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json(['message' => 'Đơn này không thanh toán qua PayOS.'], 422);
        }

        $dueNow = $order->depositDueAmount();

        if ($dueNow < 2000) {
            return response()->json(['message' => 'Số tiền thanh toán không đủ tối thiểu.'], 422);
        }

        $clientId    = Config::get('payos.client_id');
        $apiKey      = Config::get('payos.api_key');
        $checksumKey = Config::get('payos.checksum_key');

        if (! $clientId || ! $apiKey || ! $checksumKey) {
            return response()->json(['message' => 'Cổng thanh toán chưa được cấu hình.'], 500);
        }

        $payOS = new PayOS($clientId, $apiKey, $checksumKey);

        $oldPayosCode = $order->current_payos_code ?? (int) $order->order_code;
        try {
            $payOS->cancelPaymentLink((int) $oldPayosCode);
        } catch (\Throwable $e) {
            Log::info('Admin API retryPayment: cancel old PayOS link skipped', [
                'order_code' => $order->order_code,
                'reason'     => $e->getMessage(),
            ]);
        }

        $itemName     = $order->items->first()?->name ?? 'Đặt phòng';
        $newPayosCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
        $expiredAt    = now()->addMinutes(15);

        try {
            $response = $payOS->createPaymentLink([
                'orderCode'   => $newPayosCode,
                'amount'      => $dueNow,
                'description' => 'TT lai don ' . $order->order_code,
                'returnUrl'   => $request->input('return_url') ?? route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => $request->input('cancel_url') ?? route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => $dueNow]],
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin API retryPayment: createPaymentLink failed', [
                'order_code' => $order->order_code,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Không thể tạo lại link thanh toán. Vui lòng thử lại sau.'], 500);
        }

        $checkoutUrl = $response['checkoutUrl'] ?? null;
        $qrCode      = $response['qrCode'] ?? null;

        if (! $checkoutUrl) {
            return response()->json(['message' => 'Không thể tạo lại link thanh toán. Vui lòng thử lại sau.'], 500);
        }

        $order->update([
            'status'             => 'pending',
            'checkout_url'       => $checkoutUrl,
            'qr_code'            => $qrCode,
            'expired_at'         => $expiredAt,
            'current_payos_code' => $newPayosCode,
        ]);
        $order->refresh();

        return response()->json([
            'order_code' => $order->order_code,
            'status'     => $order->status,
            'qr_code'    => $order->qr_code,
            'expired_at' => $order->expired_at,
        ]);
    }

    /**
     * POST /api/admin/orders/{order_code}/remaining-payment
     * Admin tạo QR thanh toán phần còn lại cho đơn đặt cọc — dùng khi khách đến thanh toán số tiền
     * còn lại (không đặt thêm gì cả, chỉ tất toán phần cọc ban đầu). Khác với POST .../extra (bên
     * dưới) — chỗ đó dành cho khi khách ĐẶT THÊM dịch vụ/khách trước.
     *
     * Áp dụng cho đơn đang "deposit" LẪN "pending" — đơn PayOS mới tạo luôn ở "pending" (kể cả đơn
     * đặt cọc) và CHỈ tự chuyển "deposit" khi PayOS xác nhận thanh toán qua webhook thật (xem
     * GuestBookingController::paymentStatus()); "pending" không phải lỗi, chỉ là "chưa ai trả tiền
     * qua QR ban đầu". depositDueAmount()/full_amount đã đủ dữ kiện để tính đúng số còn lại ngay cả
     * khi status vẫn "pending", nên không cần bước xác nhận trung gian nào khác — gọi thẳng
     * endpoint này là đủ cho cả 2 trường hợp.
     *
     * Body:
     *  - payment_method : "PayOS" (mặc định, trả QR để khách chuyển khoản) | "cod" (khách trả tiền
     *    mặt tại quầy — admin xác nhận luôn, không tạo QR, đơn chuyển thẳng sang "paid").
     */
    public function remainingPayment(Request $request, string $orderCode): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $request->validate([
            'payment_method' => 'sometimes|in:PayOS,cod',
        ]);

        $order = Order::with('items.product')->where('order_code', $orderCode)->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        if (! in_array($order->status, ['pending', 'deposit'], true)) {
            return response()->json(['message' => 'Chỉ áp dụng cho đơn đang đặt cọc hoặc chờ thanh toán ban đầu.'], 422);
        }

        if ($order->deposit_percent === null) {
            return response()->json(['message' => 'Đơn này không phải đơn đặt cọc.'], 422);
        }

        if ($request->input('payment_method', 'PayOS') === 'cod') {
            $remaining = $this->calcRemaining($order);

            $order->update([
                'status'                   => 'paid',
                'amount'                   => (int) $order->full_amount + (int) ($order->extra_charge_amount ?? 0),
                'remaining_paid_at'        => now(),
                'remaining_payment_method' => 'cod',
            ]);

            // Đơn cọc KHÔNG được gán mã cổng lúc mới cọc (xem PaymentController::handlePaidStatus())
            // — chỉ gán khi thanh toán phần còn lại xong (status vừa chuyển "paid" ở trên). Với QR
            // PayOS (nhánh bên dưới), việc gán mã đã được webhook thanh toán chung xử lý tự động khi
            // khách quét & trả tiền — CHỈ nhánh tiền mặt ở đây mới cần tự gọi tay vì không qua webhook.
            // assignCodeToOrder() tự quyết định TTLock hay mã thủ công theo đúng cấu hình chi nhánh —
            // không gán gì cả nếu chi nhánh chưa đăng ký TTLock lẫn không có mã thủ công khả dụng.
            try {
                if (! $order->hasAccessCode()) {
                    $firstItem    = $order->items->sortBy('checkin_date')->first();
                    $checkinDate  = $order->items->min('checkin_date');
                    $checkoutDate = $order->items->max('checkout_date');
                    $product      = $firstItem?->product;

                    app(AccessCodeService::class)
                        ->assignCodeToOrder($order->id, $order->category_id, $checkinDate, $checkoutDate, $product);
                }
            } catch (\Throwable $codeErr) {
                Log::warning('Admin API: gán mã cổng sau khi xác nhận tiền mặt phần còn lại thất bại', [
                    'order_code' => $order->order_code,
                    'error'      => $codeErr->getMessage(),
                ]);
            }

            return response()->json([
                'message'     => 'Đã xác nhận thu tiền mặt phần còn lại.',
                'order_code'  => $order->order_code,
                'amount'      => $remaining,
                'status'      => $order->status,
                'access_code' => $order->refresh()->accessCode?->code,
            ]);
        }

        $result = $this->createRemainingPayOSForAdmin($order);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'order_code' => $order->order_code,
            'qr_code'    => $result['qr_code'],
            'amount'     => $result['amount'],
            'expired_at' => $result['expired_at'],
        ]);
    }

    /**
     * POST /api/admin/orders/{order_code}/extra
     * Admin đặt thêm dịch vụ/tăng số khách đi cùng/thêm khung giờ-ngày hộ khách trên đơn ĐÃ
     * thanh toán (paid/deposit) — áp dụng cho CẢ đơn theo khung giờ (slot) lẫn theo ngày (daily).
     *
     * Tái sử dụng cơ chế "đặt thêm" (services/room-addition/CCCD/giảm giá) của
     * OrderExtraBookingService — phần đó KHÔNG có bug, khớp 100% cách tính giá lúc đặt đơn mới.
     * Riêng QR "số tiền còn lại" cho đơn deposit thì KHÔNG dùng QR service đó trả về (nó gọi
     * ExtraChargeService::createRemainingPayOS() dùng chung với khách hàng) — admin tự tính lại
     * và tạo QR riêng qua createRemainingPayOSForAdmin() để đảm bảo đúng số tiền, tách biệt hoàn
     * toàn khỏi phía khách hàng.
     *
     * Trả kèm 'charge' ngay trong response — CHÍNH LÀ mã QR cần thanh toán:
     *  - Đơn đang 'deposit': charge.type = "deposit_remaining" — QR cho TOÀN BỘ phần còn lại
     *    (đã cộng dồn thêm phần vừa đặt thêm, tính bởi admin — xem ghi chú trên).
     *  - Đơn đang 'paid': charge.type = "extra_paid" — QR riêng cho đúng phần chênh lệch vừa đặt
     *    thêm (tạo bởi ExtraChargeService::createExtraChargePayOS(), không dính bug nêu trên).
     *  - Không phát sinh thêm tiền (vd chỉ giảm bớt) -> charge = null.
     *
     * Body (multipart/form-data nếu có CCCD khách đi cùng cho phần đặt thêm qua đêm):
     *  - services[]              : [{service_id, quantity}] — CỘNG DỒN thêm, không thay thế dịch vụ cũ
     *  - guest_count              : SỐ KHÁCH THÊM (cộng dồn vào guest_count hiện có), không phải tổng mới
     *  - room_addition[type]      : slot|daily
     *  - room_addition[product_id]: tuỳ chọn, mặc định dùng lại đúng phòng hiện tại của đơn
     *  - room_addition[slots][]   : bắt buộc nếu type=slot — [{timeslot_id, date (d-m-Y)}]
     *  - room_addition[checkin_date]/[checkout_date] : bắt buộc nếu type=daily (d-m-Y)
     *  - guests[{guest_index}][front|back] : CCCD khách đi cùng nếu phần đặt thêm qua đêm
     */
    public function addExtra(Request $request, string $orderCode, OrderExtraBookingService $service): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $request->validate([
            'services'                           => 'sometimes|array',
            'services.*.service_id'              => 'required_with:services|integer',
            'services.*.quantity'                => 'required_with:services|integer|min:1',
            'guest_count'                         => 'sometimes|integer|min:1|max:50',
            'room_addition'                       => 'sometimes|array',
            'room_addition.type'                  => 'required_with:room_addition|in:slot,daily',
            'room_addition.product_id'            => 'sometimes|string',
            'room_addition.slots'                 => 'required_if:room_addition.type,slot|array|min:1',
            'room_addition.slots.*.timeslot_id'   => 'required_with:room_addition.slots|integer',
            'room_addition.slots.*.date'          => 'required_with:room_addition.slots|date_format:d-m-Y|after_or_equal:today',
            'room_addition.checkin_date'          => 'required_if:room_addition.type,daily|date_format:d-m-Y|after_or_equal:today',
            'room_addition.checkout_date'         => 'required_if:room_addition.type,daily|date_format:d-m-Y|after:room_addition.checkin_date',
            'guests'                              => 'sometimes|array',
            'guests.*.front'                      => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guests.*.back'                       => 'sometimes|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $order = Order::with(['items.product.additionalServices', 'services'])
            ->where('order_code', $orderCode)
            ->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $guestFiles = [];
        foreach ((array) $request->file('guests', []) as $guestIndex => $files) {
            $guestFiles[(int) $guestIndex] = [
                'front' => $files['front'] ?? null,
                'back'  => $files['back']  ?? null,
            ];
        }

        $result = $service->addExtra(
            $order,
            $request->input('services', []),
            $request->has('guest_count') ? (int) $request->input('guest_count') : null,
            $request->input('room_addition'),
            $guestFiles,
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        // Ghi đè QR "phần còn lại" của đơn deposit bằng bản admin tự tính (xem docblock) — bỏ qua
        // charge mà OrderExtraBookingService vừa trả (tính qua service dùng chung với khách hàng).
        if (($result['charge']['type'] ?? null) === 'deposit_remaining') {
            $order->refresh();
            $adminCharge = $this->createRemainingPayOSForAdmin($order);

            $result['charge'] = isset($adminCharge['error'])
                ? ['type' => 'deposit_remaining', 'error' => $adminCharge['error']]
                : [
                    'type'       => 'deposit_remaining',
                    'qr_code'    => $adminCharge['qr_code'],
                    'amount'     => $adminCharge['amount'],
                    'expired_at' => $adminCharge['expired_at'],
                ];
        }

        return response()->json($result);
    }

    /**
     * POST /api/admin/orders/{order_code}/extra-charge-qr
     * Tạo lại QR cho khoản phát sinh (extra_charge_amount) đang chờ thanh toán trên đơn ĐÃ 'paid'
     * — dùng khi QR cũ tạo từ .../extra đã hết hạn (15 phút) mà khách chưa kịp thanh toán. Đơn
     * đang 'deposit' dùng lại .../remaining-payment ở trên (đã gồm cả phần phát sinh).
     *
     * Nhánh 'paid' dùng ExtraChargeService::createExtraChargePayOS() — không dính bug nêu ở
     * docblock class (bug chỉ nằm ở createRemainingPayOS(), riêng cho đơn deposit).
     */
    public function extraChargeQr(Request $request, string $orderCode, OrderExtraBookingService $service): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $order = Order::where('order_code', $orderCode)->first();

        if (! $order || (! $admin->isSuperAdmin() && $order->partner_id !== $admin->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $result = $service->regenerateExtraChargeQr($order);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result);
    }

    // ── Riêng của admin — KHÔNG chia sẻ với ExtraChargeService/khách hàng ─────────────────────

    private function calcRemaining(Order $order): int
    {
        $depositPaid = $order->depositDueAmount();
        $extraCharge = (int) ($order->extra_charge_amount ?? 0);

        return max(0, ((int) $order->full_amount - $depositPaid) + $extraCharge);
    }

    /**
     * Luôn tạo QR MỚI (không tái dùng remaining_checkout_url cũ) — link cũ có thể đã được tạo bởi
     * luồng khách hàng (công thức khác, có thể lệch số tiền), nên không đáng tin để trả lại nguyên
     * cho admin. Ghi đè thẳng vào cùng cột remaining_* trên Order — đây là điểm DUY NHẤT còn dùng
     * chung dữ liệu với khách hàng (cùng 1 dòng đơn), nhưng không dùng chung CODE tính toán.
     */
    private function createRemainingPayOSForAdmin(Order $order): array
    {
        $remaining = $this->calcRemaining($order);

        if ($remaining < 2000) {
            return ['error' => 'Số tiền còn lại quá nhỏ hoặc đã thanh toán đủ.'];
        }

        $clientId    = Config::get('payos.client_id');
        $apiKey      = Config::get('payos.api_key');
        $checksumKey = Config::get('payos.checksum_key');

        if (! $clientId || ! $apiKey || ! $checksumKey) {
            return ['error' => 'Cổng thanh toán chưa được cấu hình.'];
        }

        $payOS         = new PayOS($clientId, $apiKey, $checksumKey);
        $remainingCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
        $expiredAt     = now()->addMinutes(30);

        $response = $payOS->createPaymentLink([
            'orderCode'   => $remainingCode,
            'amount'      => $remaining,
            'description' => 'Tt con lai - ' . $order->order_code,
            'returnUrl'   => config('app.url') . '/payment/success?orderCode=' . $order->order_code . '&remaining=1',
            'cancelUrl'   => config('app.url') . '/payment/cancel?orderCode=' . $order->order_code,
            'buyerName'   => $order->buyer_name ?? '',
            'buyerPhone'  => $order->buyer_phone ?? '',
            'expiredAt'   => $expiredAt->timestamp,
            'items'       => [[
                'name'     => 'Tiền còn lại - ' . ($order->items->first()?->name ?? 'Phòng'),
                'quantity' => 1,
                'price'    => $remaining,
            ]],
        ]);

        $checkoutUrl = $response['checkoutUrl'] ?? null;
        $qrCode      = $response['qrCode'] ?? null;

        if (! $checkoutUrl) {
            return ['error' => 'Không thể tạo link thanh toán.'];
        }

        $order->update([
            'remaining_payos_code'   => $remainingCode,
            'remaining_checkout_url' => $checkoutUrl,
            'remaining_qr_code'      => $qrCode,
        ]);

        return [
            'qr_code'    => $qrCode,
            'amount'     => $remaining,
            'expired_at' => $expiredAt->toIso8601String(),
        ];
    }
}
