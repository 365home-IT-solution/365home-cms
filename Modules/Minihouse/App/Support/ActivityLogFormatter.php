<?php

namespace Modules\Minihouse\App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use App\Models\Partner;
use Modules\Metering\App\Models\MeteringReading;
use Modules\Minihouse\App\Models\ActivityLog;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractDocument;
use Modules\Minihouse\App\Models\ContractDocumentEvent;
use Modules\Minihouse\App\Models\ContractSignature;
use Modules\Minihouse\App\Models\WarehouseCategory;
use Modules\Minihouse\App\Models\WarehouseItem;
use Modules\Minihouse\App\Models\WarehouseItemAdjustment;
use Modules\Minihouse\App\Models\WarehouseStockCheck;
use Modules\Minihouse\App\Models\WarehouseStockIn;
use Modules\Minihouse\App\Models\WarehouseStockOut;
use Modules\Minihouse\App\Models\WarehouseStockReturn;
use Modules\Minihouse\App\Models\WarehouseUnit;
use Modules\Product\App\Models\RoomType;
use Modules\Minihouse\App\Models\ContractRenewal;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Surcharge;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Models\Zone;

// NGUỒN DUY NHẤT dịch tên trường + giá trị sang tiếng Việt cho trang "Nhật ký hoạt động" (xem
// Modules\Minihouse\Resources\views\filament\resources\activity-log\details.blade.php) — trước đây
// hiện thẳng tên cột CSDL (tiếng Anh, VD "approved_at") và giá trị enum thô (VD "pending"), thậm chí
// hiện ID thô (UUID người duyệt) thay vì tên người — lẫn lộn ngôn ngữ và không đọc được là ai/cái gì.
// Áp dụng CHUNG cho MỌI model có gắn LogsMinihouseActivity (Building, Contract, Invoice,
// InvoicePayment, Reminder, ResidenceDeclaration, Room, Surcharge, Tenant, Transaction, Zone,
// ContractRenewal), không phải viết riêng từng nơi.
class ActivityLogFormatter
{
    // subject_type (tên class đầy đủ, lưu nguyên trong cột subject_type) => tên tiếng Việt của loại
    // đối tượng — dùng cho cột "Đối tượng"/bộ lọc "Loại đối tượng"/tiêu đề modal ở
    // ActivityLogTable.php, thay vì hiện thẳng class_basename() (VD "InvoicePayment").
    public const MODEL_LABELS = [
        Building::class              => 'Toà nhà',
        Contract::class              => 'Hợp đồng',
        ContractRenewal::class       => 'Gia hạn hợp đồng',
        Invoice::class               => 'Hoá đơn',
        InvoicePayment::class        => 'Thanh toán hoá đơn',
        Reminder::class              => 'Nhắc việc',
        ResidenceDeclaration::class  => 'Khai báo lưu trú',
        Room::class                  => 'Phòng',
        Surcharge::class             => 'Phụ thu',
        Tenant::class                => 'Khách thuê',
        Transaction::class           => 'Thu chi',
        Zone::class                  => 'Khu vực',
        MeteringReading::class       => 'Chỉ số điện nước',
        ContractDocument::class      => 'Hợp đồng điện tử',
        ContractSignature::class     => 'Chữ ký hợp đồng',
        ContractDocumentEvent::class => 'Sự kiện hợp đồng điện tử',
        WarehouseCategory::class     => 'Nhóm vật tư',
        WarehouseUnit::class         => 'Đơn vị tính',
        WarehouseItem::class         => 'Vật tư',
        WarehouseItemAdjustment::class => 'Điều chỉnh tồn vật tư',
        WarehouseStockIn::class      => 'Phiếu nhập kho',
        WarehouseStockOut::class     => 'Phiếu xuất kho',
        WarehouseStockCheck::class   => 'Phiếu kiểm kê',
        WarehouseStockReturn::class  => 'Phiếu hoàn trả',
        \Modules\Minihouse\App\Models\Amenity::class          => 'Tiện ích',
        \Modules\Minihouse\App\Models\Announcement::class     => 'Thông báo',
        \Modules\Minihouse\App\Models\AssetType::class        => 'Loại tài sản',
        \Modules\Minihouse\App\Models\Camera::class           => 'Camera',
        \Modules\Minihouse\App\Models\CameraSetting::class    => 'Cấu hình camera',
        \Modules\Minihouse\App\Models\PanoramaScene::class    => 'Cảnh 360°',
        \Modules\Minihouse\App\Models\PortalBroadcast::class  => 'Thông báo cổng khách thuê',
        \Modules\Minihouse\App\Models\RentalInquiry::class    => 'Yêu cầu thuê phòng',
        \Modules\Minihouse\App\Models\RoomAsset::class        => 'Tài sản phòng',
        \Modules\Minihouse\App\Models\TenantFeedback::class   => 'Phản hồi khách thuê',
        \Modules\Minihouse\App\Models\SmsSetting::class       => 'Cấu hình SMS',
        \Modules\Minihouse\App\Models\ZaloSetting::class      => 'Cấu hình Zalo',
        \Modules\Minihouse\App\Models\TtlockSetting::class     => 'Cấu hình TTLock',
        \Modules\Minihouse\App\Models\Vehicle::class           => 'Xe khách thuê',
        \Modules\Minihouse\App\Models\VehicleRate::class       => 'Bảng giá gửi xe',
    ];

    public static function modelLabel(?string $subjectType): string
    {
        if (! $subjectType) {
            return '—';
        }

        return self::MODEL_LABELS[$subjectType] ?? class_basename($subjectType);
    }

    // field CSDL (tiếng Anh) => nhãn tiếng Việt — DÙNG CHUNG cho mọi model, vì phần lớn tên trường
    // không trùng nghĩa khác nhau giữa các bảng (VD "note"/"status" luôn nên hiểu là "Ghi chú"/
    // "Trạng thái" dù ở bảng nào).
    private const FIELD_LABELS = [
        // Chung
        'name' => 'Tên', 'note' => 'Ghi chú', 'notes' => 'Ghi chú', 'status' => 'Trạng thái',
        'type' => 'Loại', 'category' => 'Danh mục', 'amount' => 'Số tiền', 'content' => 'Nội dung',
        'title' => 'Tiêu đề', 'address' => 'Địa chỉ', 'province' => 'Tỉnh/Thành phố', 'ward' => 'Phường/Xã',
        'phone' => 'Số điện thoại', 'phone_number' => 'Số điện thoại', 'image' => 'Hình ảnh', 'photos' => 'Hình ảnh',
        'is_active' => 'Đang hoạt động', 'is_done' => 'Đã xử lý',

        // Toà nhà
        'zone_id' => 'Khu vực', 'electric_unit_price' => 'Đơn giá điện', 'water_unit_price' => 'Đơn giá nước',
        'payment_method' => 'Phương thức thanh toán', 'billing_cycle_type' => 'Kiểu chu kỳ tính tiền',
        'payment_reminder_days_before' => 'Số ngày nhắc trước hạn', 'payment_reminder_repeat_days' => 'Số ngày lặp lại nhắc',
        'fixed_due_day' => 'Ngày thu cố định', 'contract_expiry_reminder_days_before' => 'Số ngày nhắc trước khi hết hạn HĐ',
        'owner_name' => 'Tên chủ nhà', 'owner_phone' => 'SĐT chủ nhà', 'owner_id_card_number' => 'CCCD chủ nhà',
        'owner_email' => 'Email chủ nhà', 'owner_address' => 'Địa chỉ chủ nhà',
        'owner_bank_bin' => 'Mã ngân hàng', 'owner_bank_name' => 'Tên ngân hàng',
        'owner_bank_account_number' => 'Số tài khoản', 'owner_bank_account_holder' => 'Chủ tài khoản',
        'payos_client_id' => 'PayOS Client ID', 'payos_api_key' => 'PayOS API Key', 'payos_checksum_key' => 'PayOS Checksum Key',
        'momo_partner_code' => 'MoMo Partner Code', 'momo_access_key' => 'MoMo Access Key', 'momo_secret_key' => 'MoMo Secret Key',
        'vnpay_tmn_code' => 'VNPay Mã website (TMN Code)', 'vnpay_hash_secret' => 'VNPay Chuỗi bí mật',
        'payment_sandbox' => 'Chế độ thử nghiệm',

        // Phòng
        'building_id' => 'Toà nhà', 'code' => 'Mã phòng', 'floor' => 'Tầng',
        'position_row' => 'Hàng (vị trí)', 'position_col' => 'Cột (vị trí)', 'area' => 'Diện tích', 'price' => 'Giá',

        // Hợp đồng
        'room_id' => 'Phòng', 'tenant_id' => 'Khách thuê', 'start_date' => 'Ngày bắt đầu', 'end_date' => 'Ngày kết thúc',
        'monthly_price' => 'Giá thuê/tháng', 'deposit_amount' => 'Tiền cọc', 'deposit_paid_at' => 'Ngày thu cọc', 'reason_for_stay' => 'Lý do lưu trú',
        'custom_reason' => 'Lý do khác', 'contract_content' => 'Nội dung hợp đồng', 'contract_file' => 'File hợp đồng',
        'handover_file' => 'Biên bản bàn giao', 'deposit_receipt_file' => 'Biên bản đặt cọc',
        'checkout_at' => 'Ngày trả phòng', 'deposit_refunded_amount' => 'Tiền cọc hoàn lại',
        'deposit_deduction_reason' => 'Lý do trừ cọc', 'checkout_handover_file' => 'Biên bản bàn giao (trả phòng)',
        'transferred_to_contract_id' => 'Hợp đồng chuyển đến', 'transferred_from_contract_id' => 'Hợp đồng chuyển từ',
        'contract_id' => 'Hợp đồng', 'old_end_date' => 'Ngày kết thúc cũ', 'new_end_date' => 'Ngày kết thúc mới',
        'old_monthly_price' => 'Giá thuê cũ', 'new_monthly_price' => 'Giá thuê mới', 'created_by' => 'Người tạo',

        // Phòng (cột kế thừa từ bảng products), vật tư kho, hợp đồng điện tử
        'description' => 'Mô tả', 'is_activated' => 'Đang kích hoạt', 'is_in_stock' => 'Còn phòng',
        'partner_id' => 'Đối tác', 'room_type_id' => 'Loại phòng', 'room_area_sqm' => 'Diện tích (m²)',
        'slug' => 'Đường dẫn', 'styles' => 'Kiểu cho thuê', 'latitude' => 'Vĩ độ', 'longitude' => 'Kinh độ',
        'map_url' => 'Link bản đồ', 'hotline' => 'Hotline', 'setting_video_room' => 'Video phòng',
        'sku' => 'Mã vật tư', 'quantity' => 'Số lượng tồn', 'quantity_in_use' => 'Số lượng đang dùng',
        'min_quantity' => 'Tồn tối thiểu', 'unit_price' => 'Đơn giá', 'warehouse_category_id' => 'Nhóm vật tư',
        'warehouse_unit_id' => 'Đơn vị tính', 'warehouse_item_id' => 'Vật tư', 'old_quantity' => 'Số lượng cũ',
        'new_quantity' => 'Số lượng mới', 'difference' => 'Chênh lệch', 'received_at' => 'Thời điểm nhập',
        'issued_at' => 'Thời điểm xuất', 'returned_at' => 'Thời điểm hoàn trả', 'checked_at' => 'Thời điểm kiểm kê',
        'issued_to' => 'Người nhận', 'returned_by' => 'Người hoàn trả', 'handover_status' => 'Trạng thái bàn giao',
        'handover_confirmed_by' => 'Người xác nhận bàn giao', 'handover_confirmed_at' => 'Thời điểm xác nhận bàn giao',
        'handover_note' => 'Ghi chú bàn giao', 'document_id' => 'Hợp đồng điện tử', 'no' => 'Số hợp đồng',
        'sign_date' => 'Ngày ký', 'signed_place' => 'Nơi ký', 'max_occupants' => 'Số người tối đa',
        'payment_day' => 'Ngày thanh toán hàng tháng', 'extra_terms' => 'Điều khoản bổ sung',
        'sent_at' => 'Đã gửi lúc', 'sealed_at' => 'Đã niêm phong lúc', 'party' => 'Bên ký', 'signer_name' => 'Người ký',
        'signer_phone' => 'SĐT người ký', 'signed_at' => 'Ký lúc', 'event' => 'Sự kiện', 'actor_name' => 'Người thực hiện',
        'body' => 'Nội dung', 'condition' => 'Tình trạng', 'rating' => 'Đánh giá', 'is_reviewed' => 'Đã xem xét',
        'staff_note' => 'Ghi chú nhân viên', 'tenant_name' => 'Tên khách thuê', 'tenant_phone' => 'SĐT khách thuê',
        'base_url' => 'Địa chỉ máy chủ', 'username' => 'Tên đăng nhập', 'password' => 'Mật khẩu', 'api_key' => 'API Key',
        'secret_key' => 'Secret Key', 'brandname' => 'Brandname', 'app_id' => 'App ID', 'app_secret' => 'App Secret',
        'access_token' => 'Access Token', 'refresh_token' => 'Refresh Token', 'image_path' => 'Ảnh', 'thumbnail_path' => 'Ảnh thu nhỏ',
        'email' => 'Email', 'plate_display' => 'Biển số', 'plate' => 'Biển số (chuẩn hoá)', 'vehicle_type' => 'Loại xe', 'brand' => 'Hãng / dòng xe', 'color' => 'Màu', 'parking_slot' => 'Vị trí đậu', 'tag_code' => 'Mã thẻ xe', 'requested_by' => 'Nguồn khai báo', 'reject_reason' => 'Lý do từ chối', 'registration_photo' => 'Ảnh cà-vẹt', 'document_photo' => 'Ảnh giấy tờ xe', 'max_per_contract' => 'Tối đa mỗi hợp đồng', 'capacity' => 'Tổng số chỗ', 'client_id' => 'Client ID', 'client_secret' => 'Client Secret', 'password_md5' => 'Mật khẩu TTLock', 'api_base' => 'API Base URL', 'lock_id' => 'Khoá ngoài (check-in)', 'lock_id_checkout' => 'Khoá trong (check-out)', 'unlock_both_locks' => 'Mở cả 2 ổ cùng lúc', 'stream_key' => 'Stream key', 'branch_id' => 'Toà nhà', 'is_reviewed_at' => 'Xem xét lúc',
        'total_amount' => 'Tổng tiền',

        // Hoá đơn
        'month' => 'Tháng', 'period_start' => 'Bắt đầu kỳ', 'period_end' => 'Kết thúc kỳ', 'room_price' => 'Tiền phòng',
        'electric_start' => 'Số điện đầu kỳ', 'electric_end' => 'Số điện cuối kỳ', 'electric_amount' => 'Tiền điện',
        'water_start' => 'Số nước đầu kỳ', 'water_end' => 'Số nước cuối kỳ', 'water_amount' => 'Tiền nước',
        'service_amount' => 'Phụ thu', 'total_amount' => 'Tổng tiền', 'amount_paid' => 'Đã thanh toán', 'paid_at' => 'Ngày thanh toán',
        'payos_order_code' => 'Mã đơn PayOS', 'payos_checkout_url' => 'Link thanh toán PayOS', 'payos_qr_code' => 'Mã QR PayOS',
        'payos_expired_at' => 'PayOS hết hạn lúc', 'momo_order_id' => 'Mã đơn MoMo', 'momo_qr_code' => 'Mã QR MoMo',
        'momo_pay_url' => 'Link thanh toán MoMo', 'momo_expired_at' => 'MoMo hết hạn lúc', 'vnpay_txn_ref' => 'Mã giao dịch VNPay',
        'vnpay_payment_url' => 'Link thanh toán VNPay', 'vnpay_expired_at' => 'VNPay hết hạn lúc',

        // Thanh toán hoá đơn
        'invoice_id' => 'Hoá đơn', 'approved_at' => 'Ngày duyệt', 'approved_by' => 'Người duyệt',

        // Nhắc việc
        'remind_date' => 'Ngày nhắc', 'repeat_interval_days' => 'Số ngày lặp lại', 'assigned_to' => 'Giao cho',
        'notified_at' => 'Đã gửi lúc',

        // Khai báo lưu trú
        'full_name' => 'Họ tên', 'date_of_birth' => 'Ngày sinh', 'gender' => 'Giới tính', 'cccd_number' => 'Số CCCD',
        'nationality' => 'Quốc tịch', 'document_type' => 'Loại giấy tờ', 'checked_in_at' => 'Ngày đến',
        'checked_out_at' => 'Ngày đi', 'room_number' => 'Số phòng', 'stay_address' => 'Địa chỉ lưu trú',
        'current_residence' => 'Nơi thường trú', 'residence_type' => 'Loại lưu trú', 'address_detail' => 'Địa chỉ chi tiết',
        'declared_at' => 'Ngày khai báo', 'declared_by' => 'Người khai báo', 'last_reminded_at' => 'Lần nhắc gần nhất',

        // Khách thuê
        'fullname' => 'Họ tên', 'password' => 'Mật khẩu', 'id_card_number' => 'Số CCCD',
        'id_card_front' => 'Ảnh CCCD mặt trước', 'id_card_back' => 'Ảnh CCCD mặt sau', 'hometown' => 'Quê quán',
        'permanent_address' => 'Địa chỉ thường trú', 'occupation' => 'Nghề nghiệp', 'workplace' => 'Nơi làm việc',
        'emergency_contact_name' => 'Người liên hệ khẩn cấp', 'emergency_contact_phone' => 'SĐT liên hệ khẩn cấp',
        'residence_declared' => 'Đã khai báo lưu trú', 'residence_declared_at' => 'Ngày khai báo lưu trú',

        // Thu chi
        'invoice_payment_id' => 'Khoản thanh toán', 'transaction_date' => 'Ngày giao dịch', 'receipt_image' => 'Ảnh biên lai',

        // Phụ thu
        'surcharge_id' => 'Khoản phụ thu',
    ];

    // field kết thúc bằng "_id"/"_by"/"_to" trỏ tới 1 bản ghi khác — model tương ứng để tự tra ra
    // TÊN thay vì hiện ID thô.
    private const FOREIGN_KEYS = [
        'zone_id' => Zone::class, 'building_id' => Building::class, 'branch_id' => Building::class, 'room_id' => Room::class,
        'tenant_id' => Tenant::class, 'contract_id' => Contract::class, 'invoice_id' => Invoice::class,
        'invoice_payment_id' => InvoicePayment::class, 'surcharge_id' => Surcharge::class,
        'transferred_to_contract_id' => Contract::class, 'transferred_from_contract_id' => Contract::class,
        'created_by' => User::class, 'approved_by' => User::class, 'declared_by' => User::class,
        'assigned_to' => User::class, 'handover_confirmed_by' => User::class,
        'partner_id' => Partner::class, 'room_type_id' => RoomType::class,
        'warehouse_category_id' => WarehouseCategory::class, 'warehouse_unit_id' => WarehouseUnit::class,
        'warehouse_item_id' => WarehouseItem::class,
    ];

    // Cột kỹ thuật của bảng products (Phòng kế thừa Product) không có ý nghĩa với người dùng — ẩn khỏi
    // popup chi tiết (log cũ đã lỡ lưu các cột này nên phải lọc ở tầng hiển thị, không chỉ lúc ghi).
    private const HIDDEN_FIELDS = [
        \Modules\Minihouse\App\Models\Vehicle::class => ['plate'],
        Room::class => ['slug', 'styles', 'is_in_stock', 'room_type_id', 'partner_id', 'is_activated', 'description', 'room_area_sqm'],
    ];

    // Field tiền tệ — hiện "2.800.000 đ" thay vì số thô "2800000".
    private const MONEY_FIELDS = [
        'price', 'amount', 'monthly_price', 'deposit_amount', 'deposit_refunded_amount', 'old_monthly_price',
        'new_monthly_price', 'room_price', 'electric_amount', 'water_amount', 'service_amount', 'total_amount',
        'amount_paid', 'unit_price', 'monthly_fee', 'electric_unit_price', 'water_unit_price',
    ];

    public static function isHiddenField(?string $subjectType, string $field): bool
    {
        return $subjectType && in_array($field, self::HIDDEN_FIELDS[$subjectType] ?? [], true);
    }

    // model::class => field => [giá trị CSDL => nhãn tiếng Việt] — enum KHÁC NHAU tuỳ model nên
    // không gộp chung được như FIELD_LABELS (VD "status" của Invoice khác hẳn "status" của Room).
    private const VALUE_MAPS = [
        \Modules\Minihouse\App\Models\Vehicle::class => [
            'status'       => \Modules\Minihouse\App\Models\Vehicle::STATUSES,
            'vehicle_type' => \Modules\Minihouse\App\Models\Vehicle::TYPES,
            'requested_by' => ['staff' => 'Nhân viên', 'tenant' => 'Khách tự khai'],
        ],
        \Modules\Minihouse\App\Models\VehicleRate::class => [
            'vehicle_type' => \Modules\Minihouse\App\Models\Vehicle::TYPES,
        ],
        Invoice::class => [
            'status' => [
                Invoice::STATUS_UNPAID  => 'Chưa thanh toán',
                Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
                Invoice::STATUS_PAID    => 'Đã thanh toán',
            ],
        ],
        InvoicePayment::class => [
            'status' => [
                InvoicePayment::STATUS_PENDING  => 'Chờ duyệt',
                InvoicePayment::STATUS_APPROVED => 'Đã duyệt',
            ],
            'payment_method' => [
                InvoicePayment::METHOD_CASH     => 'Tiền mặt',
                InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
                InvoicePayment::METHOD_OTHER    => 'Khác',
            ],
        ],
        Contract::class => [
            'status' => [
                Contract::STATUS_ACTIVE    => 'Đang hiệu lực',
                Contract::STATUS_EXPIRED   => 'Hết hạn',
                Contract::STATUS_CANCELLED => 'Đã huỷ',
            ],
        ],
        Room::class => [
            'status' => [
                Room::STATUS_EMPTY    => 'Trống',
                Room::STATUS_RESERVED => 'Đã đặt cọc',
                Room::STATUS_RENTED   => 'Đang thuê',
                Room::STATUS_REPAIR   => 'Đã khoá',
            ],
        ],
        Reminder::class => [
            'type' => [
                Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                Reminder::TYPE_OTHER       => 'Khác',
            ],
        ],
        Transaction::class => [
            'type' => [
                Transaction::TYPE_IN  => 'Thu',
                Transaction::TYPE_OUT => 'Chi',
            ],
            'category' => [
                Transaction::CATEGORY_REPAIR         => 'Sửa chữa',
                Transaction::CATEGORY_OPERATION       => 'Vận hành',
                Transaction::CATEGORY_DEPOSIT         => 'Thu cọc',
                Transaction::CATEGORY_DEPOSIT_REFUND  => 'Hoàn cọc',
                Transaction::CATEGORY_OTHER           => 'Khác',
            ],
        ],
        Building::class => [
            'payment_method' => [
                Building::PAYMENT_METHOD_VIETQR => 'VietQR (chuyển khoản tĩnh)',
                Building::PAYMENT_METHOD_PAYOS  => 'PayOS',
                Building::PAYMENT_METHOD_MOMO   => 'MoMo',
                Building::PAYMENT_METHOD_VNPAY  => 'VNPay',
            ],
            'billing_cycle_type' => [
                Building::BILLING_CYCLE_CALENDAR_MONTH => 'Theo tháng dương lịch',
                Building::BILLING_CYCLE_ANNIVERSARY    => 'Theo ngày thuê',
            ],
        ],
        Tenant::class => [
            'gender' => [
                Tenant::GENDER_MALE   => 'Nam',
                Tenant::GENDER_FEMALE => 'Nữ',
                Tenant::GENDER_OTHER  => 'Khác',
            ],
        ],
    ];

    // Field boolean thật (cast 'boolean' ở model) — "1"/"0" nên hiện "Có"/"Không", KHÔNG áp cho mọi
    // field có giá trị "0"/"1" chung chung (VD 1 số CCCD/số điện thoại có thể toàn số 0/1 trùng hợp).
    private const BOOLEAN_FIELDS = ['is_active', 'is_done', 'residence_declared', 'payment_sandbox', 'is_activated', 'is_in_stock'];

    public static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    public static function formatValue(?string $subjectType, string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (isset(self::FOREIGN_KEYS[$field])) {
            return self::resolveForeignKey(self::FOREIGN_KEYS[$field], $value);
        }

        if ($subjectType && isset(self::VALUE_MAPS[$subjectType][$field][$value])) {
            return self::VALUE_MAPS[$subjectType][$field][$value];
        }

        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return in_array($value, [1, '1', true, 'true'], true) ? 'Có' : 'Không';
        }

        if (in_array($field, self::MONEY_FIELDS, true) && is_numeric($value)) {
            return number_format((float) $value, 0, ',', '.') . ' đ';
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value)) {
            return Carbon::parse($value)->format('d/m/Y H:i:s');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->format('d/m/Y');
        }

        return (string) $value;
    }

    // Hồ sơ liên quan đã bị xoá (mềm/thật) — tra tên từ chính nhật ký (dòng log gần nhất của đối tượng
    // đó còn lưu subject_label) và ghi rõ "đã xoá"; không còn dấu vết nào thì hiện "Bản ghi đã xoá".
    private static function resolveForeignKey(string $modelClass, mixed $id): string
    {
        $model = null;

        try {
            $model = $modelClass::withoutGlobalScopes()->find($id);
        } catch (\Throwable) {
            // không truy vấn được -> rơi xuống nhánh dự phòng
        }

        $name = $model ? self::nameOf($modelClass, $model) : null;

        if (filled($name)) {
            return (string) $name;
        }

        $label = ActivityLog::query()
            ->where('subject_type', $modelClass)
            ->where('subject_id', (string) $id)
            ->orderByDesc('id')
            ->value('subject_label');

        if (filled($label)) {
            return $label . ' (đã xoá)';
        }

        return 'Bản ghi đã xoá';
    }

    private static function nameOf(string $modelClass, $model): ?string
    {
        return match ($modelClass) {
            User::class           => $model->fullname ?: ($model->email ?? null),
            Tenant::class         => $model->fullname,
            Room::class           => $model->code ?: $model->name,
            Contract::class       => 'Hợp đồng #' . $model->id,
            Invoice::class        => 'Hoá đơn tháng ' . ($model->month?->format('m/Y') ?? '#' . $model->id),
            InvoicePayment::class => 'Thanh toán #' . $model->id,
            default               => $model->name ?? null,
        };
    }
}
