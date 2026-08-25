<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Tham chiếu RIÊNG cho tính năng "Khai báo lưu trú" — KHÔNG liên quan tới bảng `provinces` hiện
// có (mã khác hệ, dùng cho địa chỉ giao hàng/chi nhánh). Dữ liệu trích nguyên văn sheet TINH_THANH
// của mẫu tblt_vn_import.xlsx.
class TbltProvince extends Model
{
    protected $fillable = ['code', 'name', 'display'];

    public function wards(): HasMany
    {
        return $this->hasMany(TbltWard::class, 'province_code', 'code');
    }
}
