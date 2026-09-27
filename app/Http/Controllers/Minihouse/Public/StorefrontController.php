<?php

declare(strict_types=1);

namespace App\Http\Controllers\Minihouse\Public;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\BladeThemeV1\Http\Controllers\BladeThemeV1Controller;
use Modules\Minihouse\App\Models\Room;

// Trang công khai (không cần đăng nhập) giới thiệu phòng MiniHouse (cho thuê THEO THÁNG) ngay trên
// giao diện Home — HOÀN TOÀN TÁCH khỏi luồng tìm phòng/đặt phòng ngắn hạn của Home
// (Modules\BladeThemeV1\Http\Controllers\BladeThemeV1Controller::searchProduct()/productDetail()):
// không dùng chung route "/{type}/..." (Product.room_type_id của MiniHouse cố tình bị loại khỏi
// Product::exclude_minihouse global scope — xem Product::booted()), không có lịch/giá theo đêm,
// không có nút "Đặt phòng" — chỉ có nút "Liên hệ tư vấn" (gửi lead qua RentalInquiryController có
// sẵn), vì hợp đồng MiniHouse luôn do nhân viên tạo tay sau khi chốt với khách, không có khái niệm
// thanh toán/đặt phòng online ngay như Home.
//
// Dùng lại ĐÚNG query Room::available() (chỉ phòng đang "Trống") + building đang bật, giống hệt
// App\Http\Controllers\Api\Minihouse\Public\RoomController (API cho app di động) — 2 nơi phải luôn
// thấy CÙNG 1 tập phòng, không được lệch nhau.
//
// Kế thừa BladeThemeV1Controller (KHÔNG dùng chung method nào của nó, chỉ dùng constructor) để lấy
// đúng $primaryColor/$primaryColorRgb/$heavyPrimaryColor/$lightPrimaryColor — layouts.master.blade.php
// (layout dùng chung toàn site) bắt buộc phải có 4 biến này, mọi trang khác của Home đều truyền qua
// đúng cách này (xem BladeThemeV1Controller::home()).
class StorefrontController extends BladeThemeV1Controller
{
    // GET /minihouse
    public function index(Request $request): View
    {
        // KHÔNG có form lọc thủ công (search/khoảng giá/chọn toà) — Homestay thật không có kiểu lọc
        // này ở trang danh sách, chỉ dùng thanh tìm kiếm theo giờ/ngày ở header. Vẫn giữ lọc theo
        // building_id qua query string vì breadcrumb ở trang chi tiết ("Tên toà nhà") trỏ về đây kèm
        // tham số này để quay lại đúng danh sách của toà đó.
        $query = Room::query()
            ->available()
            ->whereHas('building', fn ($q) => $q->where('status', true))
            ->with(['building', 'amenities:id,name,image']);

        if ($request->filled('building_id')) {
            $query->where('building_id', $request->string('building_id'));
        }

        $rooms = $query->orderBy('price')->paginate(12)->withQueryString();

        // Bản đồ bên phải — CÙNG thư viện Leaflet + OpenStreetMap (js/leaflet.min.js,
        // css/leaflet.min.css) trang tìm kiếm Homestay thật đang dùng, chỉ khác là tự dựng marker
        // tĩnh 1 lần ở đây thay vì gọi API + tự tải lại theo bộ lọc như Home (search-map.min.js) —
        // vẫn nằm trong đúng phạm vi 1 phòng/1 lần tải, không cần bộ máy real-time đó cho MiniHouse.
        // Toạ độ lấy từ PHÒNG (Room.latitude/longitude — cột thật của products). Mỗi marker mang
        // theo TOÀN BỘ danh sách phòng của toà (không chỉ phòng rẻ nhất) để popup có thể lướt qua
        // từng phòng (mũi tên trái/phải) khi 1 địa điểm có nhiều phòng — ĐÚNG hành vi buildPopupHtml()
        // + __bpopNav() của bản đồ Homestay thật (public/js/search-map.js). Toà nào chưa nhập toạ độ
        // thì không có ghim, không lỗi trang.
        $mapMarkers = $rooms->getCollection()
            ->groupBy('building_id')
            ->map(function ($buildingRooms, $buildingId) {
                $withCoords = $buildingRooms->filter(fn ($r) => $r->latitude && $r->longitude)->sortBy('price')->values();

                if ($withCoords->isEmpty()) {
                    return null;
                }

                return [
                    'buildingId' => (string) $buildingId,
                    'lat'        => (float) $withCoords->first()->latitude,
                    'lng'        => (float) $withCoords->first()->longitude,
                    'name'       => $withCoords->first()->building?->name,
                    'minPrice'   => (float) $withCoords->min('price'),
                    'rooms'      => $withCoords->map(fn ($r) => [
                        'code'  => $r->code,
                        'price' => (float) $r->price,
                        'photo' => $r->photos[0] ?? null,
                        'url'   => url('/minihouse/' . $r->slug),
                    ])->all(),
                ];
            })
            ->filter()
            ->values();

        return view('minihouse.public.index', [
            'rooms'             => $rooms,
            'mapMarkers'        => $mapMarkers,
            'primaryColor'      => $this->primaryColor,
            'primaryColorRgb'   => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'seoData'           => [
                'seo_title'       => 'Cho thuê phòng trọ theo tháng — MiniHouse | 365HOME',
                'seo_description' => 'Tìm phòng trọ cho thuê dài hạn theo tháng: đầy đủ tiện nghi, giá tốt, nhiều khu vực. Liên hệ tư vấn miễn phí ngay hôm nay.',
            ],
        ]);
    }

    // GET /minihouse/{slug}
    public function show(string $slug): View
    {
        $room = Room::query()
            ->available()
            ->whereHas('building', fn ($q) => $q->where('status', true))
            ->with(['building.zone', 'amenities:id,name,image'])
            ->where('slug', $slug)
            ->firstOrFail();

        $tourScenes = $room->panoramaScenes()->where('is_published', true)->exists();
        $buildingTour = ! $tourScenes && $room->building->panoramaScenes()->where('is_published', true)->exists();

        return view('minihouse.public.show', [
            'room'              => $room,
            'tourUrl'           => $tourScenes
                ? route('minihouse.tour.scene', [$room->building_id, $room->panoramaScenes()->where('is_published', true)->first()->id])
                : ($buildingTour ? route('minihouse.tour.show', $room->building_id) : null),
            'primaryColor'      => $this->primaryColor,
            'primaryColorRgb'   => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'seoData'           => [
                'seo_title'       => 'Phòng ' . $room->code . ' — ' . $room->building->name . ' | Cho thuê theo tháng',
                'seo_description' => 'Phòng ' . $room->code . ' tại ' . $room->building->name . ', ' . $room->building->address . ' — giá ' . number_format((float) $room->price, 0, ',', '.') . 'đ/tháng. Liên hệ tư vấn ngay.',
            ],
        ]);
    }
}
