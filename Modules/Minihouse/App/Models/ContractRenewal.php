<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

class ContractRenewal extends Model
{
    use LogsMinihouseActivity;

    protected $table = 'minihouse_contract_renewals';

    protected $fillable = [
        'contract_id', 'old_end_date', 'new_end_date', 'old_monthly_price', 'new_monthly_price', 'note', 'created_by',
    ];

    protected $casts = [
        'old_end_date'      => 'date',
        'new_end_date'      => 'date',
        'old_monthly_price' => 'float',
        'new_monthly_price' => 'float',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
