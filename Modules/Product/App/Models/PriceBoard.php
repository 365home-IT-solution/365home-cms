<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Promotion\App\Models\Promotion;
use Modules\Promotion\App\Models\Coupon;

class PriceBoard extends Model
{
    use HasFactory, LogsAuditTrail;

    public const MODE_OVERRIDE   = 'override';
    public const MODE_ADJUSTMENT = 'adjustment';

    public const SCOPE_ROOMS    = 'rooms';
    public const SCOPE_BRANCHES = 'branches';
    public const SCOPE_PARTNER  = 'partner';

    protected $fillable = [
        'partner_id',
        'category_id',
        'scope',
        'source_preset',
        'is_template',
        'code',
        'description',
        'tags',
        'default_scope',
        'requires_dates',
        'sort_order',
        'priority',
        'days_of_week',
        'phase',
        'name',
        'note',
        'start_date',
        'end_date',
        'is_default',
        'is_active',
        'pricing_mode',
        'adjustment_type',
        'adjustment_value',
    ];

    protected $casts = [
        'is_template'      => 'boolean',
        'requires_dates'   => 'boolean',
        'tags'             => 'array',
        'days_of_week'     => 'array',
        'priority'         => 'integer',
        'start_date'       => 'date',
        'end_date'         => 'date',
        'is_default'       => 'boolean',
        'is_active'        => 'boolean',
        'adjustment_value' => 'decimal:2',
    ];

    // Duyệt trước khi đăng (App\Services\ApprovalService): đối tác tạo bảng giá MỚI → pending; bảng chưa được duyệt (pending / rejected) không bao giờ bật được.
    protected static function booted(): void
    {
        static::creating(function (PriceBoard $board) {
            if (app(\App\Services\ApprovalService::class)->guards()) {
                $board->setAttribute('approval_status', 'pending');
                $board->setAttribute('is_active', false); // creating chạy SAU saving nên phải tắt luôn ở đây
            }
        });

        static::deleted(fn (PriceBoard $board) => app(\App\Services\ApprovalService::class)->closeForDeletedSubject('price_board', $board->id));

        static::saving(function (PriceBoard $board) {
            if (($board->approval_status ?? 'approved') !== 'approved' && $board->is_active) {
                $board->setAttribute('is_active', false);
            }
        });
    }

    public function isApproved(): bool
    {
        return ($this->approval_status ?? 'approved') === 'approved';
    }

    public function sheets()
    {
        return $this->hasMany(PriceBoardSheet::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Chi nhánh của phạm vi `branches`. */
    public function scopeBranches()
    {
        return $this->belongsToMany(\Modules\Category\Entities\Category::class, 'price_board_branches', 'price_board_id', 'category_id');
    }

    /** Phòng khai báo ở cấp bảng (phạm vi `rooms`). */
    public function declaredRooms()
    {
        return $this->hasMany(PriceBoardRoom::class);
    }

    /** Chi nhánh (category gốc) bảng giá này áp dụng; NULL ở bảng mặc định toàn hệ thống. */
    public function branch()
    {
        return $this->belongsTo(\Modules\Category\Entities\Category::class, 'category_id')->withoutGlobalScopes();
    }

    /** Bảng giá đặt tên (không phải bảng mặc định) mà $user được thấy: super_admin chưa chọn đối tác
     *  thấy hết, còn lại chỉ bảng của đối tác mình. */
    public function scopeVisibleTo(Builder $query, \App\Models\User $user): Builder
    {
        // Bảng mẫu (preset) không phải bảng giá đang vận hành — có API/màn riêng.
        $query->where('is_default', false)->where('is_template', false);

        return $user->seesAllPartners() ? $query : $query->where('partner_id', $user->partner_id);
    }

    /** Id chi nhánh + mọi khu vực con (phòng có thể gắn thẳng chi nhánh hoặc gắn khu vực con của nó). */
    public static function branchCategoryIds(int $branchId): array
    {
        $ids   = [$branchId];
        $level = [$branchId];

        while ($level) {
            $level = \Modules\Category\Entities\Category::withoutGlobalScopes()
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, $level);
        }

        return array_values(array_unique($ids));
    }

    public function isAdjustment(): bool
    {
        return $this->pricing_mode === self::MODE_ADJUSTMENT;
    }

    public function items()
    {
        return $this->hasMany(PriceBoardItem::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'price_board_items', 'price_board_id', 'product_id')
            ->withPivot([
                'id', 'price', 'price_unit', 'full_booking_discount', 'bulk_discount_rules',
                'room_config', 'deposit_1_night', 'deposit_multi_night', 'deposit_min_nights',
                'default_checkin', 'default_checkout',
            ]);
    }

    public function promotions()
    {
        return $this->hasMany(Promotion::class);
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    /** Giai đoạn 2 (priority + days_of_week + giá theo ngày ở) đang bật không. */
    public static function phase2Enabled(): bool
    {
        // Công tắc ở CMS (Cấu hình web › Bảng giá) được ưu tiên hơn .env; chưa chỉnh (null) hoặc đọc lỗi (vd chưa chạy migration cài đặt) thì theo .env.
        if (config('price_boards.cms_toggle', true)) {
            try {
                $setting = app(\App\Settings\PriceBoardSettings::class)->phase2;

                if ($setting !== null) {
                    return (bool) $setting;
                }
            } catch (\Throwable) {
                // rơi về cấu hình .env
            }
        }

        return (bool) config('price_boards.phase2', false);
    }

    /** Thứ 1 = Thứ 2 … 7 = Chủ nhật; rỗng = mọi ngày. */
    public function coversWeekday(\Carbon\Carbon $date): bool
    {
        $days = array_map('intval', (array) $this->days_of_week);

        return $days === [] || in_array((int) $date->dayOfWeekIso, $days, true);
    }

    /** Bảng có hiệu lực cho ngày $date: đang bật, trong khoảng ngày và (GĐ2) đúng thứ. */
    public function appliesOn(\Carbon\Carbon $date): bool
    {
        if (! $this->is_active || ! $this->coversDate($date->copy()->startOfDay())) {
            return false;
        }

        return ! static::phase2Enabled() || $this->coversWeekday($date);
    }

    /** Thứ tự ưu tiên của phạm vi khi priority bằng nhau: hẹp hơn thắng (rooms > branches > partner). */
    public function scopeRank(): int
    {
        return match ($this->scope) {
            self::SCOPE_ROOMS    => 3,
            self::SCOPE_BRANCHES => 2,
            default              => 1,
        };
    }

    /** Bảng đang trong khoảng hiệu lực tại ngày $date (mặc định hôm nay). */
    public function coversDate(?\Carbon\Carbon $date = null): bool
    {
        $date = $date ?? now()->startOfDay();

        if ($this->start_date && $date->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }

        return true;
    }
}
