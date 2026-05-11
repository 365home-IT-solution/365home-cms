<?php
namespace Modules\SettingCompany\Entities;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'address',
        'phone',
        'email',
        'status',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class); // Quan hệ belongsTo
    }
}
