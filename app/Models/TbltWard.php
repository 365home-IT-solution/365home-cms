<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tham chiếu RIÊNG cho tính năng "Khai báo lưu trú" — trích nguyên văn sheet PHUONG_XA của mẫu
// tblt_vn_import.xlsx. KHÔNG liên quan tới bảng `wards` hiện có (mã khác hệ).
class TbltWard extends Model
{
    protected $fillable = ['code', 'name', 'display', 'province_code'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(TbltProvince::class, 'province_code', 'code');
    }
}
