<?php

namespace Modules\Product\App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;

class ManualLockPassword extends Model
{
    protected $fillable = [
        'name',
        'gate_password',
        'room_password',
        'category_id',
        'notes',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected $casts = [
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
        'is_active'   => 'boolean',
    ];

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'manual_lock_password_product',
            'manual_lock_password_id',
            'product_id'
        );
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now());
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_active) {
            return 'Ngừng hoạt động';
        }

        if ($this->isExpired()) {
            return 'Đã hết hạn';
        }

        if ($this->valid_until !== null && $this->valid_until->diffInDays(now()) <= 3) {
            return 'Sắp hết hạn';
        }

        return 'Đang hoạt động';
    }

    public function getStatusColorAttribute(): string
    {
        if (! $this->is_active) {
            return 'gray';
        }

        if ($this->isExpired()) {
            return 'danger';
        }

        if ($this->valid_until !== null && Carbon::now()->diffInDays($this->valid_until, false) <= 3) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * Mark this record as inactive (expired).
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }
}
