<?php

namespace Modules\Payment\Entities;

use App\Models\CustomerCompanion;
use Illuminate\Database\Eloquent\Model;

class OrderGuestCccd extends Model
{
    protected $fillable = [
        'order_id',
        'guest_index',
        'companion_id',
        'cccd_front',
        'cccd_back',
        'cccd_data',
    ];

    protected $casts = [
        'cccd_data' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // NULL cho khách vãng lai (upload CCCD tay riêng từng đơn) — chỉ khác NULL khi dòng này được
    // sao chép từ 1 CustomerCompanion qua popup "CCCD thành viên" (OrderForm::buildMemberCccdAction()).
    public function companion()
    {
        return $this->belongsTo(CustomerCompanion::class, 'companion_id');
    }
}
