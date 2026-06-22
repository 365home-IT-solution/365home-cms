<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestCustomer extends Model
{
    protected $fillable = [
        'device_token',
        'province_id',
        'customer_id',
        'fullname',
        'phone',
        'cccd_data',
    ];

    protected $casts = [
        'cccd_data' => 'array',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * Tìm theo device_token hoặc tạo mới nếu chưa có.
     * Luôn cập nhật các trường được cung cấp (province_id, fullname, phone, cccd_data).
     */
    public static function findOrCreateByDevice(string $deviceToken, array $attributes = []): static
    {
        $guest = static::where('device_token', $deviceToken)->first();

        if ($guest) {
            $updateData = array_filter(
                $attributes,
                fn ($v) => $v !== null,
            );

            if (! empty($updateData)) {
                $guest->update($updateData);
            }

            return $guest;
        }

        return static::create(array_merge(['device_token' => $deviceToken], $attributes));
    }
}
