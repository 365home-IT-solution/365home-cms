<?php

namespace Modules\Minihouse\App\Models;

use App\Models\Province;
use App\Models\ProvinceBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Exceptions\CannotDeleteReferencedRecordException;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuilding;
use Modules\Minihouse\App\Support\HomestayBridge;

// "Toà nhà" MiniHouse — VẬT LÝ là 1 dòng trong bảng `categories` của Home (category_type=product,
// parent_id=null, "chi nhánh"), đồng nhất kiến trúc dữ liệu, xem plan gộp MiniHouse-Homestay.
//
// GIỮ NGUYÊN 100% API cũ ($building->address/$building->owner_name/$building->payos_client_id...,
// Building::create([...])) để toàn bộ code đang dùng class này KHÔNG PHẢI SỬA GÌ — field không tồn
// tại trên categories được uỷ quyền qua BuildingSetting (bảng minihouse_building_settings) bằng
// accessor/mutator (getXAttribute/setXAttribute) chuẩn của Eloquent bên dưới; 'note' forward sang
// cột 'description' thật của Category.
class Building extends Category
{
    use SoftDeletes;
    use ScopedToActiveBuilding;
    use LogsMinihouseActivity;

    public const PAYMENT_METHOD_VIETQR = 'vietqr';
    public const PAYMENT_METHOD_PAYOS  = 'payos';
    public const PAYMENT_METHOD_MOMO   = 'momo';
    public const PAYMENT_METHOD_VNPAY  = 'vnpay';

    public const BILLING_CYCLE_CALENDAR_MONTH = 'calendar_month';
    public const BILLING_CYCLE_ANNIVERSARY    = 'anniversary_date';

    // Category KHÔNG khai báo $table tường minh (dựa vào quy ước Eloquent tự suy tên bảng từ TÊN
    // CLASS THẬT LÚC CHẠY) — Building là class con nên PHẢI khai báo lại rõ ràng, nếu không Eloquent
    // sẽ tự suy nhầm thành bảng "buildings" (số nhiều của "Building") thay vì "categories".
    protected $table = 'categories';

    // Category::$fillable KHÔNG có các cột riêng của Building (zone_id/address/owner_*/payos_*/...)
    // — PHẢI khai báo lại tường minh ở đây, nếu không mass-assignment (Building::create([...])) sẽ
    // ÂM THẦM bỏ qua toàn bộ các key này (không lỗi, không warning) vì Eloquent chỉ gọi
    // set{X}Attribute() cho các key nằm trong $fillable, khiến $pendingDetail luôn rỗng và
    // minihouse_building_settings không bao giờ được ghi (bug thật đã gặp: zone_id truyền vào
    // Building::create() bị rơi mất, khiến guard "không thể xoá Zone còn Toà nhà" không phát hiện
    // được toà nhà nào cả).
    protected $fillable = [
        'name', 'slug', 'description', 'parent_id', 'sort_order',
        'zone_id', 'address', 'province', 'ward', 'note',
        'electric_unit_price', 'water_unit_price', 'payment_method',
        'billing_cycle_type', 'payment_reminder_days_before', 'payment_reminder_repeat_days',
        'fixed_due_day', 'contract_expiry_reminder_days_before',
        'owner_name', 'owner_phone', 'owner_id_card_number', 'owner_id_card_issued_date', 'owner_id_card_issued_place',
        'owner_email', 'owner_address',
        'owner_bank_bin', 'owner_bank_name', 'owner_bank_account_number', 'owner_bank_account_holder',
        'payos_client_id', 'payos_api_key', 'payos_checksum_key',
        'momo_partner_code', 'momo_access_key', 'momo_secret_key',
        'vnpay_tmn_code', 'vnpay_hash_secret', 'payment_sandbox',
    ];

    protected $appends = [
        'zone_id', 'address', 'province', 'ward', 'note',
        'electric_unit_price', 'water_unit_price', 'payment_method',
        'billing_cycle_type', 'payment_reminder_days_before', 'payment_reminder_repeat_days',
        'fixed_due_day', 'contract_expiry_reminder_days_before',
        'owner_name', 'owner_phone', 'owner_id_card_number', 'owner_id_card_issued_date', 'owner_id_card_issued_place',
        'owner_email', 'owner_address',
        'owner_bank_bin', 'owner_bank_name', 'owner_bank_account_number', 'owner_bank_account_holder',
        'payos_client_id', 'payos_api_key', 'payos_checksum_key',
        'momo_partner_code', 'momo_access_key', 'momo_secret_key',
        'vnpay_tmn_code', 'vnpay_hash_secret', 'payment_sandbox',
    ];

    private array $pendingDetail = [];

    // Dung DUNG quy uoc accessor/mutator chuan cua Eloquent (getXAttribute/setXAttribute) - KHONG
    // override getAttribute()/setAttribute() o tang thap nhu ban truoc: attributesToArray() (Filament
    // EditRecord::fillForm() goi khi tai trang Sua) xu ly $appends bang cach goi THANG
    // get{X}Attribute(), KHONG di qua getAttribute() - ban override cu chi bat duoc property access
    // thong thuong, nem loi "Call to undefined method" o duong goi truc tiep nay (da tu kiem chung).
    public function getNoteAttribute(): ?string
    {
        return $this->attributes['description'] ?? null;
    }

    public function setNoteAttribute(?string $value): void
    {
        $this->attributes['description'] = $value;
    }

    public function getAddressAttribute(): ?string
    {
        return $this->detailValue('address');
    }

    public function setAddressAttribute($value): void
    {
        $this->pendingDetail['address'] = $value;
    }

    public function getPaymentMethodAttribute(): ?string
    {
        return $this->detailValue('payment_method');
    }

    public function setPaymentMethodAttribute($value): void
    {
        $this->pendingDetail['payment_method'] = $value;
    }

    public function getBillingCycleTypeAttribute(): ?string
    {
        return $this->detailValue('billing_cycle_type');
    }

    public function setBillingCycleTypeAttribute($value): void
    {
        $this->pendingDetail['billing_cycle_type'] = $value;
    }

    public function getOwnerNameAttribute(): ?string
    {
        return $this->detailValue('owner_name');
    }

    public function setOwnerNameAttribute($value): void
    {
        $this->pendingDetail['owner_name'] = $value;
    }

    public function getOwnerPhoneAttribute(): ?string
    {
        return $this->detailValue('owner_phone');
    }

    public function setOwnerPhoneAttribute($value): void
    {
        $this->pendingDetail['owner_phone'] = $value;
    }

    public function getOwnerIdCardNumberAttribute(): ?string
    {
        return $this->detailValue('owner_id_card_number');
    }

    public function setOwnerIdCardNumberAttribute($value): void
    {
        $this->pendingDetail['owner_id_card_number'] = $value;
    }

    public function getOwnerIdCardIssuedDateAttribute(): ?string
    {
        return $this->detailValue('owner_id_card_issued_date');
    }

    public function setOwnerIdCardIssuedDateAttribute($value): void
    {
        $this->pendingDetail['owner_id_card_issued_date'] = $value;
    }

    public function getOwnerIdCardIssuedPlaceAttribute(): ?string
    {
        return $this->detailValue('owner_id_card_issued_place');
    }

    public function setOwnerIdCardIssuedPlaceAttribute($value): void
    {
        $this->pendingDetail['owner_id_card_issued_place'] = $value;
    }

    public function getOwnerEmailAttribute(): ?string
    {
        return $this->detailValue('owner_email');
    }

    public function setOwnerEmailAttribute($value): void
    {
        $this->pendingDetail['owner_email'] = $value;
    }

    public function getOwnerAddressAttribute(): ?string
    {
        return $this->detailValue('owner_address');
    }

    public function setOwnerAddressAttribute($value): void
    {
        $this->pendingDetail['owner_address'] = $value;
    }

    public function getOwnerBankBinAttribute(): ?string
    {
        return $this->detailValue('owner_bank_bin');
    }

    public function setOwnerBankBinAttribute($value): void
    {
        $this->pendingDetail['owner_bank_bin'] = $value;
    }

    public function getOwnerBankNameAttribute(): ?string
    {
        return $this->detailValue('owner_bank_name');
    }

    public function setOwnerBankNameAttribute($value): void
    {
        $this->pendingDetail['owner_bank_name'] = $value;
    }

    public function getOwnerBankAccountNumberAttribute(): ?string
    {
        return $this->detailValue('owner_bank_account_number');
    }

    public function setOwnerBankAccountNumberAttribute($value): void
    {
        $this->pendingDetail['owner_bank_account_number'] = $value;
    }

    public function getOwnerBankAccountHolderAttribute(): ?string
    {
        return $this->detailValue('owner_bank_account_holder');
    }

    public function setOwnerBankAccountHolderAttribute($value): void
    {
        $this->pendingDetail['owner_bank_account_holder'] = $value;
    }

    public function getPayosClientIdAttribute(): ?string
    {
        return $this->detailValue('payos_client_id');
    }

    public function setPayosClientIdAttribute($value): void
    {
        $this->pendingDetail['payos_client_id'] = $value;
    }

    public function getPayosApiKeyAttribute(): ?string
    {
        return $this->detailValue('payos_api_key');
    }

    public function setPayosApiKeyAttribute($value): void
    {
        $this->pendingDetail['payos_api_key'] = $value;
    }

    public function getPayosChecksumKeyAttribute(): ?string
    {
        return $this->detailValue('payos_checksum_key');
    }

    public function setPayosChecksumKeyAttribute($value): void
    {
        $this->pendingDetail['payos_checksum_key'] = $value;
    }

    public function getMomoPartnerCodeAttribute(): ?string
    {
        return $this->detailValue('momo_partner_code');
    }

    public function setMomoPartnerCodeAttribute($value): void
    {
        $this->pendingDetail['momo_partner_code'] = $value;
    }

    public function getMomoAccessKeyAttribute(): ?string
    {
        return $this->detailValue('momo_access_key');
    }

    public function setMomoAccessKeyAttribute($value): void
    {
        $this->pendingDetail['momo_access_key'] = $value;
    }

    public function getMomoSecretKeyAttribute(): ?string
    {
        return $this->detailValue('momo_secret_key');
    }

    public function setMomoSecretKeyAttribute($value): void
    {
        $this->pendingDetail['momo_secret_key'] = $value;
    }

    public function getVnpayTmnCodeAttribute(): ?string
    {
        return $this->detailValue('vnpay_tmn_code');
    }

    public function setVnpayTmnCodeAttribute($value): void
    {
        $this->pendingDetail['vnpay_tmn_code'] = $value;
    }

    public function getVnpayHashSecretAttribute(): ?string
    {
        return $this->detailValue('vnpay_hash_secret');
    }

    public function setVnpayHashSecretAttribute($value): void
    {
        $this->pendingDetail['vnpay_hash_secret'] = $value;
    }

    public function getZoneIdAttribute(): ?int
    {
        return ($v = $this->detailValue('zone_id')) !== null ? (int) $v : null;
    }

    public function setZoneIdAttribute($value): void
    {
        $this->pendingDetail['zone_id'] = $value;
    }

    public function getPaymentReminderDaysBeforeAttribute(): ?int
    {
        return ($v = $this->detailValue('payment_reminder_days_before')) !== null ? (int) $v : null;
    }

    public function setPaymentReminderDaysBeforeAttribute($value): void
    {
        $this->pendingDetail['payment_reminder_days_before'] = $value;
    }

    public function getPaymentReminderRepeatDaysAttribute(): ?int
    {
        return ($v = $this->detailValue('payment_reminder_repeat_days')) !== null ? (int) $v : null;
    }

    public function setPaymentReminderRepeatDaysAttribute($value): void
    {
        $this->pendingDetail['payment_reminder_repeat_days'] = $value;
    }

    public function getFixedDueDayAttribute(): ?int
    {
        return ($v = $this->detailValue('fixed_due_day')) !== null ? (int) $v : null;
    }

    public function setFixedDueDayAttribute($value): void
    {
        $this->pendingDetail['fixed_due_day'] = $value;
    }

    public function getContractExpiryReminderDaysBeforeAttribute(): ?int
    {
        return ($v = $this->detailValue('contract_expiry_reminder_days_before')) !== null ? (int) $v : null;
    }

    public function setContractExpiryReminderDaysBeforeAttribute($value): void
    {
        $this->pendingDetail['contract_expiry_reminder_days_before'] = $value;
    }

    public function getElectricUnitPriceAttribute(): ?float
    {
        return ($v = $this->detailValue('electric_unit_price')) !== null ? (float) $v : null;
    }

    public function setElectricUnitPriceAttribute($value): void
    {
        $this->pendingDetail['electric_unit_price'] = $value;
    }

    public function getWaterUnitPriceAttribute(): ?float
    {
        return ($v = $this->detailValue('water_unit_price')) !== null ? (float) $v : null;
    }

    public function setWaterUnitPriceAttribute($value): void
    {
        $this->pendingDetail['water_unit_price'] = $value;
    }

    public function getPaymentSandboxAttribute(): bool
    {
        return (bool) $this->detailValue('payment_sandbox');
    }

    public function setPaymentSandboxAttribute($value): void
    {
        $this->pendingDetail['payment_sandbox'] = $value;
    }

    public function getProvinceAttribute(): ?string
    {
        return $this->detailValue('province_name_raw');
    }

    public function setProvinceAttribute($value): void
    {
        $this->pendingDetail['province_name_raw'] = $value;
    }

    public function getWardAttribute(): ?string
    {
        return $this->detailValue('ward_raw');
    }

    public function setWardAttribute($value): void
    {
        $this->pendingDetail['ward_raw'] = $value;
    }
    private function detailValue(string $key)
    {
        if (array_key_exists($key, $this->pendingDetail)) {
            return $this->pendingDetail[$key];
        }

        return $this->detail?->{$key};
    }

    public function detail(): HasOne
    {
        return $this->hasOne(BuildingSetting::class, 'category_id');
    }

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
        return $this->hasMany(Room::class, 'building_id');
    }

    public function panoramaScenes(): HasMany
    {
        return $this->hasMany(PanoramaScene::class, 'building_id')->orderBy('sort_order');
    }

    public function surcharges(): HasMany
    {
        return $this->hasMany(Surcharge::class, 'building_id');
    }

    public function hasCompleteOwnerProfile(): bool
    {
        return filled($this->owner_name) && filled($this->owner_id_card_number) && filled($this->owner_address);
    }

    public function hasOwnerBankInfo(): bool
    {
        return filled($this->owner_bank_bin) && filled($this->owner_bank_account_number);
    }

    public function hasOwnPayOs(): bool
    {
        return filled($this->payos_client_id) && filled($this->payos_api_key) && filled($this->payos_checksum_key);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function payOsCredentials(): array
    {
        return [$this->payos_client_id, $this->payos_api_key, $this->payos_checksum_key];
    }

    public function hasOwnMomo(): bool
    {
        return filled($this->momo_partner_code) && filled($this->momo_access_key) && filled($this->momo_secret_key);
    }

    /** @return array{0: string, 1: string, 2: string} */
    public function momoCredentials(): array
    {
        return [$this->momo_partner_code, $this->momo_access_key, $this->momo_secret_key];
    }

    public function hasOwnVnpay(): bool
    {
        return filled($this->vnpay_tmn_code) && filled($this->vnpay_hash_secret);
    }

    /** @return array{0: string, 1: string} */
    public function vnpayCredentials(): array
    {
        return [$this->vnpay_tmn_code, $this->vnpay_hash_secret];
    }

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

    protected function activityBuildingId(): ?int
    {
        return $this->id;
    }

    protected static function booted(): void
    {
        static::addGlobalScope('minihouse_buildings_only', function (Builder $query) {
            $query->where('category_type', 'product')
                ->whereNull('parent_id')
                ->where('partner_id', HomestayBridge::PARTNER_ID);
        });

        static::creating(function (Building $building) {
            $building->category_type ??= 'product';
            $building->parent_id     ??= null;
            $building->partner_id    ??= HomestayBridge::PARTNER_ID;
            $building->status        ??= true;

            if (! $building->slug) {
                $building->slug = self::generateUniqueSlug($building->name ?: 'toa-nha');
            }
        });

        static::saved(function (Building $building) {
            if (! empty($building->pendingDetail)) {
                BuildingSetting::updateOrCreate(['category_id' => $building->id], $building->pendingDetail);
                $building->pendingDetail = [];
                $building->unsetRelation('detail');
            }

            self::syncProvinceLink($building);
        });

        static::deleting(function (Building $building) {
            if ($building->rooms()->exists()) {
                throw new CannotDeleteReferencedRecordException(
                    'Toà nhà này vẫn còn Phòng thuộc về nó — không thể xoá. Hãy chuyển hoặc xoá các Phòng đó trước.'
                );
            }
        });
    }

    // Đồng bộ liên kết THẬT với bảng provinces/province_branches của Home mỗi khi 'province' đổi —
    // giữ đúng tinh thần Giai đoạn 2 (migrateBuildings()), áp dụng cho cả sửa tay qua BuildingForm.
    private static function syncProvinceLink(Building $building): void
    {
        $provinceName = $building->province;

        if (! $provinceName) {
            return;
        }

        $province = Province::firstOrCreate(
            ['name' => $provinceName],
            ['slug' => Str::slug($provinceName)]
        );

        ProvinceBranch::firstOrCreate([
            'province_id'  => $province->id,
            'categorie_id' => $building->id,
        ], ['status' => true]);
    }

    private static function generateUniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'toa-nha';
        $i    = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = Str::slug($base) . '-' . (++$i);
        }

        return $slug;
    }
}
