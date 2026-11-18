<?php

namespace Modules\VehicleRental\App\Support;

use Illuminate\Support\Facades\Storage;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleRatePlan;
use Modules\VehicleRental\App\Services\VehicleBookingService;

/** Định dạng JSON dùng chung cho API khách và API admin của thuê xe. */
class VehicleTransformer
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::url($path);
    }

    public static function vehicle(Vehicle $v, ?array $quote = null, bool $withPlans = false, ?array $kmQuote = null): array
    {
        $data = [
            'id'             => $v->id,
            'type'           => $v->type,
            'brand'          => $v->brand,
            'model'          => $v->model,
            'year'           => $v->year,
            'plate'          => $v->plate_display,
            'seats'          => $v->seats,
            'transmission'   => $v->transmission,
            'fuel'           => $v->fuel,
            'color'          => $v->color,
            'odometer_km'    => $v->odometer_km,
            'image'          => self::url($v->image),
            'photos'         => collect($v->photos ?? [])->map(fn ($p) => self::url($p))->values()->all(),
            'description'    => $v->description,
            'deposit_amount' => $v->deposit_amount,
            'branch'         => $v->branch ? ['id' => $v->branch->id, 'name' => $v->branch->name] : ['id' => $v->branch_id],
            'pickup_point'   => self::publicPickupPoint($v),
        ];

        if ($quote !== null) {
            $data['quote'] = $quote;
        }
        // Gói TÍNH THEO KM (nếu xe có): khách có thể chọn thuê theo km thay vì theo thời gian.
        $kmPlan = $v->relationLoaded('ratePlans') ? app(\Modules\VehicleRental\App\Services\VehiclePricingService::class)->kmPlan($v->ratePlans) : null;
        if ($kmPlan) {
            $data['km_plan'] = ['price_per_km' => (int) $kmPlan->price, 'min_km' => $kmPlan->min_km ? (int) $kmPlan->min_km : null];
            if ($kmQuote !== null) {
                $data['km_quote'] = self::quote($kmQuote);
            }
        }
        if ($withPlans) {
            $data['rate_plans'] = $v->ratePlans->where('is_active', true)->map(fn (VehicleRatePlan $p) => self::plan($p))->values()->all();
        }

        return $data;
    }

    /**
     * Điểm giao xe hiển thị công khai (tìm xe/chi tiết xe): chỉ địa chỉ + nguồn, KHÔNG kèm toạ độ — toạ độ chính xác chỉ trả cho khách đã đặt xe.
     *
     * @return array{source: string, address: ?string, located_at: ?string}
     */
    public static function publicPickupPoint(Vehicle $v): array
    {
        $p = app(\Modules\VehicleRental\App\Services\VehicleGpsService::class)->pickupPoint($v);

        return ['source' => $p['source'], 'address' => $p['address'], 'located_at' => $p['located_at']];
    }

    /**
     * Điểm giao xe của đơn: đơn chờ thanh toán/đã xác nhận (chưa giao) → vị trí HIỆN TẠI của xe (xe có thể đã di chuyển từ lúc đặt);
     * đã giao/đóng đơn → ảnh chụp lưu lúc đặt xe.
     *
     * @return array<string, mixed>
     */
    public static function pickupPoint(VehicleBooking $b): array
    {
        $live = in_array($b->status, [VehicleBooking::STATUS_PENDING_PAYMENT, VehicleBooking::STATUS_CONFIRMED], true) && $b->vehicle;
        if ($live) {
            $p = app(\Modules\VehicleRental\App\Services\VehicleGpsService::class)->pickupPoint($b->vehicle);
            $p['live'] = true;

            return $p;
        }

        $lat = $b->pickup_latitude;
        $lng = $b->pickup_longitude;

        return [
            'source'     => $b->pickup_source ?? 'branch',
            'address'    => $b->pickup_address,
            'latitude'   => $lat,
            'longitude'  => $lng,
            'located_at' => $b->pickup_located_at?->toIso8601String(),
            'fresh'      => null,
            'maps_url'   => $lat !== null && $lng !== null ? 'https://www.google.com/maps?q=' . number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '') : null,
            'live'       => false,
        ];
    }

    public static function plan(VehicleRatePlan $p): array
    {
        return $p->only([
            'id', 'name', 'unit', 'duration_hours', 'price', 'weekend_price', 'included_km', 'extra_km_price', 'min_km',
            'overtime_grace_minutes', 'overtime_hour_price', 'is_active', 'sort_order',
        ]);
    }

    public static function handover(\Modules\VehicleRental\App\Models\VehicleHandover $h): array
    {
        return [
            'type'        => $h->type,
            'odometer_km' => $h->odometer_km,
            'fuel_level'  => $h->fuel_level,
            'photos'      => collect($h->photos ?? [])->map(fn ($p) => self::url($p))->values()->all(),
            'notes'       => $h->notes,
            'staff_id'    => $h->staff_id,
            'handled_at'  => $h->handled_at?->toIso8601String(),
        ];
    }

    public static function charge(\Modules\VehicleRental\App\Models\VehicleCharge $c): array
    {
        return [
            'id'     => $c->id,
            'type'   => $c->type,
            'label'  => $c->label,
            'amount' => $c->amount,
            'note'   => $c->note,
            'photos' => collect($c->photos ?? [])->map(fn ($p) => self::url($p))->values()->all(),
        ];
    }

    /** Giấy tờ KYC. $withImages=false cho phản hồi của khách (không cần trả lại đường dẫn ảnh của chính họ). */
    public static function document(\Modules\VehicleRental\App\Models\VehicleDocument $d, bool $withImages = true): array
    {
        $data = [
            'customer_id'        => $d->customer_id,
            'customer'           => $d->customer ? ['fullname' => $d->customer->fullname, 'phone' => $d->customer->phone] : null,
            'full_name'          => $d->full_name,
            'id_number'          => $d->id_number,
            'license_number'     => $d->license_number,
            'license_class'      => $d->license_class,
            'license_expires_on' => $d->license_expires_on?->toDateString(),
            'status'             => $d->status,
            'reject_reason'      => $d->reject_reason,
            'reviewed_at'        => $d->reviewed_at?->toIso8601String(),
            'updated_at'         => $d->updated_at?->toIso8601String(),
        ];
        if ($withImages) {
            foreach (\Modules\VehicleRental\App\Models\VehicleDocument::IMAGE_FIELDS as $f) {
                $data[$f] = self::url($d->{$f});
            }
        } else {
            $data['has_images'] = collect(\Modules\VehicleRental\App\Models\VehicleDocument::IMAGE_FIELDS)->mapWithKeys(fn ($f) => [$f => filled($d->{$f})])->all();
        }

        return $data;
    }

    public static function gpsAlert(\Modules\VehicleRental\App\Models\VehicleGpsAlert $a): array
    {
        return [
            'id'              => $a->id,
            'type'            => $a->type,
            'type_label'      => \Modules\VehicleRental\App\Models\VehicleGpsAlert::TYPES[$a->type] ?? $a->type,
            'severity'        => $a->severity,
            'message'         => $a->message,
            'vehicle'         => $a->vehicle ? ['id' => $a->vehicle->id, 'plate' => $a->vehicle->plate_display, 'brand' => $a->vehicle->brand, 'model' => $a->vehicle->model] : ['id' => $a->vehicle_id],
            'booking_code'    => $a->booking?->code,
            'data'            => $a->data,
            'gps_time'        => $a->gps_time?->toIso8601String(),
            'created_at'      => $a->created_at?->toIso8601String(),
            'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $a->acknowledged_by,
        ];
    }

    /** Báo giá gọn trả cho khách (bỏ plan_ids nội bộ). */
    public static function quote(array $quote): array
    {
        $data = [
            'rental_amount'  => $quote['rental_amount'],
            'included_km'    => $quote['included_km'],
            'extra_km_price' => $quote['extra_km_price'],
            'lines'          => $quote['lines'],
        ];
        if (($quote['mode'] ?? null) === 'km') {
            $data += ['mode' => 'km', 'price_per_km' => $quote['price_per_km'], 'min_km' => $quote['min_km'], 'estimated_km' => $quote['estimated_km']];
        }

        return $data;
    }

    public static function booking(VehicleBooking $b, bool $withPayment = false): array
    {
        $service = app(VehicleBookingService::class);
        $data = [
            'code'             => $b->code,
            'status'           => $b->status,
            'vehicle'          => $b->vehicle ? self::vehicle($b->vehicle) : ['id' => $b->vehicle_id],
            'start_at'         => $b->start_at?->toIso8601String(),
            'end_at'           => $b->end_at?->toIso8601String(),
            'actual_start_at'  => $b->actual_start_at?->toIso8601String(),
            'actual_end_at'    => $b->actual_end_at?->toIso8601String(),
            'pickup_branch_id' => $b->pickup_branch_id,
            'pickup_point'     => self::pickupPoint($b),
            'pricing_mode'     => ($b->pricing_snapshot['mode'] ?? 'time'),
            'estimated_km'     => ($b->pricing_snapshot['quote']['estimated_km'] ?? null),
            'price_per_km'     => ($b->pricing_snapshot['quote']['price_per_km'] ?? null),
            'odometer_start_km' => $b->handover(\Modules\VehicleRental\App\Models\VehicleHandover::TYPE_PICKUP)?->odometer_km ?? $b->vehicle?->odometer_km,
            'odometer_end_km'  => $b->handover(\Modules\VehicleRental\App\Models\VehicleHandover::TYPE_RETURN)?->odometer_km,
            'return_branch_id' => $b->return_branch_id,
            'room_order_id'    => $b->room_order_id,
            'room_order_code'  => $b->room_order_id ? $b->roomOrder?->order_code : null,
            'rental_amount'    => $b->rental_amount,
            'discount_amount'  => $b->discount_amount,
            'extra_amount'     => $b->extra_amount,
            'total_amount'     => $b->total_amount,
            'paid_amount'      => $b->paid_amount,
            'deposit_amount'   => $b->deposit_amount,
            'refund_amount'    => $b->refund_amount,
            'refunded_at'      => $b->refunded_at?->toIso8601String(),
            'deposit_collected_amount' => $b->deposit_collected_amount,
            'deposit_deducted_amount'  => $b->deposit_deducted_amount,
            'deposit_refund_amount'    => $b->deposit_refund_amount,
            'extra_due_amount'         => $b->extra_due_amount,
            'km_driven'                => $b->km_driven,
            'settled_at'               => $b->settled_at?->toIso8601String(),
            'hold_expires_at'  => $b->hold_expires_at?->toIso8601String(),
            'paid_at'          => $b->paid_at?->toIso8601String(),
            'cancelled_at'     => $b->cancelled_at?->toIso8601String(),
            'cancel_reason'    => $b->cancelReasonLabel(),
            'can_cancel'       => $service->canCancel($b),
            'refund_percent_if_cancelled_now' => $b->paid_at ? $service->refundPercent($b) : null,
            'created_at'       => $b->created_at?->toIso8601String(),
        ];

        if ($withPayment) {
            $data['payment'] = [
                'checkout_url' => $b->payos_checkout_url,
                'qr_code'      => $b->payos_qr_code,
                'expires_at'   => $b->payos_expires_at?->toIso8601String(),
            ];
        }

        return $data;
    }
}
