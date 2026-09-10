<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bảng trung gian Hợp đồng <-> Khách thuê, PROMOTE thành model riêng (không chỉ pivot ẩn) vì mang
// thêm dữ liệu ý nghĩa: `role` (primary = người đứng tên hợp đồng gốc, occupant = người ở cùng) và
// `relationship_to_primary` (quan hệ với người đứng tên). Contract.tenant_id vẫn là nguồn xác định
// "ai đứng tên chính" — bảng này bổ sung để 1 hợp đồng có thể có NHIỀU khách thuê (đứng tên + ở
// cùng), tất cả đều là Tenant thật, đủ hồ sơ (quét CCCD, khai báo lưu trú...).
class ContractTenant extends Model
{
    protected $table = 'minihouse_contract_tenants';

    public const ROLE_PRIMARY  = 'primary';
    public const ROLE_OCCUPANT = 'occupant';

    protected $fillable = ['contract_id', 'tenant_id', 'role', 'relationship_to_primary'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
