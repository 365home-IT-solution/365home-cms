<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Bảng ghi sổ "hợp đồng này đã cấp mã mở TTLock nào, cho ổ khoá nào" — xem ContractTtlockService (nơi
// DUY NHẤT tạo/sửa/xoá dòng ở đây). Không có CRUD/UI riêng, không ghi nhật ký hoạt động — đây là dữ
// liệu vận hành nội bộ đi kèm vòng đời Hợp đồng, tương tự vai trò của minihouse_warehouse_stock_movements.
class ContractTtlockPasscode extends Model
{
    protected $table = 'minihouse_contract_ttlock_passcodes';

    // is_gate: ổ này là khoá CỔNG của toà nhà (TtlockSetting::gateLockIds()), không phải khoá của phòng.
    protected $fillable = ['contract_id', 'building_id', 'lock_id', 'is_gate', 'keyboard_pwd_id', 'code', 'start_date', 'end_date'];

    protected $casts = [
        'is_gate'    => 'boolean',
        'start_date' => 'datetime',
        'end_date'   => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
