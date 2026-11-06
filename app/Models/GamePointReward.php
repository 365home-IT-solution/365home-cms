<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Quà đổi bằng điểm game: mỗi lần đổi sinh 1 voucher cá nhân dùng chung (partner_id null, 365 HOME chịu).
class GamePointReward extends Model
{
    protected $fillable = [
        'name', 'description', 'points_cost', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days',
        'stock', 'per_customer_limit', 'redeemed_count', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'points_cost'        => 'integer',
        'coupon_value'       => 'decimal:2',
        'min_order_value'    => 'decimal:2',
        'max_discount'       => 'decimal:2',
        'validity_days'      => 'integer',
        'stock'              => 'integer',
        'per_customer_limit' => 'integer',
        'redeemed_count'     => 'integer',
        'is_active'          => 'boolean',
        'sort_order'         => 'integer',
    ];

    // Luật validate dùng chung cho API admin; form Filament có cùng ràng buộc.
    public static function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name'               => "{$req}|string|max:255",
            'description'        => 'nullable|string|max:2000',
            'points_cost'        => "{$req}|integer|min:1|max:10000000",
            'coupon_type'        => "{$req}|in:fixed,percentage",
            'coupon_value'       => "{$req}|numeric|min:1",
            'min_order_value'    => 'nullable|numeric|min:0',
            'max_discount'       => 'nullable|numeric|min:0',
            'validity_days'      => 'sometimes|integer|min:1|max:365',
            'stock'              => 'nullable|integer|min:1',
            'per_customer_limit' => 'nullable|integer|min:1',
            'is_active'          => 'sometimes|boolean',
            'sort_order'         => 'sometimes|integer',
        ];
    }

    public function remaining(): ?int
    {
        return $this->stock === null ? null : max(0, $this->stock - $this->redeemed_count);
    }
}
