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
// tại trên categories được uỷ quyền qua BuildingSetting (bảng minihouse_building_settings) trong
// getAttribute()/setAttribute() bên dưới; 'note' forward sang cột 'description' thật của Category.
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

    private const RENAMED = ['note' => 'description'];

    private const DETAIL_FIELDS = [
        'zone_id', 'address', 'province', 'ward',
        'electric_unit_price', 'water_unit_price',
        'payment_method',
        'billing_cycle_type', 'payment_reminder_days_before', 'payment_reminder_repeat_days',
        'fixed_due_day', 'contract_expiry_reminder_days_before',
        'owner_name', 'owner_phone', 'owner_id_card_number', 'owner_email', 'owner_address',
        'owner_bank_bin', 'owner_bank_name', 'owner_bank_account_number', 'owner_bank_account_holder',
        'payos_client_id', 'payos_api_key', 'payos_checksum_key',
        'momo_partner_code', 'momo_access_key', 'momo_secret_key',
        'vnpay_tmn_code', 'vnpay_hash_secret', 'payment_sandbox',
    ];

    // 'province'/'ward' hiển thị (chuỗi tự do, giữ API cũ) map sang cột *_raw của BuildingSetting —
    // liên kết THẬT với bảng provinces/province_branches của Home được đồng bộ ở saved() bên dưới.
    private const DETAIL_COLUMN_MAP = ['province' => 'province_name_raw', 'ward' => 'ward_raw'];

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

    protected $appends = [
        'zone_id', 'address', 'province', 'ward', 'note',
        'electric_unit_price', 'water_unit_price', 'payment_method',
        'billing_cycle_type', 'payment_reminder_days_before', 'payment_reminder_repeat_days',
        'fixed_due_day', 'contract_expiry_reminder_days_before',
        'owner_name', 'owner_phone', 'owner_id_card_number', 'owner_email', 'owner_address',
        'owner_bank_bin', 'owner_bank_name', 'owner_bank_account_number', 'owner_bank_account_holder',
        'payos_client_id', 'payos_api_key', 'payos_checksum_key',
        'momo_partner_code', 'momo_access_key', 'momo_secret_key',
        'vnpay_tmn_code', 'vnpay_hash_secret', 'payment_sandbox',
    ];

    private array $pendingDetail = [];

    public function getAttribute($key)
    {
        if (isset(self::RENAMED[$key])) {
            return parent::getAttribute(self::RENAMED[$key]);
        }

        if (in_array($key, self::DETAIL_FIELDS, true)) {
            if (array_key_exists($key, $this->pendingDetail)) {
                return $this->pendingDetail[$key];
            }

            $column = self::DETAIL_COLUMN_MAP[$key] ?? $key;

            return $this->detail?->{$column};
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (isset(self::RENAMED[$key])) {
            return parent::setAttribute(self::RENAMED[$key], $value);
        }

        if (in_array($key, self::DETAIL_FIELDS, true)) {
            $column                      = self::DETAIL_COLUMN_MAP[$key] ?? $key;
            $this->pendingDetail[$column] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
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
