<?php

namespace Modules\Minihouse\App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
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
        'monthly_price' => 'Giá thuê/tháng', 'deposit_amount' => 'Tiền cọc', 'reason_for_stay' => 'Lý do lưu trú',
        'custom_reason' => 'Lý do khác', 'contract_content' => 'Nội dung hợp đồng', 'contract_file' => 'File hợp đồng',
        'handover_file' => 'Biên bản bàn giao', 'deposit_receipt_file' => 'Biên bản đặt cọc',
        'checkout_at' => 'Ngày trả phòng', 'deposit_refunded_amount' => 'Tiền cọc hoàn lại',
        'deposit_deduction_reason' => 'Lý do trừ cọc', 'checkout_handover_file' => 'Biên bản bàn giao (trả phòng)',
        'transferred_to_contract_id' => 'Hợp đồng chuyển đến', 'transferred_from_contract_id' => 'Hợp đồng chuyển từ',
        'contract_id' => 'Hợp đồng', 'old_end_date' => 'Ngày kết thúc cũ', 'new_end_date' => 'Ngày kết thúc mới',
        'old_monthly_price' => 'Giá thuê cũ', 'new_monthly_price' => 'Giá thuê mới', 'created_by' => 'Người tạo',

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
        'zone_id' => Zone::class, 'building_id' => Building::class, 'room_id' => Room::class,
        'tenant_id' => Tenant::class, 'contract_id' => Contract::class, 'invoice_id' => Invoice::class,
        'invoice_payment_id' => InvoicePayment::class, 'surcharge_id' => Surcharge::class,
        'transferred_to_contract_id' => Contract::class, 'transferred_from_contract_id' => Contract::class,
        'created_by' => User::class, 'approved_by' => User::class, 'declared_by' => User::class,
        'assigned_to' => User::class,
    ];

    // model::class => field => [giá trị CSDL => nhãn tiếng Việt] — enum KHÁC NHAU tuỳ model nên
    // không gộp chung được như FIELD_LABELS (VD "status" của Invoice khác hẳn "status" của Room).
    private const VALUE_MAPS = [
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
    private const BOOLEAN_FIELDS = ['is_active', 'is_done', 'residence_declared', 'payment_sandbox'];

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

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value)) {
            return Carbon::parse($value)->format('d/m/Y H:i:s');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->format('d/m/Y');
        }

        return (string) $value;
    }

    // Hồ sơ liên quan đã bị xoá (mềm/thật) vẫn hiện được "#ID" thay vì lỗi trắng trang — không dùng
    // withoutGlobalScopes() bừa cho MỌI model (VD User không có global scope nào cần bỏ), chỉ những
    // model MiniHouse có ActiveBuildingScope mới cần.
    private static function resolveForeignKey(string $modelClass, mixed $id): string
    {
        $query = in_array($modelClass, [User::class], true) ? $modelClass::query() : $modelClass::withoutGlobalScopes();
        $model = $query->find($id);

        if (! $model) {
            return '#' . $id;
        }

        return match ($modelClass) {
            User::class     => $model->fullname ?: ($model->email ?? ('#' . $id)),
            Tenant::class   => $model->fullname,
            Room::class     => $model->code,
            Building::class => $model->name,
            Zone::class     => $model->name,
            Surcharge::class => $model->name,
            Contract::class => 'Hợp đồng #' . $model->id,
            Invoice::class  => 'Hoá đơn tháng ' . ($model->month?->format('m/Y') ?? '#' . $model->id),
            InvoicePayment::class => 'Thanh toán #' . $model->id,
            default => '#' . $id,
        };
    }
}
