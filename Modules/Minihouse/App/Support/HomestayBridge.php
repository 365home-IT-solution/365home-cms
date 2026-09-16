<?php

namespace Modules\Minihouse\App\Support;

use Modules\Product\App\Models\RoomType;

// Hằng số DÙNG CHUNG cho việc gộp Phòng/Toà nhà MiniHouse vào products/categories của Home (đồng
// nhất kiến trúc theo yêu cầu khách hàng) — xem kế hoạch đầy đủ trong lịch sử trao đổi + migration
// 2026_09_17_000001_seed_minihouse_internal_partner_and_room_type.php.
//
// PARTNER_ID: 1 đối tác (partners) NỘI BỘ duy nhất, đại diện cho "MiniHouse" trong hệ thống đối tác
// của Home — MiniHouse không có khái niệm nhiều đối tác thật (dùng toà nhà/khu vực làm ranh giới
// quyền, xem ActiveBuildingScope), nhưng products.partner_id/categories.partner_id LÀ CỘT BẮT BUỘC
// nên mọi phòng/chi nhánh MiniGate tạo ra đều phải gắn vào ĐÚNG 1 giá trị cố định này.
//
// ROOM_TYPE_SLUG: room_types.slug của loại phòng "cho thuê dài hạn kiểu MiniHouse" — dùng để:
//  (a) gắn vào products.room_type_id khi tạo phòng MiniHouse,
//  (b) làm điều kiện loại trừ trong Global Scope trên Product (xem
//      Modules\Product\App\Models\Product::booted(), scope 'exclude_minihouse') để phòng MiniHouse
//      KHÔNG BAO GIỜ lọt vào luồng đặt phòng ngắn hạn/tìm kiếm/bảng giá của Home.
//
// 2 hằng số này là chuỗi CỐ ĐỊNH (không tự sinh ngẫu nhiên mỗi lần seed) để mọi migration/service
// sau này tham chiếu lại đúng 1 bản ghi duy nhất, tránh tạo trùng khi seed chạy lại nhiều lần trên
// nhiều môi trường (migration seed dùng firstOrCreate theo đúng các giá trị này, xem migration seed).
class HomestayBridge
{
    public const PARTNER_ID = '00000000-0000-0000-0000-000000000001';

    public const ROOM_TYPE_SLUG = RoomType::MINIHOUSE_SLUG;
}
