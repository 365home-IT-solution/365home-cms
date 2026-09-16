<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;

// Bảng phụ 1-1 với categories (khoá chính = category_id) giữ các cột riêng của MiniHouse mà
// Category (dùng làm "chi nhánh" của Home) không có — ngân hàng/QR chủ nhà, cổng thanh toán riêng
// từng toà (PayOS/MoMo/VNPay), chu kỳ tính tiền/nhắc nhở, address (Category không có cột address).
// KHÔNG dùng trực tiếp ở nơi khác ngoài Building — mọi truy cập đều đi qua
// Building::getAttribute()/setAttribute() (xem Building.php) để giữ nguyên API cũ cho toàn bộ code
// hiện có, không cần sửa gì thêm.
class BuildingSetting extends Model
{
    protected $table = 'minihouse_building_settings';

    protected $primaryKey = 'category_id';

    public $incrementing = false;

    protected $fillable = [
        'category_id',
        'zone_id', 'address', 'province_name_raw', 'ward_raw',
        'electric_unit_price', 'water_unit_price',
        'owner_name', 'owner_phone', 'owner_id_card_number', 'owner_email', 'owner_address',
        'owner_bank_bin', 'owner_bank_name', 'owner_bank_account_number', 'owner_bank_account_holder',
        'payment_method',
        'payos_client_id', 'payos_api_key', 'payos_checksum_key',
        'momo_partner_code', 'momo_access_key', 'momo_secret_key',
        'vnpay_tmn_code', 'vnpay_hash_secret', 'payment_sandbox',
        'billing_cycle_type', 'payment_reminder_days_before', 'payment_reminder_repeat_days',
        'fixed_due_day', 'contract_expiry_reminder_days_before', 'note',
    ];

    protected $casts = [
        'payment_sandbox' => 'boolean',
    ];
}
