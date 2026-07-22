<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\TtlockAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\AccessCode\Entities\AccessCode;
use Modules\AuditLog\Entities\AuditLog;
use Modules\Category\Entities\Category;
use Modules\Employee\Entities\Department;
use Modules\Employee\Entities\Employee;
use Modules\Employee\Entities\Position;
use Modules\Payment\Entities\Order;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomAmenity;
use Modules\Product\App\Models\RoomService;
use Modules\Product\App\Models\RoomSpecial;
use Modules\Product\App\Models\RoomType;
use Modules\Promotion\App\Models\Coupon;
use Modules\Promotion\App\Models\Promotion;
use Spatie\Permission\Models\Role;

class PartnerSeeder extends Seeder
{
    // Tạo N đối tác mẫu, mỗi đối tác có đủ dữ liệu nghiệp vụ (≥2 bản ghi/loại) thuộc phạm vi đã
    // lọc theo partner_id — để kiểm tra toàn diện việc cô lập dữ liệu giữa các đối tác:
    // chi nhánh, phòng, nhân viên, coupon, khuyến mãi, đơn hàng, mã mở cửa, tài khoản TTLock, audit log.
    //
    // Tên các bản ghi nghiệp vụ (phòng/chi nhánh/coupon/dịch vụ...) KHÔNG gắn tên đối tác vào —
    // việc phân biệt dữ liệu của đối tác nào đã có badge màu riêng theo từng đối tác ở trang
    // super_admin (xem App\Filament\Support\PartnerTableHelpers), nên tên vẫn bình thường như một
    // đối tác thật tự đặt, không lộ ra ngoài trang khách hàng.
    //
    // Idempotent: chạy lại nhiều lần không tạo trùng (dùng firstOrCreate/updateOrCreate theo khoá tự nhiên).
    public function run(): void
    {
        $superAdmin = User::where('email', 'support@goldenbeeltd.vn')->first();

        // Phòng ban/chức vụ dùng chung cho toàn hệ thống (không thuộc riêng đối tác nào) — trước
        // đây đoạn seed nhân viên bên dưới giả định sẵn ID 1/2/3 đã tồn tại, nhưng KHÔNG có seeder
        // nào tạo ra 2 danh mục này cả, nên PartnerSeeder luôn crash lỗi khoá ngoại ngay tại đối
        // tác đầu tiên (kể cả trên database hiện tại đang dùng, không chỉ khi phục hồi DB cũ).
        // firstOrCreate theo slug để an toàn chạy lại nhiều lần.
        $departmentKinhDoanh = Department::firstOrCreate(['slug' => 'kinh-doanh'], ['name' => 'Kinh doanh', 'status' => true]);
        $departmentIT        = Department::firstOrCreate(['slug' => 'it'], ['name' => 'IT', 'status' => true]);
        $positionLeTan        = Position::firstOrCreate(['slug' => 'nhan-vien-le-tan'], ['name' => 'Nhân viên lễ tân', 'status' => true]);
        $positionNhanVien     = Position::firstOrCreate(['slug' => 'nhan-vien'], ['name' => 'Nhân viên', 'status' => true]);

        // Cùng lý do như Department/Position ở trên — đoạn seed phòng bên dưới cũng giả định sẵn
        // room_type_id 1/2 đã tồn tại, nhưng không có seeder nào tạo loại phòng cả.
        $roomTypeHomestay  = RoomType::firstOrCreate(['slug' => 'homestay'], ['name' => 'Homestay', 'is_active' => true]);
        $roomTypeKhachSan  = RoomType::firstOrCreate(['slug' => 'khach-san'], ['name' => 'Khách sạn', 'is_active' => true]);

        $partners = [
            ['name' => 'Đối tác Golden Bee', 'tax_code' => '0100100001', 'phone' => '0900000001', 'email' => 'partner1@365home.test'],
            ['name' => 'Đối tác Sunrise Homestay', 'tax_code' => '0100100002', 'phone' => '0900000002', 'email' => 'partner2@365home.test'],
            ['name' => 'Đối tác Ocean View', 'tax_code' => '0100100003', 'phone' => '0900000003', 'email' => 'partner3@365home.test'],
        ];

        foreach ($partners as $index => $data) {
            $n = $index + 1;

            $partner = Partner::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'       => $data['name'],
                    'tax_code'   => $data['tax_code'],
                    'phone'      => $data['phone'],
                    'status'     => true,
                    'created_by' => $superAdmin?->id,
                ]
            );

            $owner = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'fullname'          => "Chủ {$data['name']}",
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                    'partner_id'        => $partner->id,
                    'created_by'        => $superAdmin?->id,
                ]
            );
            if (! $owner->hasRole('partner')) {
                $owner->assignRole('partner');
            }

            // Role nhân viên RIÊNG của đối tác này (created_by = chủ đối tác) — để đối tác tự thấy,
            // tự sửa/xoá/gán quyền được trong UserResource (Select role ở đó lọc created_by =
            // auth()->id(), nên role toàn cục 'employee' — created_by null — sẽ vô hình và không
            // gỡ được với đối tác). Tên role vẫn cần gắn tên đối tác để không trùng (roles là bảng
            // dùng chung, unique theo tên) — nhưng role không hiển thị cho khách hàng nên không
            // ảnh hưởng gì. Quyền khởi tạo copy từ role mẫu 'employee' (nếu có).
            $partnerEmployeeRole = Role::firstOrCreate(
                ['name' => "Nhân viên - {$data['name']}", 'guard_name' => 'web'],
                ['created_by' => $owner->id]
            );
            if ($partnerEmployeeRole->wasRecentlyCreated) {
                $templateRole = Role::where('name', 'employee')->where('guard_name', 'web')->first();
                if ($templateRole) {
                    $partnerEmployeeRole->syncPermissions($templateRole->permissions);
                }
            }

            // 2 nhân viên/đối tác, mỗi người 1 chức vụ/phòng ban khác nhau (đủ "nhiều loại" để test)
            $employeePositions  = [1 => $positionLeTan->id, 2 => $positionNhanVien->id];
            $employeeDepartments = [1 => $departmentKinhDoanh->id, 2 => $departmentIT->id];
            $employeeLabels     = [1 => 'Lễ tân', 2 => 'Kỹ thuật'];

            $employees = [];
            for ($i = 1; $i <= 2; $i++) {
                $employeeEmail = "nv{$i}.partner{$n}@365home.test";
                $label = $employeeLabels[$i];

                $employeeUser = User::updateOrCreate(
                    ['email' => $employeeEmail],
                    [
                        'fullname'          => "NV {$label}",
                        'password'          => Hash::make('password'),
                        'email_verified_at' => now(),
                        'partner_id'        => $partner->id,
                        'created_by'        => $owner->id,
                    ]
                );
                // syncRoles thay vì assignRole: vừa gán role riêng của đối tác, vừa dọn sạch
                // role 'employee' toàn cục cũ (nếu tài khoản này từng bị gán trước đây).
                if (! $employeeUser->hasRole($partnerEmployeeRole)) {
                    $employeeUser->syncRoles([$partnerEmployeeRole]);
                }

                $employees[] = Employee::updateOrCreate(
                    ['user_id' => $employeeUser->id],
                    [
                        'created_by'   => $owner->id,
                        'partner_id'   => $partner->id,
                        'name'         => $employeeUser->fullname,
                        'email'        => $employeeUser->email,
                        'phone'        => "09" . str_pad((string) ($n * 10 + $i), 8, '0', STR_PAD_LEFT),
                        'gender'       => 'male',
                        'status'       => true,
                        'position_id'   => $employeePositions[$i] ?? null,
                        'department_id' => $employeeDepartments[$i] ?? null,
                    ]
                );
            }

            // 2 chi nhánh (Category loại 'product') — slug đã gắn theo partner nên không trùng
            // giữa các đối tác, tên giữ đơn giản/bình thường.
            $branches = [];
            for ($b = 1; $b <= 2; $b++) {
                $branches[] = Category::updateOrCreate(
                    ['slug' => "chi-nhanh-{$b}-partner-{$n}"],
                    [
                        'name'          => "Chi nhánh {$b}",
                        'description'   => "Chi nhánh số {$b}",
                        'category_type' => 'product',
                        'status'        => true,
                        'partner_id'    => $partner->id,
                    ]
                );
            }

            // 2 phòng, mỗi phòng 1 loại phòng (room_type_id) khác nhau và gán vào 1 chi nhánh khác nhau
            $roomTypeIds = [1 => $roomTypeHomestay->id, 2 => $roomTypeKhachSan->id];
            $roomTypeLabels = [1 => 'Homestay', 2 => 'Khách sạn'];

            $rooms = [];
            for ($r = 1; $r <= 2; $r++) {
                $roomLabel = $roomTypeLabels[$r];

                $room = Product::updateOrCreate(
                    ['slug' => "phong-{$r}-partner-{$n}"],
                    [
                        'partner_id'   => $partner->id,
                        'name'         => "Phòng {$roomLabel}",
                        'description'  => "Phòng loại {$roomLabel}",
                        'price'        => 500000 + ($r * 100000),
                        'room_type_id' => $roomTypeIds[$r] ?? null,
                        'is_in_stock'  => true,
                        'is_activated' => true,
                    ]
                );

                $branch = $branches[$r - 1] ?? $branches[0];
                if (! $room->categories()->where('categories.id', $branch->id)->exists()) {
                    $room->categories()->attach($branch->id);
                }

                $rooms[] = $room;
            }

            // 2 tiện ích riêng của đối tác (mỗi đối tác tự định nghĩa, không dùng chung danh mục
            // tiện ích của đối tác khác) — gán cả 2 vào cả 2 phòng để test hiển thị.
            $amenityData = [
                1 => ['type' => 'Phòng tắm', 'name' => 'Vòi sen nóng lạnh'],
                2 => ['type' => 'Tiện nghi', 'name' => 'Điều hòa 2 chiều'],
            ];
            foreach ($amenityData as $idx => $amenityInfo) {
                $amenity = RoomAmenity::updateOrCreate(
                    ['partner_id' => $partner->id, 'name' => $amenityInfo['name']],
                    ['amenity_type' => $amenityInfo['type'], 'status' => true, 'sort_order' => $idx]
                );
                foreach ($rooms as $room) {
                    if (! $room->amenities()->where('room_amenities.id', $amenity->id)->exists()) {
                        $room->amenities()->attach($amenity->id);
                    }
                }
            }

            // 2 dịch vụ bổ sung riêng của đối tác (vd đưa đón sân bay, giặt ủi) — gán vào cả 2 phòng
            $additionServiceData = [
                1 => ['name' => 'Đưa đón sân bay', 'price' => 200000],
                2 => ['name' => 'Giặt ủi', 'price' => 50000],
            ];
            foreach ($additionServiceData as $svc) {
                $service = AdditionService::updateOrCreate(
                    ['partner_id' => $partner->id, 'name' => $svc['name']],
                    ['price' => $svc['price'], 'is_active' => true]
                );
                foreach ($rooms as $room) {
                    if (! $service->products()->wherePivot('room_id', $room->id)->exists()) {
                        $service->products()->attach($room->id);
                    }
                }
            }

            // 2 dịch vụ phòng + 2 điểm đặc biệt, mỗi phòng 1 cái (kế thừa partner_id qua product_id)
            foreach ($rooms as $idx => $room) {
                RoomService::updateOrCreate(
                    ['product_id' => $room->id, 'name' => 'Dịch vụ Ăn sáng'],
                    ['description' => 'Buffet sáng tại chỗ', 'price' => 90000, 'unit' => 'người/ngày', 'sort_order' => $idx]
                );

                RoomSpecial::updateOrCreate(
                    ['product_id' => $room->id, 'title' => 'Miễn phí WiFi tốc độ cao'],
                    ['icon' => 'wifi', 'short_description' => 'Áp dụng cho toàn bộ phòng', 'sort_order' => $idx]
                );
            }

            // 2 coupon — mã code đã gắn theo partner nên không trùng, tên giữ đơn giản/bình thường.
            for ($c = 1; $c <= 2; $c++) {
                Coupon::updateOrCreate(
                    ['code' => "P{$n}COUPON{$c}"],
                    [
                        'partner_id'  => $partner->id,
                        'name'        => "Coupon {$c}",
                        'description' => "Mã giảm giá mẫu số {$c}",
                        'type'        => $c === 1 ? 'percentage' : 'fixed',
                        'value'       => $c === 1 ? 10 : 50000,
                        'apply_type'  => 'all_rooms',
                        'start_at'    => now(),
                        'end_at'      => now()->addMonths(3),
                        'is_active'   => true,
                        'created_by'  => $owner->id,
                    ]
                );
            }

            // 2 khuyến mãi — khoá theo partner_id + tên ngắn gọn (không gắn tên đối tác vào key
            // để tên hiển thị luôn bình thường; partner_id đã đủ để tránh trùng giữa các đối tác).
            for ($p = 1; $p <= 2; $p++) {
                Promotion::updateOrCreate(
                    ['partner_id' => $partner->id, 'name' => "Khuyến mãi {$p}"],
                    [
                        'description' => "Chương trình khuyến mãi mẫu số {$p}",
                        'type'       => 'discount',
                        'value'      => $p * 10,
                        'start_at'   => now(),
                        'end_at'     => now()->addMonths(2),
                        'is_active'  => true,
                        'created_by' => $owner->id,
                    ]
                );
            }

            // 2 đơn hàng, mỗi đơn gắn với 1 chi nhánh
            for ($o = 1; $o <= 2; $o++) {
                Order::firstOrCreate(
                    ['order_code' => "PN{$n}ORD{$o}"],
                    [
                        'partner_id'  => $partner->id,
                        'category_id' => $branches[$o - 1]->id ?? $branches[0]->id,
                        'user_id'     => $owner->id,
                        'amount'      => 500000 * $o,
                        'buyer_name'  => "Khách hàng mẫu {$o}",
                        'buyer_phone' => "090111000{$o}",
                        'status'      => $o === 1 ? 'paid' : 'pending',
                        'guest_count' => 2,
                    ]
                );
            }

            // 2 mã mở cửa, mỗi mã gắn với 1 chi nhánh
            for ($a = 1; $a <= 2; $a++) {
                AccessCode::updateOrCreate(
                    ['code' => "AC{$n}{$a}"],
                    [
                        'partner_id'  => $partner->id,
                        'category_id' => $branches[$a - 1]->id ?? $branches[0]->id,
                        'status'      => 'active',
                        'valid_from'  => now(),
                        'valid_until' => now()->addDays(30),
                        'gate_location' => "Cổng chính chi nhánh {$a}",
                        'notes'       => "Mã mở cửa số {$a}",
                    ]
                );
            }

            // 2 tài khoản TTLock/đối tác
            // Lưu ý: DB hiện tại của bảng ttlock_accounts chưa có cột category_id/is_default dù
            // migration nguồn có khai báo — có vẻ migration đã chạy trước khi các cột này được
            // thêm vào file (thiếu 1 migration "add column" tương ứng, không thuộc phạm vi sửa ở đây).
            for ($t = 1; $t <= 2; $t++) {
                TtlockAccount::updateOrCreate(
                    ['client_id' => "partner{$n}-client-{$t}"],
                    [
                        'partner_id'    => $partner->id,
                        'name'          => "TTLock {$t}",
                        'client_secret' => "secret-partner{$n}-{$t}",
                        'username'      => "ttlock_partner{$n}_{$t}",
                        'password_md5'  => md5("password-partner{$n}-{$t}"),
                        'is_active'     => true,
                    ]
                );
            }

            // 2 audit log mẫu (thao tác của chủ đối tác)
            foreach ($rooms as $l => $room) {
                AuditLog::firstOrCreate(
                    [
                        'partner_id'   => $partner->id,
                        'target_label' => $room->name,
                    ],
                    [
                        'user_id'        => $owner->id,
                        'user_name'      => $owner->fullname,
                        'user_email'     => $owner->email,
                        'performer_role' => 'partner',
                        'action'         => $l === 0 ? 'create' : 'update',
                        'module'         => 'Product',
                        'target_id'      => $room->id,
                        'created_at'     => now(),
                    ]
                );
            }
        }
    }
}
