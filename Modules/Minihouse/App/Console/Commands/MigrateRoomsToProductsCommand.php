<?php

namespace Modules\Minihouse\App\Console\Commands;

use App\Models\Province;
use App\Models\ProvinceBranch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Models\Amenity;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomAmenity;
use Modules\Product\App\Models\RoomAmenityAssign;
use Modules\Product\App\Models\RoomType;

// Giai đoạn 2 của kế hoạch gộp Phòng/Toà nhà MiniHouse vào products/categories — xem plan đầy đủ
// trong lịch sử trao đổi. Idempotent theo cột legacy_minihouse_building_id/legacy_minihouse_room_id
// (firstOrCreate) nên chạy lại nhiều lần an toàn, không tạo trùng. Bọc trong 1 transaction: lệch
// số dòng ở bước đối chiếu cuối sẽ tự rollback toàn bộ.
class MigrateRoomsToProductsCommand extends Command
{
    protected $signature = 'minihouse:migrate-to-products {--dry-run : Chỉ log, không ghi DB}';

    protected $description = 'Chuyển minihouse_buildings/minihouse_rooms/minihouse_amenities sang categories/products/RoomAmenity (Giai đoạn 2 kế hoạch gộp MiniHouse-Homestay)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $roomType = RoomType::where('slug', HomestayBridge::ROOM_TYPE_SLUG)->first();
        if (! $roomType) {
            $this->error('Chưa seed room_types "minihouse" — chạy Giai đoạn 1 (migrate) trước.');

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $buildingMap = $this->migrateBuildings();
            $roomMap     = $this->migrateRooms($roomType->id, $buildingMap);
            $this->migrateAmenities($roomMap);

            $this->assertCounts($buildingMap, $roomMap);

            if ($dryRun) {
                DB::rollBack();
                $this->info('Dry-run xong — đã rollback, không ghi gì thật.');

                return self::SUCCESS;
            }

            DB::commit();
            $this->info(sprintf(
                'Xong: %d toà nhà -> categories, %d phòng -> products.',
                count($buildingMap),
                count($roomMap)
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Lỗi, đã rollback toàn bộ: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<int, int> old building id => new category id */
    private function migrateBuildings(): array
    {
        $map = [];

        // withTrashed() CỐ Ý — toà đã xoá mềm vẫn phải có category tương ứng để giữ toàn vẹn khoá
        // ngoại cho các bảng con còn tham chiếu building_id của nó (Category không hỗ trợ xoá mềm,
        // nên dùng status=false làm trạng thái "ẩn" tương đương, phù hợp luôn với việc
        // Product::scopeActiveBranch() lọc theo categories.status khi hiển thị phòng Home).
        Building::withTrashed()->orderBy('id')->each(function (Building $building) use (&$map) {
            // KHÔNG dùng Category::firstOrCreate(['legacy_minihouse_building_id' => ...], [...]) —
            // cột này KHÔNG có trong Category::$fillable (cố tình, chỉ là cột tra cứu tạm cho đợt
            // gộp này, không muốn thêm vĩnh viễn vào $fillable của model gốc) nên gán qua mass-
            // assignment sẽ bị Laravel ÂM THẦM bỏ qua — tra bằng query thường rồi forceFill() riêng
            // cột đó.
            $category = Category::where('legacy_minihouse_building_id', $building->id)->first();

            if (! $category) {
                $category = new Category([
                    'name'          => $building->name,
                    'slug'          => $this->uniqueSlug($building->name),
                    'category_type' => 'product',
                    'parent_id'     => null,
                    'partner_id'    => HomestayBridge::PARTNER_ID,
                    'status'        => $building->deleted_at === null,
                    'description'   => $building->note,
                ]);
                $category->legacy_minihouse_building_id = $building->id;
                $category->save();
            }

            DB::table('minihouse_building_settings')->updateOrInsert(
                ['category_id' => $category->id],
                [
                    'zone_id'                              => $building->zone_id,
                    'address'                               => $building->address,
                    'province_name_raw'                     => $building->province,
                    'ward_raw'                               => $building->ward,
                    'electric_unit_price'                   => $building->electric_unit_price,
                    'water_unit_price'                      => $building->water_unit_price,
                    'owner_name'                             => $building->owner_name,
                    'owner_phone'                            => $building->owner_phone,
                    'owner_id_card_number'                   => $building->owner_id_card_number,
                    'owner_email'                            => $building->owner_email,
                    'owner_address'                          => $building->owner_address,
                    'owner_bank_bin'                         => $building->owner_bank_bin,
                    'owner_bank_name'                        => $building->owner_bank_name,
                    'owner_bank_account_number'               => $building->owner_bank_account_number,
                    'owner_bank_account_holder'               => $building->owner_bank_account_holder,
                    'payment_method'                          => $building->payment_method,
                    'payos_client_id'                         => $building->payos_client_id,
                    'payos_api_key'                           => $building->payos_api_key,
                    'payos_checksum_key'                      => $building->payos_checksum_key,
                    'momo_partner_code'                       => $building->momo_partner_code,
                    'momo_access_key'                         => $building->momo_access_key,
                    'momo_secret_key'                         => $building->momo_secret_key,
                    'vnpay_tmn_code'                          => $building->vnpay_tmn_code,
                    'vnpay_hash_secret'                       => $building->vnpay_hash_secret,
                    'payment_sandbox'                         => $building->payment_sandbox ?? false,
                    'billing_cycle_type'                      => $building->billing_cycle_type ?? 'calendar_month',
                    'payment_reminder_days_before'             => $building->payment_reminder_days_before,
                    'payment_reminder_repeat_days'             => $building->payment_reminder_repeat_days,
                    'fixed_due_day'                            => $building->fixed_due_day,
                    'contract_expiry_reminder_days_before'     => $building->contract_expiry_reminder_days_before,
                    'note'                                     => $building->note,
                    'created_at'                               => $building->created_at,
                    'updated_at'                               => now(),
                ]
            );

            $this->linkProvince($category, $building->province);

            $map[$building->id] = $category->id;
        });

        return $map;
    }

    private function linkProvince(Category $category, ?string $provinceName): void
    {
        if (! $provinceName) {
            return;
        }

        $province = Province::firstOrCreate(
            ['name' => $provinceName],
            ['slug' => Str::slug($provinceName)]
        );

        ProvinceBranch::firstOrCreate([
            'province_id'  => $province->id,
            'categorie_id' => $category->id,
        ], ['status' => true]);
    }

    /**
     * @param  array<int, int>  $buildingMap
     * @return array<int, string> old room id => new product UUID
     */
    private function migrateRooms(int $roomTypeId, array $buildingMap): array
    {
        $map = [];

        Room::withTrashed()->orderBy('id')->each(function (Room $room) use ($roomTypeId, $buildingMap, &$map) {
            $categoryId = $buildingMap[$room->building_id] ?? null;

            // Cùng lý do như Category ở migrateBuildings() — legacy_minihouse_room_id không nằm
            // trong Product::$fillable nên không thể gán qua firstOrCreate()/create() thông thường.
            $product = Product::where('legacy_minihouse_room_id', $room->id)->first();

            if (! $product) {
                $product = new Product([
                    'partner_id'     => HomestayBridge::PARTNER_ID,
                    'room_type_id'   => $roomTypeId,
                    'name'           => $room->code,
                    'slug'           => $this->uniqueProductSlug($room->code),
                    'room_area_sqm'  => $room->area,
                    'price'          => $room->price,
                    'styles'         => 2, // theo ngày — không dùng cho lịch đặt, chỉ để field bắt buộc hợp lệ
                    'is_activated'   => $room->status !== Room::STATUS_REPAIR && $room->deleted_at === null,
                    'is_in_stock'    => true,
                    'description'    => $room->note,
                ]);
                $product->legacy_minihouse_room_id = $room->id;
                $product->save();
            }

            if ($categoryId && ! $product->categories()->where('categories.id', $categoryId)->exists()) {
                $product->categories()->attach($categoryId);
            }

            DB::table('minihouse_room_details')->updateOrInsert(
                ['product_id' => $product->id],
                [
                    'floor'         => $room->floor,
                    'position_row'  => $room->position_row,
                    'position_col'  => $room->position_col,
                    'status'        => $room->status,
                    'photos'        => $room->photos ? json_encode($room->photos) : null,
                    'created_at'    => $room->created_at,
                    'updated_at'    => now(),
                ]
            );

            $map[$room->id] = $product->id;
        });

        return $map;
    }

    /** @param  array<int, string>  $roomMap */
    private function migrateAmenities(array $roomMap): void
    {
        $amenityMap = [];

        Amenity::query()->orderBy('id')->each(function (Amenity $amenity) use (&$amenityMap) {
            $new = RoomAmenity::firstOrCreate(
                ['partner_id' => HomestayBridge::PARTNER_ID, 'name' => $amenity->name],
                ['amenity_type' => 'minihouse', 'icon' => $amenity->image, 'status' => true, 'sort_order' => 0]
            );

            $amenityMap[$amenity->id] = $new->id;
        });

        DB::table('minihouse_room_amenity')->orderBy('room_id')->each(function ($row) use ($roomMap, $amenityMap) {
            $productId  = $roomMap[$row->room_id] ?? null;
            $amenityId  = $amenityMap[$row->amenity_id] ?? null;

            if (! $productId || ! $amenityId) {
                return;
            }

            RoomAmenityAssign::firstOrCreate(['room_id' => $productId, 'amenity_id' => $amenityId]);
        }, 200);
    }

    /**
     * @param  array<int, int>  $buildingMap
     * @param  array<int, string>  $roomMap
     */
    private function assertCounts(array $buildingMap, array $roomMap): void
    {
        $expectedBuildings = Building::withTrashed()->count();
        $expectedRooms     = Room::withTrashed()->count();

        if (count($buildingMap) !== $expectedBuildings) {
            throw new \RuntimeException("Lệch số toà nhà: mong {$expectedBuildings}, có " . count($buildingMap));
        }

        if (count($roomMap) !== $expectedRooms) {
            throw new \RuntimeException("Lệch số phòng: mong {$expectedRooms}, có " . count($roomMap));
        }
    }

    // KHÔNG loại trừ theo legacy id trong lúc kiểm tra trùng — tại thời điểm gọi hàm này,
    // firstOrCreate() CHƯA tạo ra bản ghi cho legacy id này (nếu đã có thì đâu cần sinh slug mới),
    // nên chỉ cần kiểm tra trùng "toàn cục" là đủ, tránh bug so sánh `!= NULL` luôn cho kết quả NULL
    // (không loại trừ được các category/product CHƯA từng gộp, có legacy id NULL) khiến trùng slug
    // vẫn lọt qua.
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'toa-nha';
        $slug = $base;
        $i    = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }

    private function uniqueProductSlug(?string $name): string
    {
        $base = Str::slug((string) $name) ?: 'phong';
        $slug = 'mh-' . $base;
        $i    = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = 'mh-' . $base . '-' . (++$i);
        }

        return $slug;
    }
}
