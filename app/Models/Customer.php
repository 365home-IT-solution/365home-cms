<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Payment\Entities\Order;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'fullname',
        'date_of_birth',
        'phone',
        'phone_verified_at',
        'status',
        'password',
        'password_updated_at',
        'cccd_front',
        'cccd_back',
        'cccd_data',
        'avatar',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'phone_verified_at'  => 'datetime',
        'date_of_birth'      => 'date',
        'password'           => 'hashed',
        'password_updated_at'=> 'datetime',
        'cccd_data'          => 'array',
    ];

    // Filament gọi $user->name — map về fullname để tránh TypeError
    public function getNameAttribute(): string
    {
        return $this->fullname ?? '';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('status') && $model->status === self::STATUS_INACTIVE) {
                $model->tokens()->delete();
            }
        });
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
}
