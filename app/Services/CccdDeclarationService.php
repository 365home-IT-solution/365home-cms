<?php

namespace App\Services;

use App\Models\CccdDeclaration;
use Illuminate\Support\Carbon;
use Modules\Payment\Entities\Order;

class CccdDeclarationService
{
    public function upsertFromOrder(Order $order): void
    {
        $cccd = is_array($order->cccd_data) ? $order->cccd_data : [];
        $item = $order->relationLoaded('items') ? $order->items->first() : $order->items()->first();

        $gender    = $cccd['gender'] ?? null;
        $dob       = $cccd['dob'] ?? null;
        $infoParts = array_filter([$gender, $dob, 'Cộng hòa xã hội chủ nghĩa Việt Nam']);
        $info      = $infoParts ? implode(' / ', $infoParts) : null;

        $checkinAt  = $item?->checkin_datetime
            ?? ($item?->checkin_date ? Carbon::parse($item->checkin_date) : null);
        $checkoutAt = $item?->checkout_datetime
            ?? ($item?->checkout_date ? Carbon::parse($item->checkout_date) : null);

        CccdDeclaration::updateOrCreate(
            ['order_id' => $order->id],
            [
                'full_name'         => $cccd['full_name'] ?? null,
                'info'              => $info ?: null,
                'cccd_number'       => $cccd['cccd'] ?? null,
                'checked_in_at'     => $checkinAt,
                'checked_out_at'    => $checkoutAt,
                'room_number'       => $item?->name,
                'reason_for_stay'   => null,
                'current_residence' => null,
                'province'          => null,
                'ward'              => null,
                'address_detail'    => $cccd['address'] ?? null,
            ]
        );
    }
}
