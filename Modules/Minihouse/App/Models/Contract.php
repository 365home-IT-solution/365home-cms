<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaRoom;

class Contract extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingViaRoom;
    use LogsMinihouseActivity;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'minihouse_contracts';

    protected $fillable = [
        'room_id', 'tenant_id', 'start_date', 'end_date', 'monthly_price', 'deposit_amount', 'status',
        'reason_for_stay', 'custom_reason',
        'electric_unit_price', 'water_unit_price',
        'contract_content', 'contract_file', 'handover_file', 'deposit_receipt_file',
        'checkout_at', 'deposit_refunded_amount', 'deposit_deduction_reason', 'checkout_handover_file',
        'transferred_to_contract_id', 'transferred_from_contract_id',
    ];

    // float cho các cột tiền — tránh 'decimal:2' luôn ép hiện đủ 2 số lẻ (VD "3000000.00") dù giá
    // trị là số nguyên (VNĐ không có phần lẻ trong nghiệp vụ này).
    protected $casts = [
        'start_date'               => 'date',
        'end_date'                 => 'date',
        'checkout_at'              => 'date',
        'monthly_price'            => 'float',
        'deposit_amount'           => 'float',
        'deposit_refunded_amount'  => 'float',
        'electric_unit_price'      => 'float',
        'water_unit_price'         => 'float',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function contractTenants(): HasMany
    {
        return $this->hasMany(ContractTenant::class);
    }

    // Người ở cùng (khác người đứng tên chính) — quản lý trên trang Hợp đồng qua Repeater gắn với
    // contractTenants(), chỉ chọn/tạo Tenant thật, không nhập tay lại hồ sơ riêng.
    public function occupantEntries(): HasMany
    {
        return $this->contractTenants()->where('role', ContractTenant::ROLE_OCCUPANT);
    }

    // Toàn bộ Khách thuê liên quan tới hợp đồng này (đứng tên chính + ở cùng) — dùng cho báo cáo/
    // đếm số người ở, không dùng để sửa (sửa qua tenant_id/occupantEntries riêng).
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'minihouse_contract_tenants')
            ->withPivot(['role', 'relationship_to_primary']);
    }

    // Phụ thu ĐỊNH KỲ hợp đồng này phải trả hàng tháng (VD: có gửi xe, không dùng internet) — chọn
    // từ danh mục Phụ thu của đúng Toà nhà đang thuê. InvoiceForm tự điền vào hoá đơn mới theo danh
    // sách này (snapshot số tiền lúc lập hoá đơn — xem InvoiceItem), không cần chọn lại mỗi tháng.
    public function surcharges(): BelongsToMany
    {
        return $this->belongsToMany(Surcharge::class, 'minihouse_contract_surcharges');
    }

    // Nhật ký gia hạn — xem ContractRenewal + EditContract::getHeaderActions() ("Gia hạn hợp đồng").
    public function renewals(): HasMany
    {
        return $this->hasMany(ContractRenewal::class)->latest();
    }

    // Chuyển phòng — xem EditContract::getHeaderActions() ("Chuyển phòng"). Hợp đồng CŨ (đã hết
    // hạn vì chuyển đi) trỏ transferred_to_contract_id sang hợp đồng MỚI; hợp đồng MỚI trỏ ngược lại
    // transferred_from_contract_id — 2 cột riêng (không dùng 1 cột tự tham chiếu 2 chiều) để truy
    // vấn theo chiều nào cũng ra thẳng kết quả, không phải tự đoán chiều.
    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_contract_id');
    }

    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_contract_id');
    }
}
