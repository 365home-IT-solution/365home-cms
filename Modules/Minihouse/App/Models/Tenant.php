<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaRoom;

class Tenant extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingViaRoom;
    use LogsMinihouseActivity;

    public const GENDER_MALE   = 'nam';
    public const GENDER_FEMALE = 'nu';
    public const GENDER_OTHER  = 'khac';

    protected $table = 'minihouse_tenants';

    protected $fillable = [
        'fullname', 'phone', 'id_card_number', 'id_card_front', 'id_card_back',
        'date_of_birth', 'gender', 'hometown', 'permanent_address', 'occupation', 'workplace',
        'emergency_contact_name', 'emergency_contact_phone',
        'residence_declared', 'residence_declared_at',
        'room_id', 'note',
    ];

    protected $casts = [
        'date_of_birth'          => 'date',
        'residence_declared'     => 'boolean',
        'residence_declared_at'  => 'date',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    // Hợp đồng đứng tên chính (Contract.tenant_id trỏ thẳng tới khách này) — dùng cho các nghiệp
    // vụ cần biết "đang đứng tên hợp đồng nào" (vd đồng bộ Tenant.room_id).
    public function primaryContracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    // TOÀN BỘ hợp đồng khách này có liên quan — đứng tên chính LẪN ở cùng — dùng cho "Lịch sử
    // thuê" và các báo cáo chung, vì người ở cùng giờ cũng là Khách thuê thật (xem
    // App\Models\ContractTenant).
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class, 'minihouse_contract_tenants')
            ->withPivot(['role', 'relationship_to_primary']);
    }
}
