<?php

namespace Modules\AccessCode\Entities;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Zns\App\Models\ZnsNotification;

class AccessCode extends Model
{
    use HasFactory, BelongsToPartner, LogsAuditTrail;

    protected $fillable = [
        'partner_id',
        'code',
        'category_id',
        'status',
        'usage_count',
        'used_at',
        'valid_from',
        'valid_until',
        'gate_location',
        'max_uses',
        'notes',
        'ttlock_keyboard_pwd_id',
        'ttlock_keyboard_pwd_id_checkout',
    ];

    protected $attributes = [
        'max_uses' => null,
        'usage_count' => 0,
        'status' => 'active',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'category_id' => 'integer',
        'usage_count' => 'integer',
        'max_uses' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function znsNotifications()
    {
        return $this->hasMany(ZnsNotification::class, 'access_code_id');
    }

    public function orders()
    {
        return $this->belongsToMany(
            Order::class,
            'access_code_order',
            'access_code_id',
            'order_id'
        )->withTimestamps()->withPivot('assigned_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    public function scopeValid($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    public function scopeForBranch($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
            ->orWhere(function ($q) {
                $q->where('valid_until', '<', now());
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Gán mã cho order (GỘP 2 METHODS LẠI)
     */
    public function assignToOrder($orderId)
    {
        // 1. Attach vào pivot table với assigned_at
        $this->orders()->attach($orderId, [
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // 2. Tăng usage_count
        $this->increment('usage_count');
        
        // 3. Update used_at
        $this->update(['used_at' => now()]);
        
        // 4. Nếu đạt max_uses, đánh dấu là disabled
        if (!is_null($this->max_uses) && $this->usage_count >= $this->max_uses) {
            $this->update(['status' => 'disabled']);
        }
    }

    public function isValid()
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        if (!is_null($this->max_uses) && $this->usage_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function canBeUsed()
    {
        return $this->isValid();
    }

    public function markAsExpired()
    {
        $this->update(['status' => 'expired']);
    }

    public function disable($reason = null)
    {
        $this->update([
            'status' => 'disabled',
            'notes' => $reason ? "Disabled: {$reason}" : $this->notes,
        ]);
    }

    public function activate()
    {
        $this->update(['status' => 'active']);
    }

    public function getLatestOrder()
    {
        return $this->orders()->latest('access_code_order.assigned_at')->first();
    }

    public function getActiveOrdersCount()
    {
        return $this->orders()
            ->where('status', 'paid')
            ->whereBetween('created_at', [
                $this->valid_from ?? now()->subDays(30),
                $this->valid_until ?? now()->addDays(30)
            ])
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Static Methods
    |--------------------------------------------------------------------------
    */

    public static function generateCodesForBranch($categoryId, $count = 100, $validDays = 365)
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = [
                'code' => self::generateCode(),
                'category_id' => $categoryId,
                'status' => 'active',
                'max_uses' => null,
                'usage_count' => 0,
                'valid_from' => now(),
                'valid_until' => now()->addDays($validDays),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        self::insert($codes);

        return $codes;
    }

    public static function generateCode($length = 8)
    {
        do {
            $code = strtoupper(\Illuminate\Support\Str::random($length));
        } while (self::where('code', $code)->exists());
        
        return $code;
    }
}