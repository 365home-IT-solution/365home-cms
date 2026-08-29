<?php

namespace Modules\Product\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PriceBoardPriceLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'price_board_id',
        'product_id',
        'old_price',
        'new_price',
        'old_slots',
        'new_slots',
        'changed_by',
        'created_at',
    ];

    protected $casts = [
        'old_slots'  => 'array',
        'new_slots'  => 'array',
        'created_at' => 'datetime',
    ];

    public function priceBoard()
    {
        return $this->belongsTo(PriceBoard::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Tóm tắt "cái gì đổi" của 1 dòng log, dùng hiển thị trong popup "Lịch sử thay đổi giá" —
     *  chỉ liệt kê phần THẬT SỰ khác (giá/đêm và/hoặc từng khung giờ theo tên), bỏ qua phần không đổi. */
    public function summary(): string
    {
        $parts = [];

        if ($this->old_price !== null || $this->new_price !== null) {
            if ((float) $this->old_price !== (float) $this->new_price) {
                $parts[] = 'Giá/đêm: ' . self::money($this->old_price) . ' → ' . self::money($this->new_price);
            }
        }

        $oldSlots = $this->old_slots ?? [];
        $newSlots = $this->new_slots ?? [];

        foreach (array_unique(array_merge(array_keys($oldSlots), array_keys($newSlots))) as $label) {
            $old = $oldSlots[$label] ?? null;
            $new = $newSlots[$label] ?? null;

            if ($old !== $new) {
                $parts[] = "{$label}: " . self::money($old) . ' → ' . self::money($new);
            }
        }

        return $parts ? implode('; ', $parts) : 'Không đổi';
    }

    private static function money(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 0, ',', '.') . 'đ';
    }
}
