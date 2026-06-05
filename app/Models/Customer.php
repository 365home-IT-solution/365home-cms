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

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'fullname',
        'date_of_birth',
        'phone',
        'phone_verified_at',
        'cccd_front',
        'cccd_back',
        'avatar',
    ];

    protected $hidden = [];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'date_of_birth'     => 'date',
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
