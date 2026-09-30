<?php

namespace Modules\Book\App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Models\Product;

class OverrideRoomPrice extends Model
{

    protected $fillable = [
        'room_id',
        'date',
        'checkin',
        'checkout',
        'price_override',
    ];

    protected $casts = [
        'date'           => 'date:Y-m-d',
        'price_override' => 'integer',
    ];

    public function room()
    {
        // Lịch sử/tham chiếu tới phòng vẫn phải đọc được khi phòng mất chi nhánh (scope has_branch chỉ để ẩn khỏi danh sách).
        return $this->belongsTo(Product::class, 'room_id')->withoutGlobalScope('has_branch');
    }
}
