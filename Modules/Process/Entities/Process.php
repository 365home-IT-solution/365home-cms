<?php

namespace Modules\Process\Entities;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Process extends Model
{
    use HasFactory, LogsAuditTrail;

    protected $fillable = [
        'name',
        'description'
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(Step::class)->orderBy('order');
    }
}
