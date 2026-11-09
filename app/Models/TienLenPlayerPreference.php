<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Tuỳ chọn riêng của khách trong Tiến lên (hiện chỉ có bộ bài đang dùng) — 1 dòng / khách.
class TienLenPlayerPreference extends Model
{
    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['customer_id', 'card_deck'];
}
