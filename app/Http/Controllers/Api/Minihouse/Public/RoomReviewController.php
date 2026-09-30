<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Public;

use App\Http\Controllers\Api\RatingController;
use Modules\Minihouse\App\Models\Room;
use Modules\Product\App\Models\Product;

// NHẬN XÉT PHÒNG MiniHouse — dành cho người xem phòng TRƯỚC KHI thuê (trang chi tiết phòng /
// app tìm phòng). KHÁC HẲN "Phản hồi khách thuê" trong Portal (TenantFeedback): đó là khách ĐÃ thuê
// báo cáo tình trạng/sự cố cho chủ nhà, không công khai. Hai nghiệp vụ không dùng chung dữ liệu.
//
// Dùng lại đúng hệ thống đánh giá phòng Homestay (bảng room_ratings, ảnh đính kèm, phản hồi của quản
// trị) — request/response y hệt Api\RatingController, chỉ đổi tập phòng hợp lệ: phòng MiniHouse đang
// "Trống" thuộc toà đang bật (cùng điều kiện với Api\Minihouse\Public\RoomController::show()).
// Khách đăng nhập bằng tài khoản khách hàng của 365 Home (Customer), giống đánh giá Homestay.
class RoomReviewController extends RatingController
{
    protected function findRoom(string $roomId): ?Product
    {
        return Room::query()
            ->available()
            ->whereHas('building', fn ($q) => $q->where('status', true))
            ->find($roomId);
    }
}
