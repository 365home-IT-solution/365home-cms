<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

class Room extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingId;
    use LogsMinihouseActivity;

    public const STATUS_EMPTY    = 'trong';
    public const STATUS_RESERVED = 'dat_coc';
    public const STATUS_RENTED   = 'dang_thue';
    public const STATUS_REPAIR   = 'bao_tri';

    protected $table = 'minihouse_rooms';

    protected $fillable = ['building_id', 'code', 'floor', 'position_row', 'position_col', 'area', 'price', 'status', 'note', 'photos'];

    // area/price là cột decimal ở CSDL nhưng KHÔNG cần ép cố định số lẻ như 'decimal:2' — cast đó
    // luôn trả về chuỗi có đủ N số thập phân (VD "2500000.00"), hiện ra ",00" giả dù giá trị thật
    // là số nguyên. 'float' giữ đúng số lẻ THẬT (nếu area = 20.5 vẫn hiện 20.5), số nguyên thì hiện
    // gọn "20"/"2500000" như bình thường.
    protected $casts = [
        'photos' => 'array',
        'area'   => 'float',
        'price'  => 'float',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'minihouse_room_amenity');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(RoomAsset::class);
    }
}
