<?php

namespace Modules\BladeThemeV1\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class AdditionService extends Model
{
    use HasFactory, BelongsToPartner, LogsAuditTrail;

    protected $table = 'additional_services';

    protected $fillable = [
        'partner_id',
        'name',
        'price',
        'image',
        'is_active',
    ];

    protected $casts = [
        'price'     => 'integer',
        'is_active' => 'boolean',
    ];

    /** Chi nhánh (category gốc) áp dụng dịch vụ này — nguồn gốc thật; products() bên dưới chỉ là
     *  dữ liệu dẫn xuất do App\Services\BranchServiceSync ghi lại từ đây. */
    public function branches()
    {
        return $this->belongsToMany(
            \Modules\Category\Entities\Category::class,
            'additional_service_branches',
            'additional_service_id',
            'category_id'
        );
    }

    public function products()
    {
        return $this->belongsToMany(
            \Modules\Product\App\Models\Product::class,
            'room_additional_service_assigns',
            'additional_service_id',
            'room_id'
        );
    }
}