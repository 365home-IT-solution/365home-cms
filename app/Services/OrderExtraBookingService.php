<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Payment\App\Services\CccdScannerService;
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
     * @param  int|null  $guestCount  SỐ KHÁCH THÊM (cộng dồn vào guest_count hiện có của đơn), không phải tổng số mới
     * @param  array{type:string, product_id?:string, slots?:array, checkin_date?:string, checkout_date?:string}|null  $roomAddition
     * @param  array<int, array{front:\Illuminate\Http\UploadedFile, back:\Illuminate\Http\UploadedFile}>  $guestFiles  key = guest_index
     * @return array{error:string}|array<string,mixed>
     */
    public function addExtra(
        Order $order,
        array $servicesInput,
        ?int $guestCount,
        ?array $roomAddition = null,
        array $guestFiles = [],
    ): array {
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

        // guest_count ở /extra là SỐ KHÁCH THÊM (cộng dồn vào guest_count hiện có của đơn),
        // không phải tổng số khách mới — khớp cách services cũng cộng dồn, không thay thế.
        $oldGuestCount   = (int) $order->guest_count;
        $finalGuestCount = $guestCount !== null ? $oldGuestCount + $guestCount : null;

        // ── Khai báo CCCD người đi cùng nếu phần đặt thêm có khung giờ qua đêm ──
        $isAddingOvernight = $this->resolveIsAddingOvernight($roomAddition, $roomAdditionRoom);
        $guestCccdRows     = [];

        if ($isAddingOvernight && $finalGuestCount !== null) {
            $declaredMax = max(1, (int) $order->guestCccds()->max('guest_index'));

            for ($guestIndex = $declaredMax + 1; $guestIndex <= $finalGuestCount; $guestIndex++) {
                $files = $guestFiles[$guestIndex] ?? null;

                if (empty($files['front']) || empty($files['back'])) {
                    $this->cleanupGuestUploads($guestCccdRows);

                    return ['error' => "Khung giờ qua đêm cần khai báo lưu trú cho khách thứ {$guestIndex} — vui lòng gửi kèm CCCD (mặt trước/sau) của khách này."];
                }

                $frontPath = $files['front']->store('cccd', 'public');
                $backPath  = $files['back']->store('cccd', 'public');

                $tempOrder = new Order(['cccd_front' => $frontPath, 'cccd_back' => $backPath]);
                $guestData = app(CccdScannerService::class)->scanOrder($tempOrder);

                if (! $guestData) {
                    Storage::disk('public')->delete($frontPath);
                    Storage::disk('public')->delete($backPath);
                    $this->cleanupGuestUploads($guestCccdRows);

                    return ['error' => "Không đọc được QR trên ảnh CCCD của khách thứ {$guestIndex}. Vui lòng upload ảnh gốc rõ nét, không chụp lại màn hình."];
                }

                $guestCccdRows[] = [
                    'guest_index' => $guestIndex,
                    'front'       => $frontPath,
                    'back'        => $backPath,
                    'data'        => $guestData,
                ];
            }
        }

        $oldServicesTotal = (int) $order->services()->sum('subtotal');

        $addedServices  = [];
        $roomAddedItems = null;
        $roomAddedPrice = 0;
        $roomAddedType  = $roomAddition['type'] ?? null;

        DB::transaction(function () use (
            $order, $room, $roomAdditionRoom, $roomAddition, $servicesInput, $finalGuestCount, $guestCccdRows,
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

            if ($finalGuestCount !== null) {
                $order->update(['guest_count' => $finalGuestCount]);
            }

            foreach ($guestCccdRows as $row) {
                $order->guestCccds()->create([
                    'guest_index' => $row['guest_index'],
                    'cccd_front'  => $row['front'],
                    'cccd_back'   => $row['back'],
                    'cccd_data'   => $row['data'],
                ]);
            }

            if ($roomAddition) {
                // Khoá phòng để tránh race condition với 1 request đặt/đặt-thêm khác cùng lúc.
                Product::where('id', $roomAdditionRoom->id)->lockForUpdate()->first();

                $order->refresh();
                $currentGuestCount = (int) $order->guest_count;

                if ($roomAddition['type'] === 'slot') {
                    [$roomAddedPrice, , $itemsData, , $slotSummary] = $this->roomItemBuilder->buildSlotItems(
                        $roomAddition['slots'],
                        null,
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
            'order_code'       => $order->order_code,
            'payment_status'   => $order->payment_status,
            'order_status'     => $order->order_status,
            'guest_count'      => $order->guest_count,
            'services_added'   => $addedServices,
            'room_added'       => $roomAddedItems ? ['type' => $roomAddedType, 'items' => $roomAddedItems, 'price' => $roomAddedPrice] : null,
            'guests_declared'  => array_column($guestCccdRows, 'guest_index'),
            'diff'             => $diff,
            'charge'           => null,
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
     * Tạo lại QR PayOS cho khoản phát sinh (extra_charge_amount) đang chờ thanh toán — dùng khi
     * khách đã đặt thêm (qua addExtra ở trên) nhưng chưa kịp thanh toán (VD QR cũ hết hạn sau 15
     * phút vì khách bận). Chỉ áp dụng cho đơn status=paid — đơn deposit dùng remaining-payment
     * (endpoint riêng đã có sẵn) để tiếp tục thanh toán phần còn lại (đã gồm extra_charge_amount).
     *
     * @return array{error:string}|array{order_code:string, charge:array}
     */
    public function regenerateExtraChargeQr(Order $order): array
    {
        if ($order->status !== 'paid') {
            return ['error' => 'Chức năng này chỉ áp dụng cho đơn đã thanh toán đủ. Đơn đang đặt cọc vui lòng dùng API thanh toán phần còn lại.'];
        }

        if ((int) ($order->extra_charge_amount ?? 0) <= 0 || $order->extra_charge_paid_at !== null) {
            return ['error' => 'Không có khoản phát sinh nào đang chờ thanh toán.'];
        }

        $payOSResult = $this->extraCharge->createExtraChargePayOS($order, (int) $order->extra_charge_amount);

        return [
            'order_code' => $order->order_code,
            'charge'     => $this->toClientCharge('extra_paid', $payOSResult),
        ];
    }

    /**
     * type=daily luôn qua đêm (khớp buildDailyItems hardcode over_night=true). type=slot: chỉ cần
     * 1 khung bất kỳ trong slots[] có over_night=true là toàn bộ lần đặt thêm coi là qua đêm.
     */
    private function resolveIsAddingOvernight(?array $roomAddition, Product $roomAdditionRoom): bool
    {
        if (! $roomAddition) {
            return false;
        }

        if ($roomAddition['type'] === 'daily') {
            return true;
        }

        $rtsByTimeslot = $roomAdditionRoom->roomTimeSlots
            ->filter(fn ($s) => is_null($s->date))
            ->keyBy('timeslot_id');

        foreach ($roomAddition['slots'] ?? [] as $slot) {
            $rts = $rtsByTimeslot->get((int) ($slot['timeslot_id'] ?? 0));
            if ($rts && (bool) $rts->over_night) {
                return true;
            }
        }

        return false;
    }

    private function cleanupGuestUploads(array $rows): void
    {
        foreach ($rows as $row) {
            Storage::disk('public')->delete($row['front']);
            Storage::disk('public')->delete($row['back']);
        }
    }

    /**
     * Client gửi ngày theo dd-mm-yyyy (validate ở controller) — convert sang Y-m-d
     * trước khi đưa vào RoomItemBuilder (vốn dùng Carbon::parse("{$date} {$time}")).
     */
    private function normalizeRoomAdditionDates(array $roomAddition): array
    {
        foreach (['checkin_date', 'checkout_date'] as $key) {
            if (! empty($roomAddition[$key])) {
                $roomAddition[$key] = \Carbon\Carbon::createFromFormat('d-m-Y', $roomAddition[$key])->format('Y-m-d');
            }
        }

        if (! empty($roomAddition['slots']) && is_array($roomAddition['slots'])) {
            foreach ($roomAddition['slots'] as $i => $slot) {
                if (! empty($slot['date'])) {
                    $roomAddition['slots'][$i]['date'] = \Carbon\Carbon::createFromFormat('d-m-Y', $slot['date'])->format('Y-m-d');
                }
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
