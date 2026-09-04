<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reminder extends Model
{
    use SoftDeletes;

    public const TYPE_PAYMENT     = 'thu_tien';
    public const TYPE_CONTRACT    = 'het_han_hop_dong';
    public const TYPE_MAINTENANCE = 'bao_tri';
    public const TYPE_OTHER       = 'khac';

    protected $table = 'minihouse_reminders';

    protected $fillable = ['title', 'content', 'remind_date', 'type', 'room_id', 'contract_id', 'is_done'];

    protected $casts = [
        'remind_date' => 'date',
        'is_done'     => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
