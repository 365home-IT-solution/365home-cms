<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuilding;

class Building extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuilding;
    use LogsMinihouseActivity;

    public const PAYMENT_METHOD_VIETQR = 'vietqr';
    public const PAYMENT_METHOD_PAYOS  = 'payos';
    public const PAYMENT_METHOD_MOMO   = 'momo';
    public const PAYMENT_METHOD_VNPAY  = 'vnpay';

    // CALENDAR_MONTH (mặc định): lập hoá đơn theo đúng tháng dương lịch, mọi hợp đồng trong toà đóng
    // tiền cùng đợt. ANNIVERSARY: theo ngày BẮT ĐẦU của TỪNG hợp đồng (mỗi khách 1 mốc riêng theo
    // ngày dọn vào) — xem InvoiceGenerationService::generateDueAnniversaryInvoices().
    public const BILLING_CYCLE_CALENDAR_MONTH = 'calendar_month';
    public const BILLING_CYCLE_ANNIVERSARY    = 'anniversary_date';

    protected $table = 'minihouse_buildings';

    protected $fillable = [
        'zone_id',
        'name', 'address', 'province', 'ward',
        'electric_unit_price', 'water_unit_price',
        'note', 'image',
        'payment_method',
        'billing_cycle_type', 'payment_reminder_days_before', 'payment_reminder_repeat_days', 'fixed_due_day',
        'contract_expiry_reminder_days_before',
        'owner_name', 'owner_phone', 'owner_id_card_number', 'owner_email', 'owner_address',
        'owner_bank_bin', 'owner_bank_name',
        'owner_bank_account_number', 'owner_bank_account_holder',
        'payos_client_id', 'payos_api_key', 'payos_checksum_key',
        'momo_partner_code', 'momo_access_key', 'momo_secret_key',
        'vnpay_tmn_code', 'vnpay_hash_secret',
        'payment_sandbox',
    ];

    // float — tránh cast 'decimal:2' luôn ép hiện đủ 2 số lẻ (VD "2500.00") dù giá trị là số nguyên.
    protected $casts = [
        'electric_unit_price'           => 'float',
        'water_unit_price'              => 'float',
        'payment_reminder_days_before'  => 'integer',
        'payment_reminder_repeat_days'  => 'integer',
        'fixed_due_day'                 => 'integer',
        'contract_expiry_reminder_days_before' => 'integer',
        'payment_sandbox'               => 'boolean',
    ];

    public function usesAnniversaryBilling(): bool
    {
        return $this->billing_cycle_type === self::BILLING_CYCLE_ANNIVERSARY;
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function surcharges(): HasMany
    {
        return $this->hasMany(Surcharge::class);
    }

    // Đủ thông tin để đưa vào mục "BÊN CHO THUÊ" của hợp đồng thuê phòng (xem
    // ContractContentRenderer::render()) — CCCD + địa chỉ là 2 field pháp lý bắt buộc phải có trên
    // hợp đồng giấy, tên/điện thoại có thể suy luận thiếu nhưng vẫn nên đủ.
    public function hasCompleteOwnerProfile(): bool
    {
        return filled($this->owner_name) && filled($this->owner_id_card_number) && filled($this->owner_address);
    }

    // Đủ dữ liệu để dựng QR chuyển khoản (xem VietQrService) — thiếu 1 trong 2 field bắt buộc thì
    // không hiện QR trên phiếu thu, tránh in ra QR hỏng/không quét được.
    public function hasOwnerBankInfo(): bool
    {
        return filled($this->owner_bank_bin) && filled($this->owner_bank_account_number);
    }

    // Toà nhà có tài khoản PayOS RIÊNG (khác tài khoản PayOS chung của cả hệ thống, dùng chung với
    // Home) — khi có đủ 3 field này, InvoicePayOsService tự dùng đúng tài khoản này để tạo mã QR,
    // tiền vào thẳng tài khoản của chủ toà, PayOS tự gọi webhook xác nhận — không cần "Chi hộ"
    // chuyển tiền lại cho chủ toà vì tiền đã vào đúng chỗ ngay từ đầu.
    public function hasOwnPayOs(): bool
    {
        return filled($this->payos_client_id) && filled($this->payos_api_key) && filled($this->payos_checksum_key);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [client_id, api_key, checksum_key]
     */
    public function payOsCredentials(): array
    {
        return [$this->payos_client_id, $this->payos_api_key, $this->payos_checksum_key];
    }

    // Toà nhà có tài khoản MoMo Business RIÊNG — cùng nguyên tắc hasOwnPayOs(), nhưng KHÔNG có tài
    // khoản chung để rơi về (xem ghi chú ở migration add_momo_vnpay_credentials...) — chưa khai báo
    // đủ 3 field thì MoMo coi như CHƯA cấu hình cho toà đó, không có phương án dự phòng nào khác.
    public function hasOwnMomo(): bool
    {
        return filled($this->momo_partner_code) && filled($this->momo_access_key) && filled($this->momo_secret_key);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [partner_code, access_key, secret_key]
     */
    public function momoCredentials(): array
    {
        return [$this->momo_partner_code, $this->momo_access_key, $this->momo_secret_key];
    }

    // Toà nhà có tài khoản VNPay (merchant) RIÊNG — cùng nguyên tắc, cũng KHÔNG có tài khoản chung.
    public function hasOwnVnpay(): bool
    {
        return filled($this->vnpay_tmn_code) && filled($this->vnpay_hash_secret);
    }

    /**
     * @return array{0: string, 1: string} [tmn_code, hash_secret]
     */
    public function vnpayCredentials(): array
    {
        return [$this->vnpay_tmn_code, $this->vnpay_hash_secret];
    }

    // Kiểu thanh toán THỰC SỰ áp dụng cho toà nhà này — dựa trên LỰA CHỌN tường minh của nhân viên
    // (payment_method, chọn ở BuildingForm) chứ KHÔNG tự suy luận theo trường nào đang được điền,
    // tránh mơ hồ khi nhiều mục cùng có dữ liệu. Trả về null nếu đã chọn 1 kiểu nhưng chưa điền đủ
    // thông tin cho kiểu đó (VD chọn "PayOS riêng" nhưng bỏ trống API Key).
    public function activePaymentMethod(): ?string
    {
        return match ($this->payment_method) {
            self::PAYMENT_METHOD_PAYOS  => $this->hasOwnPayOs() ? self::PAYMENT_METHOD_PAYOS : null,
            self::PAYMENT_METHOD_MOMO   => $this->hasOwnMomo() ? self::PAYMENT_METHOD_MOMO : null,
            self::PAYMENT_METHOD_VNPAY  => $this->hasOwnVnpay() ? self::PAYMENT_METHOD_VNPAY : null,
            self::PAYMENT_METHOD_VIETQR => $this->hasOwnerBankInfo() ? self::PAYMENT_METHOD_VIETQR : null,
            default                     => null,
        };
    }

    // Building tự thân LÀ toà nhà — không có building_id/room_id/contract_id để LogsMinihouseActivity
    // tự suy ra như các model khác.
    protected function activityBuildingId(): ?int
    {
        return $this->id;
    }
}
