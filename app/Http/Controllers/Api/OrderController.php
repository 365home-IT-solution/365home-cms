<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\PromotionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use PayOS\PayOS;

class OrderController extends Controller
{
    // GET /api/orders
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $orders = Order::with(['items.product.media', 'services'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json([
            'data' => $orders->getCollection()->map(fn ($o) => $this->buildListItem($o)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    // GET /api/orders/{order_code}
    public function show(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with([
            'items.product.media',
            'items.product.roomTimeSlots.timeSlot',
            'items.product.roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            'services',
        ])
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        return response()->json($this->buildDetail($order));
    }

    // ─────────────────────────────────────────────

    /**
     * PATCH /api/orders/{order_code}
     * Cập nhật thông tin người mua và/hoặc dịch vụ bổ sung.
     * Chỉ áp dụng khi đơn ở trạng thái pending.
     */
    public function update(Request $request, string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with(['items.product', 'services'])
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Chỉ có thể cập nhật khi đơn đang ở trạng thái pending.'], 422);
        }

        $request->validate([
            'guest_count'             => 'sometimes|integer|min:1|max:50',
            'note_for_admin'          => 'sometimes|nullable|string|max:500',
            'services'                => 'sometimes|array',
            'services.*.service_id'   => 'required_with:services|integer',
            'services.*.quantity'     => 'required_with:services|integer|min:1',
        ]);

        $updates = [];
        foreach (['guest_count', 'note_for_admin'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $request->input($field);
            }
        }

        // Đồng bộ guest_count xuống từng OrderItem + tính lại phụ thu khách nếu có cấu hình
        if ($request->has('guest_count')) {
            $newGuestCount = (int) $request->input('guest_count');
            $order->items()->update(['guest_count' => $newGuestCount]);

            $productId = $order->items->first()?->product_id;
            $guestRoom = $productId ? \Modules\Product\App\Models\Product::find($productId) : null;
            $guestConfig    = $guestRoom?->room_config ?? [];
            $guestFee       = (int) ($guestConfig['extra_guest_fee'] ?? 0);
            $guestThreshold = (int) ($guestConfig['max_free_guests'] ?? 2);

            if ($guestFee > 0) {
                $itemsSum       = (int) $order->items->sum('price');
                $oldServicesSum = (int) $order->services->sum('subtotal');
                $oldSurcharge   = max(0, (int) $order->amount - $itemsSum - $oldServicesSum);
                $nights         = max(1, $order->items->count());
                $newSurcharge   = max(0, $newGuestCount - $guestThreshold) * $guestFee * $nights;

                if ($newSurcharge !== $oldSurcharge) {
                    $newAmtWithSurcharge = max(0, (int) $order->amount - $oldSurcharge + $newSurcharge);
                    $updates['amount']   = $newAmtWithSurcharge;

                    $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
                    if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                        $origFinal    = (int) round((int) $order->full_amount * 100 / $depositPct);
                        $origDiscount = max(0, (int) $order->amount - $origFinal);
                        $updates['full_amount'] = (int) ceil(max(0, $newAmtWithSurcharge - $origDiscount) * $depositPct / 100);
                    } else {
                        $origDiscount = max(0, (int) $order->amount - (int) $order->full_amount);
                        $updates['full_amount'] = max(0, $newAmtWithSurcharge - $origDiscount);
                    }
                }
            }
        }

        // Thay thế toàn bộ services nếu key được gửi lên
        $servicesResult = null;
        if ($request->has('services')) {
            $productId = $order->items->first()?->product_id;
            if (! $productId) {
                return response()->json(['message' => 'Đơn hàng không có phòng.'], 422);
            }

            $room = \Modules\Product\App\Models\Product::where('id', $productId)
                ->with('additionalServices')
                ->first();

            if (! $room) {
                return response()->json(['message' => 'Phòng không tồn tại.'], 404);
            }

            $availableServices = $room->additionalServices->keyBy('id');
            $servicesData      = [];
            $addedTotal        = 0;

            foreach ($request->input('services') as $index => $entry) {
                $serviceId = (int) $entry['service_id'];
                $quantity  = (int) $entry['quantity'];
                $service   = $availableServices->get($serviceId);

                if (! $service || ! $service->is_active) {
                    throw ValidationException::withMessages([
                        "services.{$index}.service_id" => ["Dịch vụ #{$serviceId} không tồn tại hoặc không khả dụng cho phòng này."],
                    ]);
                }

                $subtotal       = $service->price * $quantity;
                $addedTotal    += $subtotal;
                $servicesData[] = [
                    'service_id'   => $service->id,
                    'service_name' => $service->name,
                    'price'        => (int) $service->price,
                    'quantity'     => $quantity,
                    'subtotal'     => (int) $subtotal,
                ];
            }

            // Lưu tổng dịch vụ cũ TRƯỚC khi xoá để tính lại giá phòng (giữ phụ thu, không dùng items->sum('price'))
            $oldServicesTotal = (int) $order->services->sum('subtotal');

            // Xoá cũ, thêm mới
            $order->services()->delete();
            foreach ($servicesData as $svc) {
                $order->services()->create($svc);
            }

            // Tính lại amount / full_amount
            // Nếu guest_count cũng vừa thay đổi thì dùng amount đã cập nhật phụ thu, không dùng DB cũ
            $currentAmountBase = isset($updates['amount']) ? (int) $updates['amount'] : (int) $order->amount;
            $roomBaseAmount    = max(0, $currentAmountBase - $oldServicesTotal);
            $newSubtotal    = $roomBaseAmount + $addedTotal;

            // Xử lý đơn cọc và đơn thường riêng biệt:
            // - Đơn thường: full_amount = finalAmount → discount = amount - full_amount
            // - Đơn cọc:   full_amount = deposit portion → phải reconstruct finalAmount thật trước
            $depositPct = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;
            if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
                $origFinalAmount = (int) round((int) $order->full_amount * 100 / $depositPct);
                $origDiscount    = max(0, (int) $order->amount - $origFinalAmount);
                $newRealFinal    = max(0, $newSubtotal - $origDiscount);
                $newFullAmount   = (int) ceil($newRealFinal * $depositPct / 100);
            } else {
                $origDiscount  = max(0, (int) $order->amount - (int) $order->full_amount);
                $newRealFinal  = max(0, $newSubtotal - $origDiscount);
                $newFullAmount = $newRealFinal;
            }

            $updates['amount']      = $newSubtotal;
            $updates['full_amount'] = $newFullAmount;

            // Link PayOS cũ tạo với giá cũ → vô hiệu hoá nếu giá thay đổi
            if ($newFullAmount !== (int) $order->full_amount && $order->checkout_url) {
                $updates['checkout_url'] = null;
                $updates['expired_at']   = null;
            }

            $servicesResult = $servicesData;
        }

        if (! empty($updates)) {
            $order->update($updates);
            $order->refresh();
        }

        // Tạo lại link PayOS nếu chưa có (giá vừa thay đổi hoặc lần đầu thêm dịch vụ)
        if (
            $order->payment_method === 'PayOS' &&
            ! $order->checkout_url &&
            (int) $order->full_amount >= 2000
        ) {
            $itemName = $order->items->first()?->name ?? 'Đặt phòng';
            $this->buildPayOSLink($order, $itemName);
            $order->refresh();
        }

        // Nếu không cập nhật services, trả về services hiện tại
        $servicesResult ??= $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => (int) $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => (int) $s->subtotal,
        ])->values()->toArray();

        $svcTotal       = array_sum(array_column($servicesResult, 'subtotal'));
        $newSubtotalAmt = (int) $order->amount;
        $newFullAmt     = (int) $order->full_amount;
        $depositPctResp = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;

        if ($depositPctResp !== null && $depositPctResp > 0 && $depositPctResp < 100) {
            // Đơn cọc: full_amount = tiền cọc → reconstruct tổng thật
            $realFinalAmount = (int) round($newFullAmt * 100 / $depositPctResp);
            $discountDisplay = max(0, $newSubtotalAmt - $realFinalAmount);
        } else {
            $realFinalAmount = $newFullAmt;
            $discountDisplay = max(0, $newSubtotalAmt - $newFullAmt);
        }

        return response()->json([
            'order_code'     => $order->order_code,
            'status'         => $order->status,
            'payment_method' => $order->payment_method,
            'guest_count'    => $order->guest_count,
            'note_for_admin' => $order->note_for_admin,
            'pricing' => [
                'room_subtotal'   => max(0, $newSubtotalAmt - $svcTotal),
                'services_total'  => $svcTotal,
                'subtotal'        => $newSubtotalAmt,
                'discount_amount' => $discountDisplay,
                'final_amount'    => $realFinalAmount,
                'deposit_percent' => $depositPctResp,
                'deposit_amount'  => $depositPctResp !== null ? $newFullAmt : null,
                'pay_now'         => $newFullAmt,
            ],
            'checkout_url' => $order->checkout_url,
            'expired_at'   => $order->expired_at,
            'services'     => $servicesResult,
        ]);
    }

    // ─────────────────────────────────────────────

    /**
     * POST /api/orders/{order_code}/retry-payment
     * Tạo lại link PayOS khi link cũ hết hạn hoặc bị huỷ.
     */
    public function retryPayment(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if (! in_array($order->status, ['pending', 'cancelled_payment'])) {
            return response()->json([
                'message' => 'Chỉ có thể tạo lại link khi đơn ở trạng thái pending hoặc đã hết hạn thanh toán.',
            ], 422);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json(['message' => 'Đơn này không thanh toán qua PayOS.'], 422);
        }

        if ((int) $order->full_amount < 2000) {
            return response()->json(['message' => 'Số tiền thanh toán không đủ tối thiểu.'], 422);
        }

        $itemName    = $order->items->first()?->name ?? 'Đặt phòng';
        $checkoutUrl = $this->buildPayOSLink($order, $itemName);

        if (! $checkoutUrl) {
            return response()->json(['message' => 'Không thể tạo link thanh toán. Vui lòng thử lại sau.'], 500);
        }

        $order->update(['status' => 'pending']);
        $order->refresh();

        return response()->json([
            'order_code'   => $order->order_code,
            'status'       => $order->status,
            'checkout_url' => $order->checkout_url,
            'expired_at'   => $order->expired_at,
        ]);
    }

    /**
     * POST /api/orders/{order_code}/remaining-payment
     * Tạo link PayOS để thanh toán phần còn lại sau khi đã đặt cọc.
     */
    public function remainingPayment(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        if ($order->status !== 'deposit') {
            return response()->json(['message' => 'Chỉ áp dụng cho đơn đang ở trạng thái đặt cọc.'], 422);
        }

        // Đã có link còn dùng được → trả về luôn
        if ($order->remaining_checkout_url && $order->remaining_payos_code) {
            $remaining = max(0, (int) ($order->full_amount ?? $order->amount) - (int) $order->amount);
            return response()->json([
                'order_code'   => $order->order_code,
                'checkout_url' => $order->remaining_checkout_url,
                'amount'       => $remaining,
            ]);
        }

        $fullAmount = (int) ($order->full_amount ?? $order->amount);
        $remaining  = $fullAmount - (int) $order->amount;

        if ($remaining < 2000) {
            return response()->json(['message' => 'Số tiền còn lại quá nhỏ hoặc đã thanh toán đủ.'], 422);
        }

        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return response()->json(['message' => 'Cổng thanh toán chưa được cấu hình.'], 500);
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

            if (! $checkoutUrl) {
                return response()->json(['message' => 'Không thể tạo link thanh toán.'], 500);
            }

            $order->update([
                'remaining_payos_code'   => $remainingCode,
                'remaining_checkout_url' => $checkoutUrl,
            ]);

            return response()->json([
                'order_code'   => $order->order_code,
                'checkout_url' => $checkoutUrl,
                'amount'       => $remaining,
                'expired_at'   => $expiredAt->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('remainingPayment API error', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Lỗi khi tạo link thanh toán. Vui lòng thử lại.'], 500);
        }
    }

    /**
     * GET /api/orders/{order_code}/payment-status
     * Kiểm tra trạng thái PayOS và cập nhật đơn hàng.
     */
    public function paymentStatus(string $orderCode): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $order = Order::with('items')
            ->where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        // Không cần gọi PayOS nếu đã xác định rõ
        if (in_array($order->status, ['paid', 'failed', 'cancelled'])) {
            return response()->json([
                'order_code' => $order->order_code,
                'status'     => $order->status,
            ]);
        }

        if ($order->payment_method !== 'PayOS') {
            return response()->json([
                'order_code' => $order->order_code,
                'status'     => $order->status,
            ]);
        }

        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return response()->json(['order_code' => $order->order_code, 'status' => $order->status]);
            }

            // Đơn cọc đang chờ thanh toán còn lại → query remaining_payos_code
            $isRemaining = $order->status === 'deposit' && $order->remaining_payos_code;
            $payosCode   = $isRemaining
                ? (int) $order->remaining_payos_code
                : (int) $order->order_code;

            $payOS    = new PayOS($clientId, $apiKey, $checksumKey);
            $response = $payOS->getPaymentLinkInformation($payosCode);
            $status   = $response['status'] ?? 'PENDING';

            switch ($status) {
                case 'PAID':
                    if ($isRemaining) {
                        $order->update([
                            'status'            => 'paid',
                            'amount'            => $order->full_amount ?? $order->amount,
                            'remaining_paid_at' => now(),
                        ]);
                    } elseif ($order->deposit_percent !== null) {
                        $order->update(['status' => 'deposit', 'checkout_url' => null, 'deposit_paid_at' => now()]);
                    } else {
                        $order->update(['status' => 'paid']);
                    }
                    break;

                case 'CANCELLED':
                    if (! in_array($order->status, ['paid', 'deposit'])) {
                        $order->update(['status' => 'failed']);
                    }
                    break;

                case 'EXPIRED':
                    if (! in_array($order->status, ['paid', 'deposit'])) {
                        $order->update(['status' => 'cancelled_payment', 'checkout_url' => null]);
                    }
                    break;
            }

            $order->refresh();

            return response()->json([
                'order_code'   => $order->order_code,
                'status'       => $order->status,
                'payos_status' => $status,
                'checkout_url' => $order->checkout_url,
                'expired_at'   => $order->expired_at,
            ]);

        } catch (\Throwable $e) {
            Log::error('paymentStatus API error', [
                'order_code' => $orderCode,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'order_code' => $order->order_code,
                'status'     => $order->status,
            ]);
        }
    }

    // ─────────────────────────────────────────────

    private function buildPayOSLink(Order $order, string $itemName): ?string
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                return null;
            }

            $payOS     = new PayOS($clientId, $apiKey, $checksumKey);
            $expiredAt = now()->addMinutes(15);

            // Huỷ link cũ trên PayOS (nếu còn PENDING) trước khi tạo link mới cùng orderCode
            try {
                $payOS->cancelPaymentLink((int) $order->order_code);
            } catch (\Throwable) {}

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $order->order_code,
                'amount'      => (int) $order->full_amount,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [['name' => $itemName, 'quantity' => 1, 'price' => (int) $order->full_amount]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;

            if ($checkoutUrl) {
                $order->update(['checkout_url' => $checkoutUrl, 'expired_at' => $expiredAt]);
            }

            return $checkoutUrl;

        } catch (\Throwable $e) {
            Log::error('buildPayOSLink error', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ─────────────────────────────────────────────

    private function buildListItem(Order $order): array
    {
        $firstItem = $order->items->first();
        $lastItem  = $order->items->last();

        // Tên phòng: ưu tiên lấy từ product, fallback từ phần đầu của item.name
        $roomName = $firstItem?->product?->name
            ?? ($firstItem?->name ? explode(' - ', $firstItem->name, 2)[0] : null);

        return [
            'order_code'   => $order->order_code,
            'created_at'   => $order->created_at->format('Y-m-d H:i:s'),
            'status'       => $order->status,
            'room_id'        => $firstItem?->product?->id,
            'room_slug'      => $firstItem?->product?->slug,
            'room_name'      => $roomName,
            'room_thumbnail' => $this->getRoomThumbnail($firstItem?->product),
            'checkin'      => $firstItem?->checkin_date?->format('Y-m-d H:i'),
            'checkout'     => $lastItem?->checkout_date?->format('Y-m-d H:i'),
            'final_amount' => (int) $order->full_amount,
        ];
    }

    private function buildDetail(Order $order): array
    {
        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        // Map RoomTimeSlot theo start_time để tra timeslot_id
        $rtsMap = collect();
        if ($product) {
            $rtsMap = $product->roomTimeSlots
                ->whereNull('date')
                ->keyBy(fn ($rts) => $rts->timeSlot?->start_time);
        }

        // ── Slots ──
        $slots = $order->items->map(function ($item) use ($rtsMap) {
            $startTime = $item->checkin_date?->format('H:i:s');
            $rts       = $startTime ? $rtsMap->get($startTime) : null;

            // Label nằm sau "RoomName - " trong item.name
            $nameParts = $item->name ? explode(' - ', $item->name, 2) : [];
            $label     = count($nameParts) > 1 ? $nameParts[1] : null;

            return [
                'timeslot_id' => $rts?->timeslot_id,
                'date'        => $item->checkin_date?->format('Y-m-d'),
                'label'       => $label,
                'price'       => (int) $item->price,
            ];
        })->values()->toArray();

        // ── Services ──
        $services = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => $s->subtotal,
        ])->values()->toArray();

        // ── Promotions (recompute từ config phòng hiện tại) ──
        [$promotions, $promotionDiscount] = $this->recomputePromotions($order->items, $rtsMap);

        // ── Summary ──
        $slotsTotal    = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');

        // amount = subtotal (slots + services trước discount)
        // full_amount = sau discount
        $totalDiscount = (int) $order->amount - (int) $order->full_amount;

        // Phần discount không phải promotion: có thể là bulk/full_booking và/hoặc coupon
        $otherDiscount = max(0, $totalDiscount - $promotionDiscount);

        $slotsFinal = max(0, $slotsTotal - $totalDiscount);

        return [
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
            ],
            'room' => [
                'id'        => $product?->id,
                'slug'      => $product?->slug,
                'name'      => $product?->name,
                'thumbnail' => $this->getRoomThumbnail($product),
            ],
            'slots'    => $slots,
            'services' => $services,

            // Recomputed từ config phòng hiện tại
            'promotions' => $promotions,

            // Không lưu khi tạo đơn → không thể phục hồi chi tiết
            'system_discount' => $otherDiscount > 0 ? ['discount_amount' => $otherDiscount] : null,
            'coupon'          => null,

            'summary' => [
                'slots_total'        => $slotsTotal,
                'promotion_discount' => $promotionDiscount,
                'system_discount'    => $otherDiscount,
                'coupon_discount'    => 0,
                'discount_amount'    => $totalDiscount,
                'slots_final'        => $slotsFinal,
                'services_total'     => $servicesTotal,
                'final_amount'       => (int) $order->full_amount,
            ],
        ];
    }

    private function recomputePromotions($items, $rtsMap): array
    {
        $calculator    = new PromotionCalculator();
        $applied       = [];
        $totalDiscount = 0;

        foreach ($items as $item) {
            $startTime = $item->checkin_date?->format('H:i:s');
            $rts       = $startTime ? $rtsMap->get($startTime) : null;
            $date      = $item->checkin_date?->format('Y-m-d');

            if (! $rts || ! $date || ! $rts->timeSlot) {
                continue;
            }

            $result        = $calculator->calculate($rts, $date);
            $totalDiscount += $result['promo_discount'];

            foreach ($result['applied'] as $entry) {
                $found = false;
                foreach ($applied as $i => $a) {
                    if ($a['id'] === $entry['id']) {
                        $applied[$i]['discount_amount'] += $entry['discount_amount'];
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $applied[] = $entry;
                }
            }
        }

        return [$applied, $totalDiscount];
    }

    private function getRoomThumbnail(?\Modules\Product\App\Models\Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $media = $product->getFirstMedia('Ảnh bìa')
              ?? $product->getFirstMedia('Ảnh chính')
              ?? $product->getFirstMedia();

        return $media?->getUrl();
    }
}
