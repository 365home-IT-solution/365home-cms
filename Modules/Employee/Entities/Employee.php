<?php

declare(strict_types=1);

namespace Modules\Employee\Entities;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Province;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\RoomCleaningLog;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Employee extends Model implements HasMedia
{
    use InteractsWithMedia, BelongsToPartner;

    protected $fillable = [
        'created_by',
        'partner_id',
        'employee_code',
        'attendance_code',
        'name',
        'phone',
        'pay_branch_id',
        'works_all_branches',
        'department_id',
        'position_id',
        'user_id',
        'hire_date',
        'note',
        'id_number',
        'date_of_birth',
        'gender',
        'address',
        'province_id',
        'ward_code',
        'email',
        'facebook_url',
        'salary_type_id',
        'salary_template_id',
        'base_amount',
        'has_allowances',
        'has_deductions',
        'status',
    ];

    protected $casts = [
        'works_all_branches' => 'boolean',
        // Chỉ định rõ format 'Y-m-d' (không kèm giờ) — nếu để cast 'date' trơn, Laravel serialize
        // JSON theo ISO8601 UTC, còn app chạy timezone Asia/Ho_Chi_Minh (UTC+7) nên nửa đêm giờ VN
        // bị lùi về NGÀY HÔM TRƯỚC lúc 17:00 UTC khi trả ra JSON (vd 2024-01-01 -> "2023-12-31T17:00:00Z").
        'hire_date'           => 'date:Y-m-d',
        'date_of_birth'       => 'date:Y-m-d',
        'base_amount'         => 'decimal:2',
        'has_allowances'      => 'boolean',
        'has_deductions'      => 'boolean',
        'status'              => 'boolean',
    ];

    // avatar_url là accessor ảo (không phải cột DB) — PHẢI khai báo ở $appends thì mới
    // xuất hiện trong response JSON, nếu không frontend luôn nhận về undefined.
    protected $appends = ['avatar_url'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Employee $employee) {
            if (empty($employee->employee_code)) {
                $employee->employee_code = static::generateEmployeeCode();
            }
        });
    }

    public static function generateEmployeeCode(): string
    {
        $number = static::count() + 1;

        do {
            $code = 'NV' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $number++;
        } while (static::where('employee_code', $code)->exists());

        return $code;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('avatar') ?: null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Mỗi đối tác chỉ thấy/quản lý nhân viên thuộc partner_id của chính mình.
    // super_admin bỏ qua bộ lọc này, xem được toàn bộ nhân viên của mọi đối tác.
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('partner_id', $user->partner_id);
    }

    public function payBranch(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'pay_branch_id');
    }

    public function workBranches(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'employee_branches', 'employee_id', 'category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'ward_code', 'code');
    }

    public function salaryType(): BelongsTo
    {
        return $this->belongsTo(SalaryType::class);
    }

    public function salaryTemplate(): BelongsTo
    {
        return $this->belongsTo(SalaryTemplate::class);
    }

    public function allowanceTypes(): BelongsToMany
    {
        return $this->belongsToMany(AllowanceType::class, 'employee_allowances')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function deductionTypes(): BelongsToMany
    {
        return $this->belongsToMany(DeductionType::class, 'employee_deductions')
            ->withPivot('amount')
            ->withTimestamps();
    }

    // Lượt dọn phòng nhân viên này đã xác nhận (cleaned_at != null) — được ghi tự động ngay khi
    // xác nhận dọn xong ở màn "Kiểm tra dọn phòng" (xem Product::confirmCleaned()), không cần
    // nhập tay. Dùng để tính lương theo lượt cho vị trí piece_rate (vd Lễ tân).
    public function cleaningLogs(): HasMany
    {
        return $this->hasMany(RoomCleaningLog::class);
    }

    // Lượt tư vấn khách hàng — nhân viên/lễ tân tự ghi nhận (xem ConsultationLogResource).
    public function consultationLogs(): HasMany
    {
        return $this->hasMany(ConsultationLog::class);
    }

    public function getIsPieceRateAttribute(): bool
    {
        return $this->salaryType?->isPieceRate() ?? false;
    }

    // Chỉ lấy lượt PHÁT SINH TRONG THÁNG HIỆN TẠI — lương tính theo lượt là lương của THÁNG,
    // không cộng dồn lượt của các tháng trước.
    public function getMonthlyCleaningLogsAttribute(): Collection
    {
        return $this->cleaningLogs()
            ->whereNotNull('cleaned_at')
            ->whereMonth('cleaned_at', now()->month)
            ->whereYear('cleaned_at', now()->year)
            ->with('product')
            ->orderByDesc('cleaned_at')
            ->get();
    }

    public function getMonthlyConsultationLogsAttribute(): Collection
    {
        return $this->consultationLogs()
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->orderByDesc('consulted_at')
            ->get();
    }

    // Lương theo lượt của THÁNG HIỆN TẠI = số lượt dọn phòng × đơn giá/lượt dọn + số lượt tư vấn
    // × đơn giá/lượt tư vấn (2 đơn giá này khai báo ở Loại lương — SalaryType::room_cleaning_rate/
    // consultation_rate, vd Lễ tân: 10.000đ/lượt dọn, 5.000đ/lượt tư vấn).
    public function getPieceRateAmountAttribute(): float
    {
        if (! $this->is_piece_rate) {
            return 0.0;
        }

        $cleaningRate     = (float) ($this->salaryType->room_cleaning_rate ?? 0);
        $consultationRate = (float) ($this->salaryType->consultation_rate ?? 0);

        return ($this->monthly_cleaning_logs->count() * $cleaningRate)
            + ($this->monthly_consultation_logs->count() * $consultationRate);
    }

    // Phần "lương cơ bản" thật sự dùng để tính lương cuối cùng: nếu vị trí tính theo lượt thì
    // KHÔNG dùng base_amount (bỏ qua, kể cả nếu form còn lưu giá trị cũ) mà dùng piece_rate_amount.
    public function getComputedBaseAmountAttribute(): float
    {
        return $this->is_piece_rate ? $this->piece_rate_amount : (float) $this->base_amount;
    }

    // Tổng lương thực nhận mỗi tháng = lương cơ bản (hoặc lương theo lượt) + tổng phụ cấp - tổng
    // giảm trừ. Số tiền phụ cấp/giảm trừ đã được quy đổi ra VNĐ thật ngay lúc chọn (kể cả loại
    // tính theo %, xem UserForm::createSalaryTab()) nên chỉ cần cộng/trừ thẳng cột pivot 'amount',
    // không cần tính lại % ở đây.
    public function getTotalAllowancesAttribute(): float
    {
        return (float) $this->allowanceTypes->sum('pivot.amount');
    }

    public function getTotalDeductionsAttribute(): float
    {
        return (float) $this->deductionTypes->sum('pivot.amount');
    }

    public function getFinalSalaryAttribute(): float
    {
        return $this->computed_base_amount + $this->total_allowances - $this->total_deductions;
    }
}
