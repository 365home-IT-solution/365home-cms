<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\PromotionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Payment\Entities\Order;

class OrderController extends Controller
{
    // GET /api/orders
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Customer $customer */
        $customer = auth('sanctum')->user();

        $orders = Order::with(['items.product', 'services'])
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
            'room_name'    => $roomName,
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
                'id'   => $product?->id,
                'name' => $product?->name,
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
}
