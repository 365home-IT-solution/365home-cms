<?php

namespace Modules\QA\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class QA extends Model
{
    use HasFactory;

    protected $table = 'qa';
    
    protected $fillable = [
        'name',
        'slug',
        'categories',
        'status',
        'qa_data'
    ];

    protected $casts = [
        'categories' => 'array',
        'qa_data' => 'array'
    ];
}
