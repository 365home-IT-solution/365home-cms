<?php

declare(strict_types=1);

namespace Modules\Employee\Entities;

use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SalaryTemplate extends Model
{
    use BelongsToPartner;

    protected $fillable = [
        'partner_id',
        'name',
        'slug',
        'salary_type_id',
        'base_amount',
        'description',
        'status',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'status'      => 'boolean',
    ];

    public function salaryType(): BelongsTo
    {
        return $this->belongsTo(SalaryType::class);
    }

    public function allowanceTypes(): BelongsToMany
    {
        return $this->belongsToMany(AllowanceType::class, 'salary_template_allowances')
            ->withPivot('amount')
            ->withTimestamps();
    }

    public function deductionTypes(): BelongsToMany
    {
        return $this->belongsToMany(DeductionType::class, 'salary_template_deductions')
            ->withPivot('amount')
            ->withTimestamps();
    }
}
