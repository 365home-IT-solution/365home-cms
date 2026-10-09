<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Đối tác đồng ý (bật/tắt theo chi nhánh) tham gia chiến dịch ĐỒNG TÀI TRỢ của 365home — chưa đồng ý thì mã
// funded_by = shared không áp lên phòng của chi nhánh đó (Modules\Promotion\App\Models\Coupon::appliesToRoom()).
class CouponPartnerParticipation extends Model
{
    protected $fillable = ['coupon_id', 'partner_id', 'category_id', 'is_enabled', 'decided_by', 'decided_at'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'decided_at' => 'datetime',
    ];
}
