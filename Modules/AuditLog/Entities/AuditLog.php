<?php

declare(strict_types=1);

namespace Modules\AuditLog\Entities;

use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToPartner;

    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $fillable = [
        'partner_id',
        'user_id',
        'user_name',
        'user_email',
        'performer_role',
        'action',
        'module',
        'target_id',
        'target_label',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // Nhãn hiển thị cho module
    public static function moduleLabels(): array
    {
        return [
            'Role'                 => 'Vai trò',
            'User'                 => 'Nhân viên',
            'Customer'             => 'Khách hàng',
            'UserBranchPermission' => 'Phân quyền chi nhánh',
            'Post'                 => 'Bài viết',
            'Product'              => 'Phòng',
            'Order'                => 'Booking',
            'Branch'               => 'Chi nhánh',
            'Coupon'               => 'Mã giảm giá',
            'Promotion'            => 'Khuyến mãi',

            // Bổ sung cho các model mới gắn LogsAuditTrail (xem app/Models/Concerns/LogsAuditTrail.php).
            'Province'              => 'Tỉnh/Thành phố',
            'CustomerCheckinCycle'  => 'Chu kỳ đăng nhập KH',
            'ConsultationLog'       => 'Tư vấn khách hàng',
            'NotificationFcm'       => 'FCM Token',
            'CccdDeclaration'       => 'Khai báo CCCD',
            'AskUser'               => 'Hỏi đáp AskUser',
            'MembershipTier'        => 'Hạng thành viên',
            'GuestCustomer'         => 'Khách vãng lai',
            'TtlockAccount'         => 'Tài khoản TTLock',
            'Category'              => 'Danh mục',
            'Banner'                => 'Banner',
            'PopupImage'            => 'Popup quảng cáo',
            'AccessCode'            => 'Mã khoá',
            'AppPage'               => 'Trang APP',
            'RoomTimeSlot'          => 'Khung giờ phòng',
            'PriceBoard'            => 'Bảng giá',
            'Comment'               => 'Bình luận',
            'Component'             => 'Thành phần',
            'DataScope'             => 'Phạm vi dữ liệu',
            'EmailSetting'          => 'Cấu hình Email',
            'FormNotification'      => 'Thông báo Form',
            'Form'                  => 'Form',
            'FormSubmission'        => 'Dữ liệu Form gửi về',
            'Page'                  => 'Trang nội dung',
            'PaymentConfiguration'  => 'Cấu hình thanh toán',
            'Process'               => 'Quy trình',
            'AdditionService'       => 'Dịch vụ thêm',
            'ManualLockPassword'    => 'Mật khẩu khoá thủ công',
            'RoomAmenity'           => 'Tiện ích phòng',
            'RoomImage'             => 'Ảnh phòng',
            'RoomService'           => 'Dịch vụ phòng',
            'RoomSpecial'           => 'Ưu đãi đặc biệt phòng',
            'RoomType'              => 'Loại phòng',
            'QA'                    => 'Hỏi đáp',
            'Business'              => 'Thông tin công ty',
            'Tag'                   => 'Tag',
            'Theme'                 => 'Giao diện',
            'WarehouseCategory'     => 'Nhóm vật tư',
            'WarehouseItem'         => 'Vật tư',
            'WarehouseStockCheck'   => 'Phiếu kiểm kê',
            'WarehouseStockIn'      => 'Phiếu nhập kho',
            'WarehouseStockOut'     => 'Phiếu xuất kho',
            'WarehouseUnit'         => 'Đơn vị tính',
            'ZnsNotification'       => 'Thông báo ZNS',
        ];
    }

    // Nhãn hiển thị cho action
    public static function actionLabels(): array
    {
        return [
            'create'           => 'Tạo mới',
            'update'           => 'Cập nhật',
            'delete'           => 'Xóa',
            'price_adjustment' => 'Phát sinh/hoàn tiền',
        ];
    }

    public function getModuleLabelAttribute(): string
    {
        return static::moduleLabels()[$this->module] ?? $this->module;
    }

    public function getActionLabelAttribute(): string
    {
        return static::actionLabels()[$this->action] ?? $this->action;
    }
}
