<?php

namespace Modules\Minihouse\App\Support;

use Illuminate\Database\Eloquent\Builder;

// NGUỒN DUY NHẤT liệt kê toàn bộ quyền panel MiniHouse — dùng chung bởi
// database\seeders\MinihousePermissionSeeder (tạo quyền/vai trò mặc định) VÀ
// Modules\Minihouse\App\Filament\Resources\RoleResource (checkbox chọn quyền khi tạo/sửa vai trò
// ngay trong panel MiniHouse) — tránh 2 nơi liệt kê tay dễ lệch nhau khi thêm Resource mới.
//
// LƯU Ý: SurchargeResource cố ý dùng CHUNG nhóm quyền 'buildings' (không có 'surcharges' riêng ở
// đây) — xem SurchargeResource::permissionGroup(), phụ thu là dữ liệu phụ trợ theo toà nhà.
class MinihousePermissions
{
    public const RESOURCE_GROUPS = [
        'buildings', 'zones', 'rooms', 'tenants', 'contracts', 'invoices', 'transactions', 'reminders', 'residence_declarations', 'feedbacks', 'announcements', 'cameras', 'warehouse',
    ];

    public const RESOURCE_ACTIONS = ['view_any', 'create', 'update', 'delete'];

    // Quyền không theo khuôn CRUD ở trên — điều kiện đăng nhập được panel (access_minihouse), xem
    // báo cáo (view_any_reports), xem nhật ký hoạt động (view_any_activity_logs — CHỈ xem, không có
    // create/update/delete vì ActivityLogResource là dữ liệu chỉ đọc, không ai được sửa/xoá log).
    // approve_invoice_payments: quyền RIÊNG cho "Chủ toà nhà" tự xác nhận 1 lần thanh toán tiền mặt/
    // chuyển khoản do nhân viên ghi nhận là CÓ THẬT trước khi hoá đơn được tính "Đã thanh toán" —
    // tách khỏi update_invoices vì nhân viên vẫn cần ghi nhận thanh toán được (update_invoices) mà
    // KHÔNG được tự duyệt luôn chính mình (xem InvoicePaymentObserver, EditInvoice::approvePayment).
    // Thanh toán qua PayOS (webhook tự xác nhận tiền đã vào tài khoản thật) KHÔNG cần qua bước này.
    // page_camera_monitor: xem trang "Xem camera" (live view) — tách khỏi view_any_cameras vì đây
    // là trang XEM TRỰC TIẾP (không sửa/xoá cấu hình camera), nhân viên trực có thể chỉ cần quyền
    // này mà không cần toàn quyền quản lý danh sách camera. page_manage_camera_settings: cấu hình
    // địa chỉ/tài khoản máy chủ Frigate/go2rtc dùng CHUNG cho mọi camera MiniHouse (1 cấu hình duy
    // nhất, xem HomestayBridge::PARTNER_ID) — nhạy cảm hơn CRUD camera thường nên tách quyền riêng,
    // cùng nguyên tắc page_ManageCamera bên Home.
    public const EXTRA_PERMISSIONS = ['access_minihouse', 'view_any_reports', 'view_any_activity_logs', 'approve_invoice_payments', 'page_camera_monitor', 'page_manage_camera_settings'];

    public const GROUP_LABELS = [
        'buildings'              => 'Toà nhà / Phụ thu',
        'zones'                  => 'Khu vực',
        'rooms'                  => 'Phòng',
        'tenants'                => 'Khách thuê',
        'contracts'              => 'Hợp đồng',
        'invoices'               => 'Hoá đơn',
        'transactions'           => 'Thu chi',
        'reminders'              => 'Nhắc việc',
        'residence_declarations' => 'Khai báo lưu trú',
        'feedbacks'              => 'Phản hồi khách thuê',
        'announcements'          => 'Thông báo (Portal)',
        'cameras'                => 'Camera',
        // 1 nhóm quyền DUY NHẤT dùng chung cho toàn bộ 7 Resource kho vật tư (Danh mục/Đơn vị/Vật tư/
        // Nhập/Xuất/Kiểm kê/Hoàn trả) — cùng nguyên tắc SurchargeResource dùng chung nhóm 'buildings',
        // tránh nổ ra 7 nhóm quyền riêng cho 1 tính năng vốn luôn được cấp/thu hồi CÙNG LÚC trong thực
        // tế (nhân viên phụ trách kho thường cần TOÀN QUYỀN kho, không tách nhỏ theo từng loại phiếu).
        'warehouse'              => 'Kho vật tư',
    ];

    public const ACTION_LABELS = [
        'view_any' => 'Xem',
        'create'   => 'Tạo',
        'update'   => 'Sửa',
        'delete'   => 'Xoá',
    ];

    /** @return array<string> */
    public static function all(): array
    {
        $permissions = self::EXTRA_PERMISSIONS;

        foreach (self::RESOURCE_GROUPS as $group) {
            foreach (self::RESOURCE_ACTIONS as $action) {
                $permissions[] = "{$action}_{$group}";
            }
        }

        return $permissions;
    }

    // "Xem"/"Tạo"/"Sửa"/"Xoá" — dùng cho CheckboxList của 1 nhóm resource trong RoleForm.
    /** @return array<string, string> permission => nhãn */
    public static function optionsForGroup(string $group): array
    {
        $options = [];

        foreach (self::RESOURCE_ACTIONS as $action) {
            $options["{$action}_{$group}"] = self::ACTION_LABELS[$action];
        }

        return $options;
    }

    // Thu hẹp 1 query User bất kỳ về đúng "tài khoản thuộc MiniHouse" — có quyền access_minihouse
    // trực tiếp, qua vai trò, hoặc super_admin. Dùng chung bởi UserResource::getEloquentQuery()
    // (danh sách tài khoản hiện trong panel) VÀ ReminderNotificationService (ai được nhận thông báo
    // nhắc việc) — 1 nguồn duy nhất định nghĩa "tài khoản MiniHouse là gì", tránh 2 nơi định nghĩa
    // lệch nhau. Nhận vào Builder có sẵn (thay vì tự tạo User::query()) để gọi được ở
    // getEloquentQuery() (đã có parent::getEloquentQuery() làm gốc) lẫn 1 query mới tinh.
    public static function scopeToMinihouseUsers(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('permissions', fn (Builder $q2) => $q2->where('name', 'access_minihouse'))
                ->orWhereHas('roles.permissions', fn (Builder $q2) => $q2->where('name', 'access_minihouse'))
                ->orWhereHas('roles', fn (Builder $q2) => $q2->where('name', config('filament-shield.super_admin.name')));
        });
    }
}
