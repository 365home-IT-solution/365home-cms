<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseItem extends Model
{
    use BelongsToPartner;

    protected static function boot()
    {
        parent::boot();

        // Ghi lại MỌI lần "quantity" bị sửa TRỰC TIẾP qua trang "Sửa vật tư" (không qua phiếu
        // nhập/xuất/kiểm kê) — chống nhân viên tự ý sửa số tồn để che gian lận mà không để lại dấu
        // vết. CHỈ bắt được thay đổi qua $item->save()/update() (Eloquent event thật) — các lệnh
        // WarehouseItem::whereKey($id)->increment('quantity', $delta) mà
        // WarehouseStock{In,Out,Check}Item dùng nội bộ là query builder increment(), KHÔNG bắn
        // Eloquent event, nên hoàn toàn không có nguy cơ ghi trùng lặp với sổ nhật ký nhập/xuất/kiểm
        // kê đã có. Dùng updated() (sau khi UPDATE đã chạy thành công) thay vì updating() để chỉ ghi
        // log cho thay đổi THẬT SỰ đã lưu — updated() vẫn đọc được getOriginal() đúng giá trị CŨ vì
        // Eloquent chỉ syncOriginal() sau khi event 'updated' đã bắn xong.
        static::updated(function (WarehouseItem $item) {
            if (! $item->wasChanged('quantity')) {
                return;
            }

            $item->adjustments()->create([
                'partner_id'   => $item->partner_id,
                'old_quantity' => (float) $item->getOriginal('quantity'),
                'new_quantity' => (float) $item->quantity,
                'difference'   => round((float) $item->quantity - (float) $item->getOriginal('quantity'), 2),
                'created_by'   => auth()->id(),
            ]);
        });
    }

    protected $fillable = [
        'partner_id',
        'sku',
        'name',
        'warehouse_category_id',
        'warehouse_unit_id',
        'quantity',
        'min_quantity',
        'unit_price',
        'description',
        'status',
    ];

    protected $casts = [
        // 'float' thay vì 'decimal:2' — xem giải thích ở WarehouseStockIn::$casts.
        'quantity'     => 'float',
        'min_quantity' => 'float',
        'unit_price'   => 'float',
        'status'       => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(WarehouseCategory::class, 'warehouse_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(WarehouseUnit::class, 'warehouse_unit_id');
    }

    public function stockInItems(): HasMany
    {
        return $this->hasMany(WarehouseStockInItem::class);
    }

    public function stockOutItems(): HasMany
    {
        return $this->hasMany(WarehouseStockOutItem::class);
    }

    public function stockCheckItems(): HasMany
    {
        return $this->hasMany(WarehouseStockCheckItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(WarehouseItemAdjustment::class);
    }

    // Sổ nhật ký biến động tồn kho (nhập + xuất + kiểm kê gộp chung, sắp theo thời gian) — xem
    // WarehouseStockMovement, dùng cho tab "Lịch sử biến động" trên trang sửa vật tư.
    public function movements(): HasMany
    {
        return $this->hasMany(WarehouseStockMovement::class, 'warehouse_item_id');
    }

    public function isLowStock(): bool
    {
        return $this->min_quantity > 0 && $this->quantity <= $this->min_quantity;
    }
}
