<?php

declare(strict_types=1);

namespace Modules\Employee\Entities;

use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Model;

class SalaryType extends Model
{
    use BelongsToPartner;

    // calc_type: 'fixed' (lương cơ bản cố định như bình thường) hoặc 'piece_rate' (tính theo số
    // lượt công việc thực tế trong tháng — vd Lễ tân: room_cleaning_rate đồng/lượt dọn phòng +
    // consultation_rate đồng/lượt tư vấn khách hàng, xem Employee::getFinalSalaryAttribute()).
    protected $fillable = [
        'partner_id',
        'name',
        'slug',
        'calc_type',
        'room_cleaning_rate',
        'consultation_rate',
        'description',
        'status',
    ];

    protected $casts = [
        'status'             => 'boolean',
        'room_cleaning_rate' => 'decimal:2',
        'consultation_rate'  => 'decimal:2',
    ];

    public function isPieceRate(): bool
    {
        return $this->calc_type === 'piece_rate';
    }
}
