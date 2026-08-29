<?php

namespace Modules\Promotion\App\Models;

use App\Models\Concerns\BelongsToActiveBranchCategories;
use App\Models\Concerns\BelongsToPartner;
use App\Models\Customer;
use App\Models\MembershipTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Category\Entities\Category;
use Modules\Category\Traits\Categorizable;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\Product;

class Coupon extends Model
{
    use HasFactory, BelongsToPartner, Categorizable, BelongsToActiveBranchCategories;

    protected $fillable = [
        'partner_id',
        'price_board_id',
        'code',
        'name',
        'description',
        'type',
        'value',
        'apply_type',
        'room_id',
        'min_order_value',
        'max_discount',
        'usage_limit',
        'used_count',
        'start_at',
        'end_at',
        'is_active',
        'is_exclusive',
        'created_by',
        'customer_id',
        'validity_days',
        'template_coupon_id',
        'auto_issue_tier_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
        'is_exclusive' => 'boolean',
        'value' => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'validity_days' => 'integer',
        'auto_issue_tier_id' => 'integer',
    ];

    /**
     * Quan hệ nhiều-nhiều với RoomTimeSlot
     * Chỉ dùng khi apply_type = 'specific_slot'
     */
    public function roomTimeSlots()
    {
        return $this->belongsToMany(
            RoomTimeSlot::class,
            'coupon_room_time_slot',
            'coupon_id',
            'room_time_slot_id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function priceBoard()
    {
        return $this->belongsTo(\Modules\Product\App\Models\PriceBoard::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function customers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'coupon_customers', 'coupon_id', 'customer_id')
            ->withPivot('assigned_at')
            ->withTimestamps();
    }

    public function usages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function isPersonal(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Coupon MẪU (gắn vào 1 hạng thành viên qua membership_tier_coupon, validity_days đã set) mà
     * bản sao cá nhân này được nhân bản từ đó — null nếu coupon không phải bản sao tự động cấp
     * theo hạng. Xem MembershipService::grantTemplateCoupon().
     */
    public function templateCoupon()
    {
        return $this->belongsTo(self::class, 'template_coupon_id');
    }

    /**
     * Các bản sao cá nhân đã được cấp cho từng khách từ coupon MẪU này.
     */
    public function personalClones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'template_coupon_id');
    }

    /**
     * Các hạng thành viên mà coupon này được gắn vào.
     */
    public function membershipTiers(): BelongsToMany
    {
        return $this->belongsToMany(MembershipTier::class, 'membership_tier_coupon', 'coupon_id', 'membership_tier_id')
            ->withTimestamps();
    }

    /**
     * Hạng thành viên mà coupon NÀY được cấp tự động khi khách đăng nhập (thưởng đăng nhập định kỳ)
     * — null nếu coupon không phải loại này. Dùng để tra lịch sử cấp gần nhất cho 1 khách khi xét
     * điều kiện đủ chu kỳ. Xem MembershipService::grantLoginReward().
     */
    public function autoIssueTier()
    {
        return $this->belongsTo(MembershipTier::class, 'auto_issue_tier_id');
    }

    /**
     * Quan hệ với Room (Product)
     * Dùng khi apply_type = 'specific_room' (SỐ ÍT — đúng 1 phòng)
     */
    public function room()
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    /**
     * Nhiều phòng cụ thể — dùng khi apply_type = 'specific_rooms' (SỐ NHIỀU, khác 'specific_room'
     * số ít ở trên chỉ 1 phòng qua cột room_id). Xem migration coupon_products.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products', 'coupon_id', 'product_id')
            ->withTimestamps();
    }

    /**
     * Coupon có áp dụng được cho 1 phòng cụ thể không (theo apply_type) — KHÔNG kiểm tra
     * active/thời gian/usage_limit (xem isApplicableToSlot() nếu cần đủ cả các điều kiện đó cho 1
     * room_time_slot). 'specific_slot' luôn false ở đây vì cần đúng RoomTimeSlot mới xác định
     * được, không chỉ room_id — dùng isApplicableToSlot() cho trường hợp đó.
     *
     * Cộng thêm điều kiện chi nhánh (qua Categorizable::categories(), gán ở "Coupon tự động cấp"
     * của Form hạng thành viên hoặc trực tiếp trên Coupon) — nếu coupon có gán chi nhánh thì phòng
     * PHẢI thuộc 1 trong các chi nhánh đó (kể cả khu vực con) mới được áp dụng, bất kể apply_type là
     * gì. Không gán chi nhánh nào (mặc định) = áp dụng mọi chi nhánh, giữ nguyên hành vi cũ.
     */
    public function appliesToRoom(string $roomId): bool
    {
        $applies = match ($this->apply_type) {
            'all_rooms'      => true,
            'specific_room'  => $this->room_id === $roomId,
            'specific_rooms' => $this->rooms()->where('products.id', $roomId)->exists(),
            default          => false,
        };

        return $applies && $this->passesBranchRestriction($roomId);
    }

    private function passesBranchRestriction(string $roomId): bool
    {
        $categoryIds = $this->categories()->pluck('categories.id')->all();

        if (empty($categoryIds)) {
            return true;
        }

        $expanded = collect($categoryIds)->flatMap(fn ($id) => $this->expandCategoryIds((int) $id))->unique()->all();

        return Product::where('id', $roomId)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $expanded))
            ->exists();
    }

    // Mở rộng 1 chi nhánh ra chính nó + toàn bộ khu vực con — cùng logic đã dùng ở
    // Api\Admin\PromotionController::expandCategoryIds()/ProductController::expandCategoryIds().
    private function expandCategoryIds(int $categoryId): array
    {
        $allIds       = [$categoryId];
        $currentLevel = [$categoryId];

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            $children = array_diff($children, $allIds);
            if (empty($children)) {
                break;
            }
            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }

    /**
     * Kiểm tra coupon có áp dụng cho room_time_slot này không
     */
    public function isApplicableToSlot(RoomTimeSlot $slot): bool
    {
        // Kiểm tra active và thời gian
        if (!$this->is_active) {
            return false;
        }

        $now = now();
        if ($this->start_at && $now->lt($this->start_at)) {
            return false;
        }
        if ($this->end_at && $now->gt($this->end_at)) {
            return false;
        }

        // Kiểm tra usage limit
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        // Kiểm tra apply_type
        switch ($this->apply_type) {
            case 'all_rooms':
            case 'specific_room':
            case 'specific_rooms':
                return $this->appliesToRoom((string) $slot->room_id);

            case 'specific_slot':
                return $this->roomTimeSlots()->where('room_time_slot_id', $slot->id)->exists()
                    && $this->passesBranchRestriction((string) $slot->room_id);

            default:
                return false;
        }
    }

    /**
     * Tính giá trị giảm giá
     */
    public function calculateDiscount(float $amount): float
    {
        if ($this->type === 'percentage') {
            $discount = ($amount * $this->value) / 100;
        } else {
            $discount = $this->value;
        }

        // Áp dụng max_discount nếu có
        if ($this->max_discount && $discount > $this->max_discount) {
            $discount = $this->max_discount;
        }

        return min($discount, $amount); // Không được vượt quá tổng tiền
    }

    /**
     * Tăng số lần sử dụng. Truyền orderId để đồng thời ghi lại 1 dòng lịch sử sử dụng
     * (coupon_usages) — dùng cho trang quản lý khách hàng/dashboard/export voucher đã dùng.
     * Không truyền orderId khi chỉ cần tăng đếm thô (giữ tương thích các nơi gọi cũ).
     */
    public function incrementUsage(
        ?string $orderId = null,
        ?string $customerId = null,
        ?int $categoryId = null,
        ?int $discountAmount = null
    ): void {
        $this->increment('used_count');

        if ($orderId !== null) {
            $this->usages()->create([
                'customer_id'     => $customerId,
                'order_id'        => $orderId,
                'category_id'     => $categoryId,
                'code'            => $this->code,
                'discount_amount' => $discountAmount,
                'used_at'         => now(),
            ]);
        }
    }
}