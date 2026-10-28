<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;

class ProvinceBranch extends Model
{
    use HasFactory;

    protected $fillable = [
        'province_id',
        'categorie_id',
        'ward_code',
        'area_name',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categorie_id');
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'ward_code', 'code');
    }

    /**
     * Khu vực hiển thị cho khách (VD "Đà Lạt"): ưu tiên area_name admin nhập; để trống thì lấy phần
     * sau " - " của tên phường mới (VD "Phường Xuân Hương - Đà Lạt" → "Đà Lạt"); không có thì null.
     */
    public function areaName(): ?string
    {
        $area = trim((string) $this->area_name);

        if ($area !== '') {
            return $area;
        }

        $wardName = (string) $this->ward?->name;

        return preg_match('/\s[-–]\s*(.+)$/u', $wardName, $m) ? trim($m[1]) : null;
    }
}
