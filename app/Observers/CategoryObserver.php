<?php

namespace App\Observers;

use App\Support\GeneratesImagePresets;
use App\Support\ResizesOversizedImage;
use Illuminate\Support\Facades\Storage;
use Modules\AccessCode\Entities\AccessCode;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Category\Entities\Category;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

class CategoryObserver
{
    // Khi admin gán/đổi "Đối tác sở hữu" cho 1 chi nhánh (category_type=product, xem
    // CategoryForm::partnerInput()), TOÀN BỘ dữ liệu đã gắn vào chi nhánh đó — phòng (Product),
    // lịch đặt (Order), mã cổng (AccessCode) — trực tiếp hoặc qua danh mục con (một số đối tác tổ
    // chức category 2 cấp: chi nhánh → danh mục phòng con, xem ghi chú trong OrderForm.php) — phải
    // được cập nhật LẠI partner_id cho khớp NGAY LẬP TỨC.
    //
    // Nếu không cascade: Product/Order/AccessCode.partner_id là các cột RIÊNG, chỉ tự gán 1 LẦN lúc
    // TẠO bản ghi theo tài khoản đang đăng nhập (xem BelongsToPartner::bootBelongsToPartner()),
    // hoàn toàn không liên quan đến category.partner_id. Category hiện với tài khoản đối tác dựa
    // vào category.partner_id (CategoryResource), còn Product/Order/AccessCode lại lọc riêng theo
    // partner_id CỦA CHÍNH NÓ (BelongsToPartner global scope) — 2 nơi không tự đồng bộ, nên gán đối
    // tác cho 1 chi nhánh ĐÃ CÓ SẴN dữ liệu (tạo trước đó, mang partner_id cũ/null) khiến tài khoản
    // đối tác thấy được chi nhánh nhưng KHÔNG thấy phòng/lịch đặt/mã cổng bên trong để quản lý —
    // "có chi nhánh nhưng không có gì bên trong" thay vì phải "có TOÀN BỘ hoặc không có gì".
    public function saved(Category $category): void
    {
        if (filled($category->image) && ($category->wasRecentlyCreated || $category->wasChanged('image'))) {
            $path = Storage::disk('public')->path($category->image);
            ResizesOversizedImage::apply($path);
            GeneratesImagePresets::apply($path);

            $dimensions = @getimagesize($path);
            if ($dimensions) {
                $category->updateQuietly(['image_width' => $dimensions[0], 'image_height' => $dimensions[1]]);
            }
        }

        if ($category->category_type !== 'product' || blank($category->partner_id)) {
            return;
        }

        // CỐ Ý không giới hạn "chỉ chạy khi partner_id vừa đổi" (wasChanged) — chạy lại ở MỌI lần
        // lưu chi nhánh để tự "chữa lành" luôn cả những chi nhánh ĐÃ BỊ LỆCH từ trước khi có
        // observer này (chỉ cần admin mở chi nhánh đó lên bấm Lưu lại, không cần đổi gì, là toàn bộ
        // phòng/lịch đặt/mã cổng bên trong tự đồng bộ về đúng đối tác). Chạy lại khi KHÔNG có gì
        // lệch chỉ là các câu UPDATE không khớp dòng nào, chi phí không đáng kể.
        $partnerId   = $category->partner_id;
        $categoryIds = $category->children()->pluck('id')->push($category->id);

        // Khu vực con (chính bảng categories) — PHẢI cascade lại cột partner_id của chính nó,
        // không chỉ của Product/Order/AccessCode bên dưới. Thiếu dòng này khiến khu vực con tạo
        // trước observer/migration backfill partner_id (hoặc lệch vì lý do khác) mãi mãi có
        // partner_id sai/null, làm mọi query lọc theo category.partner_id (CategoryResource,
        // CategoryController::visibleCategoriesQuery, API tree...) trả về chi nhánh cha đúng
        // nhưng KHÔNG BAO GIỜ thấy khu vực con — dù allowedCategoryIds() đã cho phép đúng id đó.
        Category::whereIn('id', $categoryIds)->update(['partner_id' => $partnerId]);

        // Product gắn qua bảng pivot categorizables (morphToMany), không có cột category_id trực
        // tiếp — phải whereHas qua quan hệ categories().
        $productCount = Product::withoutGlobalScope('partner')
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categoryIds))
            ->update(['partner_id' => $partnerId]);

        // Order và AccessCode có cột category_id trực tiếp (không qua pivot).
        $orderCount = Order::withoutGlobalScope('partner')
            ->whereIn('category_id', $categoryIds)
            ->update(['partner_id' => $partnerId]);

        $accessCodeCount = AccessCode::withoutGlobalScope('partner')
            ->whereIn('category_id', $categoryIds)
            ->update(['partner_id' => $partnerId]);

        // Các câu UPDATE hàng loạt ở trên dùng query builder (::whereIn()->update()) nên KHÔNG bắn
        // Eloquent event — LogsAuditTrail gắn trên Product/Order/AccessCode hoàn toàn không thấy
        // được thay đổi này. Ghi 1 dòng log thủ công tóm tắt cascade, CHỈ khi thực sự có bản ghi bị
        // ảnh hưởng (tránh spam log mỗi lần admin mở chi nhánh lên bấm Lưu mà không có gì lệch).
        if (($productCount + $orderCount + $accessCodeCount) > 0) {
            AuditLogger::log(
                action: 'update',
                module: 'Category',
                record: $category,
                new: [
                    'partner_id_moi'        => $partnerId,
                    'so_phong_bi_anh_huong' => $productCount,
                    'so_booking_bi_anh_huong' => $orderCount,
                    'so_ma_khoa_bi_anh_huong' => $accessCodeCount,
                ],
                label: $category->name . ' — cascade đổi đối tác cho dữ liệu bên trong',
            );
        }

        // Dọn rác "quyền chi nhánh" (UserBranchPermission) của các tài khoản KHÔNG thuộc đối tác
        // MỚI này — thường phát sinh khi 1 chi nhánh đổi sang đối tác khác, nhưng quyền đã gán cho
        // tài khoản đối tác CŨ vẫn còn nguyên trong DB. Không gây rò rỉ dữ liệu (lớp partner_id
        // ngoài vẫn luôn chặn đúng), nhưng để lại "rác" gây khó hiểu khi xem lại bảng phân quyền
        // (tài khoản đối tác cũ vẫn có dòng quyền trỏ vào chi nhánh giờ thuộc đối tác khác).
        UserBranchPermission::whereIn('category_id', $categoryIds)
            ->whereHas('user', function ($query) use ($partnerId) {
                $query->where(function ($q) use ($partnerId) {
                    $q->whereNull('partner_id')->orWhere('partner_id', '!=', $partnerId);
                });
            })
            ->delete();
    }
}
