<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\App\Models\Product;

class RoomRating extends Model
{
    protected $fillable = [
        'customer_id', 'room_id', 'star', 'comment',
        'admin_reply', 'replied_by', 'replied_at',
    ];

    protected $casts = [
        'star'       => 'integer',
        'replied_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
