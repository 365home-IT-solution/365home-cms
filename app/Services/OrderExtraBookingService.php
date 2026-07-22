<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

/**
 * Cho phép khách (đã login hoặc guest) tự đặt thêm dịch vụ/số khách/khung giờ mới
 * trên đơn ĐÃ THÀNH CÔNG (paid/deposit) và trả về QR thanh toán khoản phát sinh ngay
 * trong response. Tái sử dụng ExtraChargeService (vốn chỉ admin gọi được trước đây)
 * và RoomItemBuilder (check trùng lịch + tính giá, trích từ BookingController).
 */
class OrderExtraBookingService
{
    public function __construct(
        private ExtraChargeService $extraCharge,
        private NotificationFcmService $notifier,
        private OrderRealtimeService $realtime,
        private RoomItemBuilder $roomItemBuilder,
        private SlotRealtimeService $slotRealtime,
    ) {
    }

    /**
     * @param  array{service_id:int, quantity:int}[]  $servicesInput
     * @param  array{type:string, product_id?:int, timeslot_id?:int, date?:string, checkin_date?:string, checkout_date?:string}|null  $roomAddition
     * @return array{error:string}|array<string,mixed>
     */
    public function addExtra(Order $order, array $servicesInput, ?int $guestCount, ?array $roomAddition = null): array
    {
        if ($roomAddition) {
            $roomAddition = $this->normalizeRoomAdditionDates($roomAddition);
        }

        if (! in_array($order->status, ['paid', 'deposit'], true)) {
            return ['error' => 'Đơn hàng chưa thanh toán, không thể đặt thêm.'];
        }

        if ($order->order_status === 'checked_out') {
            return ['error' => 'Đơn hàng đã trả phòng, không thể đặt thêm.'];
        }

        $productId = $order->items->first()?->product_id;
        $room      = $productId
            ? Product::where('id', $productId)->with('additionalServices')->first()
            : null;

        if (! $room) {
            return ['error' => 'Phòng không tồn tại.'];
        }

        $roomAdditionRoom = $room;
        if ($roomAddition && ! empty($roomAddition['product_id']) && (string) $roomAddition['product_id'] !== (string) $room->id) {
            $roomAdditionRoom = Product::where('id', $roomAddition['product_id'])
                ->where('is_activated', true)
                ->with('roomTimeSlots.timeSlot')
                ->first();

            if (! $roomAdditionRoom) {
                return ['error' => 'Phòng muốn đặt thêm không tồn tại hoặc đã ngừng hoạt động.'];
            }
        } elseif ($roomAddition) {
            $roomAdditionRoom->loadMissing('roomTimeSlots.timeSlot');
        }

        if ($roomAddition) {
            $isSlotType = (int) $roomAdditionRoom->styles === 1;
            if (($roomAddition['type'] === 'slot') !== $isSlotType) {
                return ['error' => 'Kiểu đặt thêm (slot/daily) không khớp với loại phòng.'];
            }
        }

        $oldGuestCount    = (int) $order->guest_count;
        $oldServicesTotal = (int) $order->services()->sum('subtotal');

        $addedServices  = [];
        $roomAddedItems = null;
        $roomAddedPrice = 0;
        $roomAddedType  = $roomAddition['type'] ?? null;

        DB::transaction(function () use (
            $order, $room, $roomAdditionRoom, $roomAddition, $servicesInput, $guestCount,
            &$addedServices, &$roomAddedItems, &$roomAddedPrice
        ) {
            if (! empty($servicesInput)) {
                $availableServices = $room->additionalServices->keyBy('id');

                foreach ($servicesInput as $index => $entry) {
                    $serviceId = (int) $entry['service_id'];
                    $quantity  = (int) $entry['quantity'];
                    $service   = $availableServices->get($serviceId);

                    if (! $service || ! $service->is_active) {
                        throw ValidationException::withMessages([
                            "services.{$index}.service_id" => ["Dịch vụ #{$serviceId} không tồn tại hoặc không khả dụng cho phòng này."],
                        ]);
                    }

                    $addedServices[] = [
                        'service_id'   => $service->id,
                        'service_name' => $service->name,
                        'price'        => (int) $service->price,
                        'quantity'     => $quantity,
                        'subtotal'     => (int) $service->price * $quantity,
                    ];
                }

                // Cộng thêm (không xoá service cũ) — khác OrderServiceController::store vốn thay thế toàn bộ.
                foreach ($addedServices as $svc) {
                    $order->services()->create($svc);
                }
            }

            if ($guestCount !== null) {
                $order->update(['guest_count' => $guestCount]);
            }

            if ($roomAddition) {
                // Khoá phòng để tránh race condition với 1 request đặt/đặt-thêm khác cùng lúc.
                Product::where('id', $roomAdditionRoom->id)->lockForUpdate()->first();

                $order->refresh();
                $currentGuestCount = (int) $order->guest_count;

                if ($roomAddition['type'] === 'slot') {
                    [$roomAddedPrice, , $itemsData, , $slotSummary] = $this->roomItemBuilder->buildSlotItems(
                        [$roomAddition],
                        $roomAddition['date'] ?? null,
                        $roomAdditionRoom,
                        $currentGuestCount,
                    );
                    $roomAddedItems = $slotSummary;
                } else {
                    [$roomAddedPrice, , $itemsData, , $nightSummary] = $this->roomItemBuilder->buildDailyItems(
                        $roomAddition['checkin_date'],
                        $roomAddition['checkout_date'],
                        $roomAdditionRoom,
                        $currentGuestCount,
                    );
                    $roomAddedItems = $nightSummary;
                }

                foreach ($itemsData as $itemData) {
                    $order->items()->create($itemData);
                }
            }
        });

        $order->refresh();

        $diff = $this->extraCharge->calculateDiff($order, $oldGuestCount, $oldServicesTotal) + $roomAddedPrice;

        if ($roomAddition) {
            if ($roomAddedType === 'slot') {
                $byDate = collect($roomAddedItems)->groupBy('date');
                foreach ($byDate as $date => $slots) {
                    $this->slotRealtime->broadcastBooked(
                        (string) $roomAdditionRoom->id,
                        $date,
                        $slots->pluck('timeslot_id')->values()->toArray(),
                    );
                }
            } else {
                $this->slotRealtime->broadcastDailyBooked(
                    (string) $roomAdditionRoom->id,
                    $roomAddition['checkin_date'],
                    $roomAddition['checkout_date'],
                );
            }
        }

        $result = [
            'order_code'     => $order->order_code,
            'payment_status' => $order->payment_status,
            'order_status'   => $order->order_status,
            'services_added' => $addedServices,
            'room_added'     => $roomAddedItems ? ['type' => $roomAddedType, 'items' => $roomAddedItems, 'price' => $roomAddedPrice] : null,
            'diff'           => $diff,
            'charge'         => null,
        ];

        if ($diff <= 0) {
            return $result;
        }

        if ($order->status === 'deposit') {
            $this->extraCharge->applyDiffToDeposit($order, $diff);
            $order->refresh();

            $remainingInfo = $this->extraCharge->createRemainingPayOS($order);
            if (isset($remainingInfo['error'])) {
                Log::warning('OrderExtraBookingService: createRemainingPayOS failed', [
                    'order_id' => $order->id,
                    'error'    => $remainingInfo['error'],
                ]);
                $result['charge'] = ['type' => 'deposit_remaining', 'error' => $remainingInfo['error']];
            } else {
                $result['charge'] = $this->toClientCharge('deposit_remaining', $remainingInfo);
            }
        } else {
            $payOSResult = $this->extraCharge->createExtraChargePayOS($order, $diff);
            $result['charge'] = $this->toClientCharge('extra_paid', $payOSResult);
        }

        $this->notifyGuestExtra($order, $diff, $result['charge']);

        return $result;
    }

    /**
     * Client gửi ngày theo dd-mm-yyyy (validate ở controller) — convert sang Y-m-d
     * trước khi đưa vào RoomItemBuilder (vốn dùng Carbon::parse("{$date} {$time}")).
     */
    private function normalizeRoomAdditionDates(array $roomAddition): array
    {
        foreach (['date', 'checkin_date', 'checkout_date'] as $key) {
            if (! empty($roomAddition[$key])) {
                $roomAddition[$key] = \Carbon\Carbon::createFromFormat('d-m-Y', $roomAddition[$key])->format('Y-m-d');
            }
        }

        return $roomAddition;
    }

    /**
     * API app chỉ hiển thị QR ảnh, không cần link web checkout_url (đúng convention của
     * payment-qr/retry-payment/remaining-payment hiện có) — chỉ giữ qr_code/amount/expired_at.
     */
    private function toClientCharge(string $type, array $payOSResult): array
    {
        return [
            'type'       => $type,
            'qr_code'    => $payOSResult['qr_code'] ?? null,
            'amount'     => $payOSResult['amount'] ?? null,
            'expired_at' => $payOSResult['expired_at'] ?? null,
        ];
    }

    private function notifyGuestExtra(Order $order, int $diff, ?array $charge): void
    {
        $title = "Đơn #{$order->order_code}: phát sinh thêm " . number_format($diff, 0, ',', '.') . 'đ';
        $body  = 'Bạn vừa đặt thêm dịch vụ/số khách. Vui lòng thanh toán khoản phát sinh.';

        try {
            if ($order->customer_id) {
                $customer = \App\Models\Customer::find($order->customer_id);
                if ($customer) {
                    $this->notifier->sendToCustomer($customer, $title, $body, 'order_extra_charge', [
                        'order_code' => (string) $order->order_code,
                        'amount'     => $diff,
                    ]);
                }
            } elseif ($order->device_token) {
                $this->notifier->sendToGuestToken($order->device_token, $title, $body, 'order_extra_charge', [
                    'order_code' => (string) $order->order_code,
                    'amount'     => $diff,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('OrderExtraBookingService: notify failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }

        try {
            $this->realtime->broadcastOrderUpdate(
                (string) $order->order_code,
                [
                    'extra_charge' => [
                        'amount'   => $diff,
                        'qr_code'  => $charge['qr_code'] ?? null,
                        'is_paid'  => false,
                    ],
                ],
                $order->customer_id ? (int) $order->customer_id : null,
            );
        } catch (\Throwable $e) {
            Log::warning('OrderExtraBookingService: broadcast failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
