<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    public const TYPE_IN  = 'thu';
    public const TYPE_OUT = 'chi';

    public const CATEGORY_REPAIR    = 'sua_chua';
    public const CATEGORY_OPERATION = 'van_hanh';
    public const CATEGORY_OTHER     = 'khac';

    protected $table = 'minihouse_transactions';

    protected $fillable = ['contract_id', 'type', 'category', 'amount', 'transaction_date', 'note', 'receipt_image'];

    protected $casts = [
        'transaction_date' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
