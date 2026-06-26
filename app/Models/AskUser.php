<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AskUser extends Model
{
    protected $table = 'ask_users';

    protected $fillable = [
        'title',
        'description',
        'items',
    ];

    protected $casts = [
        'items' => 'array',
    ];
}
