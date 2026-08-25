<?php

declare(strict_types=1);

namespace Modules\Warehouse\Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;
use Modules\Warehouse\App\Models\WarehouseCategory;
use Modules\Warehouse\App\Models\WarehouseItem;
use Modules\Warehouse\App\Models\WarehouseUnit;

// Danh mục vật tư khởi tạo dành riêng cho vận hành phòng nghỉ / khách sạn (housekeeping, minibar,
// lễ tân, bảo trì...). Seed lại cho MỖI đối tác đang có trong hệ thống (mỗi đối tác có kho riêng)
// để dữ liệu sẵn sàng dùng ngay, không cần đối tác tự nhập tay từ đầu.
class WarehouseItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = $this->items();

        $partners = Partner::all();

        if ($partners->isEmpty()) {
            // Không có đối tác nào (môi trường mới cài) — seed dùng chung, super_admin vẫn thấy được.
            $this->seedFor(null, $items);

            return;
        }

        foreach ($partners as $partner) {
            $this->seedFor($partner->id, $items);
        }
    }

    private function seedFor(?string $partnerId, array $items): void
    {
        $categoryCache = [];
        $unitCache     = [];

        foreach ($items as $item) {
            $categoryId = $categoryCache[$item['category']] ??= WarehouseCategory::firstOrCreate(
                ['partner_id' => $partnerId, 'name' => $item['category']]
            )->id;

            $unitId = $unitCache[$item['unit']] ??= WarehouseUnit::firstOrCreate(
                ['partner_id' => $partnerId, 'name' => $item['unit']]
            )->id;

            WarehouseItem::firstOrCreate(
                [
                    'partner_id' => $partnerId,
                    'sku'        => $item['sku'],
                ],
                [
                    'name'                   => $item['name'],
                    'warehouse_category_id'  => $categoryId,
                    'warehouse_unit_id'      => $unitId,
                    'quantity'               => $item['quantity'],
                    'min_quantity'           => $item['min_quantity'],
                    'unit_price'             => $item['unit_price'],
                    'status'                 => true,
                ]
            );
        }
    }

    private function items(): array
    {
        return [
            // ── Buồng phòng — Vải & giường ─────────────────────────────
            ['sku' => 'HK-001', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Khăn tắm lớn',        'unit' => 'cái', 'quantity' => 100, 'min_quantity' => 20, 'unit_price' => 85000],
            ['sku' => 'HK-002', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Khăn mặt',            'unit' => 'cái', 'quantity' => 100, 'min_quantity' => 20, 'unit_price' => 35000],
            ['sku' => 'HK-003', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Khăn chân',           'unit' => 'cái', 'quantity' => 60,  'min_quantity' => 15, 'unit_price' => 45000],
            ['sku' => 'HK-004', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Ga trải giường',      'unit' => 'cái', 'quantity' => 60,  'min_quantity' => 15, 'unit_price' => 150000],
            ['sku' => 'HK-005', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Vỏ gối',              'unit' => 'cái', 'quantity' => 100, 'min_quantity' => 20, 'unit_price' => 55000],
            ['sku' => 'HK-006', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Ruột gối',            'unit' => 'cái', 'quantity' => 40,  'min_quantity' => 10, 'unit_price' => 120000],
            ['sku' => 'HK-007', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Chăn / mền',          'unit' => 'cái', 'quantity' => 40,  'min_quantity' => 10, 'unit_price' => 350000],
            ['sku' => 'HK-008', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Vỏ chăn / mền',       'unit' => 'cái', 'quantity' => 40,  'min_quantity' => 10, 'unit_price' => 180000],
            ['sku' => 'HK-009', 'category' => 'Buồng phòng - Vải & giường', 'name' => 'Áo choàng tắm',       'unit' => 'cái', 'quantity' => 30,  'min_quantity' => 10, 'unit_price' => 200000],

            // ── Buồng phòng — Đồ dùng phòng tắm (amenities) ────────────
            ['sku' => 'AM-001', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Xà phòng tắm mini',    'unit' => 'bánh', 'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 5000],
            ['sku' => 'AM-002', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Dầu gội đầu mini',      'unit' => 'chai', 'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 6000],
            ['sku' => 'AM-003', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Sữa tắm mini',          'unit' => 'chai', 'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 6000],
            ['sku' => 'AM-004', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Bàn chải đánh răng',    'unit' => 'bộ',   'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 4000],
            ['sku' => 'AM-005', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Kem đánh răng mini',    'unit' => 'tuýp', 'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 4000],
            ['sku' => 'AM-006', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Dao cạo râu dùng 1 lần','unit' => 'cái',  'quantity' => 150, 'min_quantity' => 30, 'unit_price' => 3000],
            ['sku' => 'AM-007', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Lược chải tóc',         'unit' => 'cái',  'quantity' => 150, 'min_quantity' => 30, 'unit_price' => 3000],
            ['sku' => 'AM-008', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Mũ tắm (bịt tóc)',      'unit' => 'cái',  'quantity' => 150, 'min_quantity' => 30, 'unit_price' => 2000],
            ['sku' => 'AM-009', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Tăm bông (bộ)',         'unit' => 'gói',  'quantity' => 100, 'min_quantity' => 20, 'unit_price' => 3000],
            ['sku' => 'AM-010', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Giấy vệ sinh',          'unit' => 'cuộn', 'quantity' => 150, 'min_quantity' => 30, 'unit_price' => 8000],
            ['sku' => 'AM-011', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Bông tẩy trang',        'unit' => 'gói',  'quantity' => 80,  'min_quantity' => 20, 'unit_price' => 10000],
            ['sku' => 'AM-012', 'category' => 'Buồng phòng - Đồ dùng phòng tắm', 'name' => 'Dép đi trong phòng',    'unit' => 'đôi',  'quantity' => 150, 'min_quantity' => 30, 'unit_price' => 7000],

            // ── Buồng phòng — Hóa chất & dụng cụ vệ sinh ───────────────
            ['sku' => 'CL-001', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Nước lau sàn',            'unit' => 'chai', 'quantity' => 30, 'min_quantity' => 5,  'unit_price' => 45000],
            ['sku' => 'CL-002', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Nước tẩy rửa toilet',     'unit' => 'chai', 'quantity' => 30, 'min_quantity' => 5,  'unit_price' => 35000],
            ['sku' => 'CL-003', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Nước lau kính',           'unit' => 'chai', 'quantity' => 20, 'min_quantity' => 5,  'unit_price' => 40000],
            ['sku' => 'CL-004', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Nước rửa tay',            'unit' => 'chai', 'quantity' => 40, 'min_quantity' => 10, 'unit_price' => 30000],
            ['sku' => 'CL-005', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Túi đựng rác',            'unit' => 'kg',   'quantity' => 30, 'min_quantity' => 5,  'unit_price' => 60000],
            ['sku' => 'CL-006', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Khăn lau đa năng',        'unit' => 'cái',  'quantity' => 60, 'min_quantity' => 15, 'unit_price' => 15000],
            ['sku' => 'CL-007', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Chổi quét nhà',           'unit' => 'cái',  'quantity' => 15, 'min_quantity' => 3,  'unit_price' => 40000],
            ['sku' => 'CL-008', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Cây lau nhà',             'unit' => 'cái',  'quantity' => 15, 'min_quantity' => 3,  'unit_price' => 90000],
            ['sku' => 'CL-009', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Nước xịt phòng / khử mùi','unit' => 'chai', 'quantity' => 20, 'min_quantity' => 5,  'unit_price' => 55000],
            ['sku' => 'CL-010', 'category' => 'Buồng phòng - Hóa chất & dụng cụ', 'name' => 'Găng tay cao su',         'unit' => 'đôi',  'quantity' => 40, 'min_quantity' => 10, 'unit_price' => 10000],

            // ── Minibar / Đồ uống ────────────────────────────────────────
            ['sku' => 'MB-001', 'category' => 'Minibar / Đồ uống', 'name' => 'Nước suối chai 500ml',   'unit' => 'chai', 'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 5000],
            ['sku' => 'MB-002', 'category' => 'Minibar / Đồ uống', 'name' => 'Trà túi lọc',             'unit' => 'gói',  'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 2000],
            ['sku' => 'MB-003', 'category' => 'Minibar / Đồ uống', 'name' => 'Cà phê hòa tan',          'unit' => 'gói',  'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 3000],
            ['sku' => 'MB-004', 'category' => 'Minibar / Đồ uống', 'name' => 'Đường gói',               'unit' => 'gói',  'quantity' => 200, 'min_quantity' => 50, 'unit_price' => 1000],
            ['sku' => 'MB-005', 'category' => 'Minibar / Đồ uống', 'name' => 'Sữa tươi hộp nhỏ',        'unit' => 'hộp',  'quantity' => 100, 'min_quantity' => 20, 'unit_price' => 8000],
            ['sku' => 'MB-006', 'category' => 'Minibar / Đồ uống', 'name' => 'Ly giấy dùng 1 lần',      'unit' => 'cái',  'quantity' => 300, 'min_quantity' => 50, 'unit_price' => 1000],

            // ── Lễ tân / Văn phòng phẩm ──────────────────────────────────
            ['sku' => 'OF-001', 'category' => 'Lễ tân / Văn phòng phẩm', 'name' => 'Giấy in A4',       'unit' => 'ram', 'quantity' => 20, 'min_quantity' => 5,  'unit_price' => 75000],
            ['sku' => 'OF-002', 'category' => 'Lễ tân / Văn phòng phẩm', 'name' => 'Bút bi',            'unit' => 'cái', 'quantity' => 50, 'min_quantity' => 10, 'unit_price' => 3000],
            ['sku' => 'OF-003', 'category' => 'Lễ tân / Văn phòng phẩm', 'name' => 'Sổ tay ghi chú',    'unit' => 'cuốn','quantity' => 30, 'min_quantity' => 5,  'unit_price' => 15000],
            ['sku' => 'OF-004', 'category' => 'Lễ tân / Văn phòng phẩm', 'name' => 'Phong bì thư',      'unit' => 'cái', 'quantity' => 100,'min_quantity' => 20, 'unit_price' => 1000],
            ['sku' => 'OF-005', 'category' => 'Lễ tân / Văn phòng phẩm', 'name' => 'Túi giặt ủi (đồ khách)', 'unit' => 'cái', 'quantity' => 100, 'min_quantity' => 20, 'unit_price' => 2000],

            // ── Bảo trì / Kỹ thuật ───────────────────────────────────────
            ['sku' => 'MT-001', 'category' => 'Bảo trì / Kỹ thuật', 'name' => 'Bóng đèn LED',       'unit' => 'cái', 'quantity' => 40, 'min_quantity' => 10, 'unit_price' => 35000],
            ['sku' => 'MT-002', 'category' => 'Bảo trì / Kỹ thuật', 'name' => 'Pin AA',              'unit' => 'viên','quantity' => 60, 'min_quantity' => 15, 'unit_price' => 5000],
            ['sku' => 'MT-003', 'category' => 'Bảo trì / Kỹ thuật', 'name' => 'Pin AAA',             'unit' => 'viên','quantity' => 60, 'min_quantity' => 15, 'unit_price' => 5000],
            ['sku' => 'MT-004', 'category' => 'Bảo trì / Kỹ thuật', 'name' => 'Băng keo hai mặt',    'unit' => 'cuộn','quantity' => 15, 'min_quantity' => 3,  'unit_price' => 20000],
            ['sku' => 'MT-005', 'category' => 'Bảo trì / Kỹ thuật', 'name' => 'Dây rút nhựa',        'unit' => 'gói', 'quantity' => 10, 'min_quantity' => 2,  'unit_price' => 25000],
        ];
    }
}
