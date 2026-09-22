<?php

namespace Tests\Feature\Minihouse;

use App\Models\Province;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Bug thật đã gặp trên production (2026-09-22): lưu Building (Building::saved() luôn chạy lại
// syncProvinceLink(), kể cả khi save() chỉ để ghi field KHÔNG liên quan tới tỉnh/thành — xem
// ContractDocumentService::update() gọi $building->update(['owner_id_card_issued_date' => ...]))
// ném 500 SQLSTATE 23000 "Duplicate entry ... for key cms_provinces_slug_unique" nếu bảng provinces
// đã có sẵn 1 dòng CÙNG SLUG hoá nhưng KHÁC chữ với Building->province (VD khoảng trắng/viết hoa/
// tiền tố "TP." khác nhau) — Province::firstOrCreate(['name' => ...]) chỉ tra theo NAME, Laravel's
// createOrFirst() tự bắt lỗi trùng rồi thử lại CŨNG chỉ tra theo NAME, không bao giờ khớp được dòng
// đã có (chỉ khớp SLUG) nên ném lại đúng exception gốc. Khoá lại: save() Building vẫn PHẢI thành
// công, tự dùng lại dòng Province đã có (không tạo trùng) khi rơi vào đúng tình huống này.
class BuildingProvinceSlugCollisionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_saving_building_reuses_existing_province_with_same_slug_but_different_name(): void
    {
        // Dòng có sẵn — tên KHÁC HẲN (không khớp where('name', ...)) nhưng slug được set THẲNG trùng
        // với slug mà Str::slug() sẽ tạo ra từ tên Building sắp lưu — mô phỏng đúng tình huống thật
        // (2 cách viết khác nhau của cùng 1 tỉnh/thành, VD "TP. Hồ Chí Minh" vs "Hồ Chí Minh", cùng
        // slug hoá ra 1 giá trị) mà không phụ thuộc dữ liệu tỉnh/thành thật đã seed sẵn.
        $provinceName = 'Test Collision Province ' . uniqid();
        $slug = Str::slug($provinceName);
        $existing = Province::create(['name' => 'Tên hoàn toàn khác ' . uniqid(), 'slug' => $slug]);

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create([
            'zone_id' => $zone->id, 'name' => 'B', 'address' => 'a',
            'province' => $provinceName,
        ]);

        // Không ném exception (test tự fail nếu save() phía trên đã ném) — và KHÔNG tạo thêm dòng
        // Province mới nào (vẫn đúng 1 dòng, dùng lại đúng dòng cũ theo slug).
        $this->assertSame(1, Province::where('slug', $slug)->count());
        $this->assertSame($existing->id, Province::where('slug', $slug)->first()->id);

        // Save() lại lần nữa vì lý do KHÁC HẲN (2 field CCCD, không đụng tỉnh/thành) — vẫn không được
        // ném lỗi, đúng y hệt bug thật gặp qua ContractDocumentService::update().
        $building->update(['owner_id_card_issued_date' => '2021-03-15']);

        $this->assertSame(1, Province::where('slug', $slug)->count());
    }
}
