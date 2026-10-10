<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Toạ độ chính xác cấp chi nhánh (category_type = product) — dùng cho "geo" của schema
// LodgingBusiness (xem App\Support\BranchLodgingSchema). Trước đây chỉ có toạ độ cấp tỉnh
// (provinces.lat/lng) nên schema không gắn geo. Điền sẵn toạ độ 5 chi nhánh đang hoạt động theo
// slug; chi nhánh mới nhập ở trang "Chi tiết cơ sở" của đối tác.
return new class extends Migration
{
    private const BRANCH_COORDINATES = [
        '252-xuan-thuy-an-binh-can-tho' => [10.0217964, 105.7445886],
        '254-xuan-thuy-an-binh-can-tho' => [10.0217964, 105.7445886],
        '89-xuan-thuy-an-binh-can-tho'  => [10.0217964, 105.7476907],
        // Pinus
        '385v314b-385-d-tran-nam-phu'   => [10.0327654, 105.7616941],
        // Về nhà Staycation
        '515-30-thang-4'                => [10.01149, 105.7609116],
    ];

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('checkout_time');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        foreach (self::BRANCH_COORDINATES as $slug => [$latitude, $longitude]) {
            DB::table('categories')
                ->where('slug', $slug)
                ->where('category_type', 'product')
                ->update(['latitude' => $latitude, 'longitude' => $longitude]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
