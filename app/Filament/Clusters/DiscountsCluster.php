<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

// Gộp 2 module trước đây tách rời (Modules\Promotion\...\PromotionResource "Ưu đãi khung giờ" và
// Modules\Coupon\...\CouponResource "Mã giảm giá") thành 1 mục menu duy nhất — chỉ gộp GIAO DIỆN
// quản trị (Filament Cluster: 1 nav item + sub-nav chuyển qua lại), KHÔNG đụng gì đến model/bảng
// dữ liệu/logic tính giá của 2 bên (vẫn tách riêng y nguyên như trước). Đăng ký cluster này chỉ
// cần gán `protected static ?string $cluster = self::class;` trên PromotionResource/CouponResource
// — Filament tự ẩn nav item riêng của từng resource, chỉ còn lại nav item của cluster.
class DiscountsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Khuyến mãi & Giảm giá';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $clusterBreadcrumb = 'Khuyến mãi & Giảm giá';
}
