<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

class Reminder extends Model
{
    use SoftDeletes;
    use LogsMinihouseActivity;

    // room_id/contract_id ĐỀU nullable (nhắc việc chung, không gắn phòng/hợp đồng nào — vd "Đóng
    // thuế quý") — không dùng chung được ScopedToActiveBuildingViaRoom/ViaContract (sẽ ẩn LUÔN các
    // reminder chung này khỏi tài khoản bị giới hạn, dù chúng không thuộc riêng toà nào để mà lộ).
    // Chỉ ẩn khi reminder THỰC SỰ gắn 1 phòng/hợp đồng thuộc toà nhà ngoài phạm vi được phép.
    protected static function booted(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            $buildingIds = ActiveBuildingScope::activeBuildingIds();

            $builder->where(function (Builder $query) use ($buildingIds) {
                $query->where(fn (Builder $q) => $q->whereNull('room_id')->whereNull('contract_id'))
                    ->orWhereHas('room', fn (Builder $q) => $q->whereIn('building_id', $buildingIds))
                    ->orWhereHas('contract.room', fn (Builder $q) => $q->whereIn('building_id', $buildingIds));
            });
        });
    }

    public const TYPE_PAYMENT     = 'thu_tien';
    public const TYPE_CONTRACT    = 'het_han_hop_dong';
    public const TYPE_MAINTENANCE = 'bao_tri';
    public const TYPE_OTHER       = 'khac';

    protected $table = 'minihouse_reminders';

    protected $fillable = ['title', 'content', 'remind_date', 'type', 'room_id', 'contract_id', 'is_done', 'notified_at'];

    protected $casts = [
        'remind_date' => 'date',
        'is_done'     => 'boolean',
        'notified_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
