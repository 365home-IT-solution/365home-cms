<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use PayOS\PayOS;

class BookingController extends Controller
{
    /**
     * POST /api/orders
     *
     * Tạo đơn đặt phòng theo 2 loại:
     *   - slot:    đặt theo khung giờ (cần timeslot_id + date)
     *   - monthly: đặt theo tháng    (cần checkin_date + checkout_date)
     *
     * Nếu có token Sanctum → lấy buyer_name/buyer_phone từ customer.
     * Nếu không có token   → bắt buộc truyền buyer_name + buyer_phone.
     */
    public function store(Request $request): JsonResponse
    {
        // ── 1. Validate đầu vào ──────────────────────────────────────────────
        $baseRules = [
            'type'           => 'required|in:slot,monthly',
            'room_id'        => 'required|string',
            'guest_count'    => 'required|integer|min:1',
            'payment_method' => 'sometimes|in:PayOS,cash',
            'buyer_name'     => 'sometimes|nullable|string|max:255',
            'buyer_phone'    => 'sometimes|nullable|string|max:20',
            'cccd_front'     => 'sometimes|file|image|max:5120',
            'cccd_back'      => 'sometimes|file|image|max:5120',
        ];

        if ($request->input('type') === 'slot') {
            $baseRules['timeslot_id'] = 'required|integer';
            $baseRules['date']        = 'required|date_format:Y-m-d|after_or_equal:today';
        } else {
            $baseRules['checkin_date']  = 'required|date|after_or_equal:today';
            $baseRules['checkout_date'] = 'required|date|after:checkin_date';
        }

        $request->validate($baseRules);

        // ── 2. Xác định thông tin khách ──────────────────────────────────────
        $customer   = auth('sanctum')->user();
        $buyerName  = $customer ? $customer->fullname : $request->buyer_name;
        $buyerPhone = $customer ? $customer->phone    : $request->buyer_phone;

        if (! $customer && (! $buyerName || ! $buyerPhone)) {
            throw ValidationException::withMessages([
                'buyer_name'  => ! $buyerName  ? ['Trường này là bắt buộc khi chưa đăng nhập.'] : [],
                'buyer_phone' => ! $buyerPhone ? ['Trường này là bắt buộc khi chưa đăng nhập.'] : [],
            ]);
        }

        // ── 3. Load phòng ────────────────────────────────────────────────────
        $room = Product::where('id', $request->room_id)
            ->where('is_activated', true)
            ->with([
                'roomType',
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->first();

        if (! $room) {
            return response()->json(['message' => 'Phòng không tồn tại hoặc đã ngừng hoạt động.'], 404);
        }

        // ── 4. Xây dựng dữ liệu đặt phòng theo loại ────────────────────────
        if ($request->type === 'slot') {
            [$amount, $itemName, $itemData] = $this->buildSlotItem($request, $room);
        } else {
            [$amount, $itemName, $itemData] = $this->buildMonthlyItem($request, $room);
        }

        // ── 5. Upload CCCD ───────────────────────────────────────────────────
        $cccdFront = $request->hasFile('cccd_front')
            ? Storage::disk('public')->putFile('cccd/front', $request->file('cccd_front'))
            : null;

        $cccdBack = $request->hasFile('cccd_back')
            ? Storage::disk('public')->putFile('cccd/back', $request->file('cccd_back'))
            : null;

        // ── 6. Lấy category từ phòng ────────────────────────────────────────
        $category      = $room->categories()->first();
        $paymentMethod = $request->input('payment_method', 'PayOS');

        // ── 7. Tạo đơn hàng + item trong transaction ────────────────────────
        $order = DB::transaction(function () use (
            $room, $amount, $buyerName, $buyerPhone,
            $customer, $cccdFront, $cccdBack, $category,
            $itemData, $paymentMethod, $request
        ) {
            $order = Order::create([
                'amount'         => $amount,
                'full_amount'    => $amount,
                'description'    => 'Đặt phòng - ' . $room->name,
                'buyer_name'     => $buyerName,
                'buyer_phone'    => $buyerPhone,
                'payment_method' => $paymentMethod,
                'status'         => 'pending',
                'cccd_front'     => $cccdFront,
                'cccd_back'      => $cccdBack,
                'guest_count'    => $request->guest_count,
                'category_id'    => $category?->id,
                'customer_id'    => $customer?->id,
            ]);

            $order->items()->create($itemData);

            return $order;
        });

        // ── 8. Tạo link PayOS (nếu thanh toán qua PayOS) ────────────────────
        if ($paymentMethod === 'PayOS' && $amount >= 2000) {
            $this->createPayOSLink($order, $itemName);
        }

        $order->refresh();

        return response()->json([
            'success' => true,
            'order'   => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'amount'         => $order->amount,
                'full_amount'    => $order->full_amount,
                'description'    => $order->description,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
                'payment_method' => $order->payment_method,
                'status'         => $order->status,
                'expired_at'     => $order->expired_at,
                'checkout_url'   => $order->checkout_url,
            ],
            'checkout_url' => $order->checkout_url,
        ], 201);
    }

    // ── SLOT: Đặt theo khung giờ ─────────────────────────────────────────────

    private function buildSlotItem(Request $request, Product $room): array
    {
        $timeslotId = (int) $request->timeslot_id;
        $dateStr    = $request->date;

        // Tìm template RoomTimeSlot (date IS NULL) của phòng với timeslot_id tương ứng
        $rts = $room->roomTimeSlots
            ->filter(fn ($s) => is_null($s->date))
            ->where('timeslot_id', $timeslotId)
            ->first();

        if (! $rts || ! $rts->timeSlot) {
            throw ValidationException::withMessages([
                'timeslot_id' => ['Khung giờ không tồn tại cho phòng này.'],
            ]);
        }

        // Kiểm tra slot có bị block không
        if ($rts->isBlockedOn($dateStr)) {
            throw ValidationException::withMessages([
                'date' => ['Khung giờ này đã bị chặn vào ngày bạn chọn.'],
            ]);
        }

        $timeSlot = $rts->timeSlot;
        $checkin  = Carbon::parse("{$dateStr} {$timeSlot->start_time}");
        $checkout = Carbon::parse("{$dateStr} {$timeSlot->end_time}");

        // Qua đêm: end_time <= start_time → checkout sang ngày hôm sau
        if ($checkout->lte($checkin)) {
            $checkout->addDay();
        }

        // Kiểm tra conflict với đơn hàng đã tồn tại
        $conflict = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped']))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'timeslot_id' => ['Khung giờ này đã được đặt rồi.'],
            ]);
        }

        $startLabel = substr($timeSlot->start_time, 0, 5);
        $endLabel   = substr($timeSlot->end_time, 0, 5);
        $isOvernight = (bool) $rts->over_night;
        $itemName   = $room->name . ' - ' . $startLabel . ' - ' . $endLabel . ($isOvernight ? ' (Qua đêm)' : '');
        $price      = (int) $rts->price;

        return [$price, $itemName, [
            'product_id'    => $room->id,
            'name'          => $itemName,
            'price'         => $price,
            'quantity'      => 1,
            'is_shipped'    => true,
            'checkin_date'  => $checkin,
            'checkout_date' => $checkout,
            'extra_fee'     => 0,
            'guest_count'   => $request->guest_count,
        ]];
    }

    // ── MONTHLY: Đặt theo tháng ──────────────────────────────────────────────

    private function buildMonthlyItem(Request $request, Product $room): array
    {
        $checkin  = Carbon::parse($request->checkin_date);
        $checkout = Carbon::parse($request->checkout_date);

        // Kiểm tra conflict
        $conflict = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkout)
            ->where('checkout_date', '>', $checkin)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['pending', 'paid', 'deposit', 'shipped']))
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'checkin_date' => ['Phòng đã được đặt trong khoảng thời gian này.'],
            ]);
        }

        $months   = max(1, (int) $checkin->diffInMonths($checkout));
        $price    = (int) ($room->price * $months);
        $itemName = $room->name . ' - Thuê tháng (' . $months . ' tháng)';

        return [$price, $itemName, [
            'product_id'    => $room->id,
            'name'          => $itemName,
            'price'         => $price,
            'quantity'      => 1,
            'is_shipped'    => true,
            'checkin_date'  => $checkin,
            'checkout_date' => $checkout,
            'extra_fee'     => 0,
            'guest_count'   => $request->guest_count,
        ]];
    }

    // ── Tạo link thanh toán PayOS ─────────────────────────────────────────────

    private function createPayOSLink(Order $order, string $itemName): void
    {
        try {
            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            if (! $clientId || ! $apiKey || ! $checksumKey) {
                Log::warning('PayOS chưa được cấu hình', ['order_id' => $order->id]);
                return;
            }

            $payOS     = new PayOS($clientId, $apiKey, $checksumKey);
            $expiredAt = now()->addMinutes(15);

            $response = $payOS->createPaymentLink([
                'orderCode'   => (int) $order->order_code,
                'amount'      => (int) $order->amount,
                'description' => 'TT don ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $order->order_code,
                'cancelUrl'   => route('payment.cancel') . '?orderCode=' . $order->order_code,
                'buyerName'   => $order->buyer_name ?? '',
                'buyerPhone'  => $order->buyer_phone ?? '',
                'expiredAt'   => $expiredAt->timestamp,
                'items'       => [[
                    'name'     => $itemName,
                    'quantity' => 1,
                    'price'    => (int) $order->amount,
                ]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;

            if ($checkoutUrl) {
                $order->update([
                    'checkout_url' => $checkoutUrl,
                    'expired_at'   => $expiredAt,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('PayOS link creation error', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
            // Không ném exception — đơn hàng đã được tạo, chỉ thiếu link thanh toán
        }
    }
}
