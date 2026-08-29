<?php

declare(strict_types=1);

namespace Modules\Employee\Entities;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Ghi nhận 1 lượt nhân viên (thường là lễ tân) tư vấn cho 1 khách hàng — dùng để tính lương theo
// lượt cho các vị trí không hưởng lương cố định (xem SalaryType::calc_type = 'piece_rate' và
// Employee::getFinalSalaryAttribute()).
class ConsultationLog extends Model
{
    use BelongsToPartner, LogsAuditTrail;

    protected $table = 'employee_consultation_logs';

    protected $fillable = [
        'partner_id',
        'employee_id',
        'customer_name',
        'customer_phone',
        'note',
        'consulted_at',
    ];

    protected $casts = [
        'consulted_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ConsultationLog $log) {
            $log->consulted_at ??= now();
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
