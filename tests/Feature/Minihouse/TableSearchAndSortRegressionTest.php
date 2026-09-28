<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Tests\TestCase;

// Room::code và Building::address là ACCESSOR đọc từ cột khác/bảng phụ (Room::code -> cột "name" trên
// chính bảng products; Building::address -> bảng minihouse_building_settings), KHÔNG PHẢI cột thật tên
// "code"/"address". Trước đây nhiều bảng gọi ->searchable()/->sortable() mặc định trên các cột này (và
// trên quan hệ "room.code"/"contract.room.code"), khiến Filament sinh thẳng "WHERE code LIKE .."/
// "ORDER BY code" và MySQL báo "Unknown column" (500) ngay khi người dùng gõ vào ô tìm kiếm hoặc bấm
// sắp xếp cột đó — xem Modules\Minihouse\App\Filament\Support\RoomCodeSearch (nơi sửa, dùng
// ->searchable(query: ...)/->sortable(query: ...) trỏ đúng cột/bảng thật).
class TableSearchAndSortRegressionTest extends TestCase
{
    public function test_searching_and_sorting_by_room_code_or_building_address_does_not_500(): void
    {
        $this->withoutExceptionHandling();
        auth()->shouldUse('web');
        $this->actingAs(User::role('super_admin')->first(), 'web');

        $this->get('/minihouse/admin/rooms?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/rooms?tableSortColumn=code&tableSortDirection=asc')->assertOk();
        $this->get('/minihouse/admin/buildings?tableSearch=an binh')->assertOk();
        $this->get('/minihouse/admin/contracts?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/contracts?tableSortColumn=room.code&tableSortDirection=asc')->assertOk();
        $this->get('/minihouse/admin/reminders?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/tenants?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/tenant-feedbacks?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/panorama-scenes?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/invoices?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/invoices?tableSortColumn=contract.room.code&tableSortDirection=asc')->assertOk();
        $this->get('/minihouse/admin/transactions?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/residence-declarations?tableSearch=a01')->assertOk();
        $this->get('/minihouse/admin/residence-declarations?tableSortColumn=contract.room.code&tableSortDirection=asc')->assertOk();
    }
}
