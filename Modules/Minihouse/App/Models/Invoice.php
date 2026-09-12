<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaContract;

class Invoice extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingViaContract;
    use LogsMinihouseActivity;

    public const STATUS_UNPAID  = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID    = 'paid';

    protected $table = 'minihouse_invoices';

    protected $fillable = [
        'contract_id', 'month', 'period_start', 'period_end', 'room_price',
        'electric_start', 'electric_end', 'electric_unit_price', 'electric_amount',
        'water_start', 'water_end', 'water_unit_price', 'water_amount',
        'service_amount', 'total_amount', 'status', 'amount_paid', 'paid_at',
        'payos_order_code', 'payos_checkout_url', 'payos_qr_code', 'payos_expired_at',
        'momo_order_id', 'momo_qr_code', 'momo_pay_url', 'momo_expired_at',
        'vnpay_txn_ref', 'vnpay_payment_url', 'vnpay_expired_at',
    ];

    // float cho mọi cột số tiền/chỉ số — tránh 'decimal:2' luôn ép hiện đủ 2 số lẻ (VD "0.00") dù
    // giá trị là số nguyên.
    protected $casts = [
        'month'                => 'date',
        'period_start'         => 'date',
        'period_end'           => 'date',
        'paid_at'              => 'datetime',
        'room_price'           => 'float',
        'electric_start'       => 'float',
        'electric_end'         => 'float',
        'electric_unit_price'  => 'float',
        'electric_amount'      => 'float',
        'water_start'          => 'float',
        'water_end'            => 'float',
        'water_unit_price'     => 'float',
        'water_amount'         => 'float',
        'service_amount'       => 'float',
        'total_amount'         => 'float',
        'amount_paid'          => 'float',
        'payos_expired_at'     => 'datetime',
        'momo_expired_at'      => 'datetime',
        'vnpay_expired_at'     => 'datetime',
    ];

    // Còn hiệu lực để hiện QR cho khách quét — hết hạn thì phải tạo QR mới (xem
    // InvoicePayOsService::createQr()).
    public function hasActivePayOsQr(): bool
    {
        return filled($this->payos_qr_code) && $this->payos_expired_at?->isFuture();
    }

    // Cùng nguyên tắc hasActivePayOsQr() — còn hiệu lực thì hiện lại đúng link cũ, không tự tạo link
    // mới mỗi lần mở popup (tránh phát sinh giao dịch mới không cần thiết ở phía MoMo/VNPay).
    public function hasActiveMomoLink(): bool
    {
        return filled($this->momo_pay_url) && $this->momo_expired_at?->isFuture();
    }

    public function hasActiveVnpayLink(): bool
    {
        return filled($this->vnpay_payment_url) && $this->vnpay_expired_at?->isFuture();
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    // Từng dòng phụ thu (rác, gửi xe, internet...) đã áp vào hoá đơn này — tổng của các dòng này
    // = service_amount (xem InvoiceForm::recalcItemsTotal()).
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // Từng LẦN thanh toán — amount_paid/paid_at/status là cột CACHE tự đồng bộ từ đây, xem
    // InvoicePaymentObserver. Không tự tính tay ở đây để tránh 2 nơi tính ra 2 kết quả khác nhau.
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    // Hoá đơn đã có ÍT NHẤT 1 khoản thanh toán ĐÃ DUYỆT — dùng để chặn xoá hoá đơn (xem
    // InvoiceObserver::deleting()): xoá 1 hoá đơn hiện tại xoá THẬT (không phải xoá mềm) mọi
    // InvoicePayment + kéo theo xoá luôn dòng "Thu" tương ứng trong sổ Thu Chi, tức là mất VĨNH VIỄN
    // bằng chứng đã thu tiền thật — sai nguyên tắc kế toán nếu để xoá tự do. Chỉ chặn khi có khoản
    // ĐÃ DUYỆT (tiền đã xác nhận thật); khoản "pending" (nhân viên khai chờ chủ nhà duyệt) chưa phải
    // tiền thật nên không cần chặn, xoá hoá đơn nháp/nhầm đó vẫn cho phép.
    public function hasApprovedPayment(): bool
    {
        return $this->payments()->where('status', InvoicePayment::STATUS_APPROVED)->exists();
    }

    // "Lập hoá đơn hàng loạt" CỐ Ý tạo hoá đơn thiếu chỉ số điện/nước (không tự đoán được, phải đọc
    // đồng hồ thật — xem InvoiceGenerationService), nhân viên tự bổ sung sau bằng cách sửa tay. Trong
    // khoảng thời gian CHƯA bổ sung đó, hoá đơn CHƯA đủ thông tin để cho khách thuê xem trong Portal
    // (tổng tiền lúc này còn thiếu tiền điện/nước, sẽ tự đổi khi nhân viên điền xong — khách thấy số
    // tiền "nhảy" mà không hiểu vì sao rất dễ hiểu nhầm là tính sai). Coi là "sẵn sàng cho khách" khi
    // CẢ 2 chỉ số cuối kỳ đã được điền — dùng chung cho cả Portal (ẩn/thông báo) VÀ InvoiceObserver
    // (thời điểm bắn thông báo "Hoá đơn mới").
    public function isReadyForTenant(): bool
    {
        return filled($this->electric_end) && filled($this->water_end);
    }

    // Ngày ĐẾN HẠN đóng tiền của hoá đơn này — mặc định = period_start (đúng ngày kỳ thuê/tháng bắt
    // đầu, dùng cho cả 2 kiểu chu kỳ: mùng 1 với toà "Theo tháng dương lịch", hoặc đúng ngày dọn vào
    // với toà "Theo ngày thuê"). RIÊNG toà "Theo tháng dương lịch" có khai "Ngày thu cố định"
    // (Building::fixed_due_day, VD mùng 10) thì đến hạn = đúng ngày đó CỦA THÁNG hoá đơn này (không
    // đổi cách tính tiền phòng — vẫn prorate theo period_start/period_end như cũ, CHỈ đổi ngày dùng
    // để tính remind_date tự động, xem InvoiceObserver::created()). Không áp fixed_due_day cho toà
    // "Theo ngày thuê" — ngày dọn vào của từng khách MỚI là mốc cố định thật sự cho hợp đồng đó.
    public function dueDate(): ?\Illuminate\Support\Carbon
    {
        if (! $this->period_start) {
            return null;
        }

        $building = $this->resolveContractSafely()?->room?->building;

        if (! $building || $building->usesAnniversaryBilling() || ! $building->fixed_due_day) {
            return $this->period_start;
        }

        $day = min((int) $building->fixed_due_day, $this->period_start->daysInMonth);

        return $this->period_start->copy()->day($day);
    }

    // Contract dùng SoftDeletes riêng — quan hệ mặc định $this->contract sẽ trả về null nếu hợp
    // đồng bị xoá mềm dù hoá đơn vẫn còn (dueDate()/activityLabel() có thể được gọi trên 1 hoá đơn cũ
    // BẤT KỲ LÚC NÀO, không chỉ ngay lúc tạo). Nếu người gọi đã tự nạp sẵn quan hệ 'contract' an toàn
    // từ trước (VD InvoiceObserver::created() dùng setRelation()) thì DÙNG LẠI, không query thêm lần
    // nữa — chỉ tự query khi chưa có.
    private function resolveContractSafely(): ?Contract
    {
        if ($this->relationLoaded('contract')) {
            return $this->contract;
        }

        return $this->contract_id
            ? Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($this->contract_id)
            : null;
    }

    // Chỉ nhận ĐÚNG 1 lần thanh toán duy nhất cho mỗi hoá đơn, số tiền phải bằng đúng tổng tiền hoá
    // đơn (yêu cầu 2026-09-07: không còn hỗ trợ trả nhiều lần/trả từng phần cho các lần ghi nhận
    // MỚI) — dùng chung ở cả InvoiceForm (Filament) và InvoicePaymentController (API) để 2 nơi không
    // lệch quy tắc. Hoá đơn CŨ đã lỡ có nhiều dòng/trả 1 phần từ trước khi áp quy tắc này vẫn giữ
    // nguyên dữ liệu, không bị xoá/gộp lại — quy tắc chỉ chặn THÊM MỚI.
    // $ignorePaymentId: bỏ qua chính dòng đang sửa khi kiểm tra "đã có lần trả nào chưa" (dùng khi
    // sửa 1 payment đã tồn tại thay vì thêm mới).
    public function validateSinglePayment(float $amount, ?int $ignorePaymentId = null): ?string
    {
        $existingCount = $this->payments()
            ->when($ignorePaymentId, fn ($q) => $q->where('id', '!=', $ignorePaymentId))
            ->count();

        if ($existingCount > 0) {
            return 'Hoá đơn này đã được ghi nhận thanh toán — không thể thêm lần thứ 2 (chỉ nhận thanh toán 1 lần duy nhất).';
        }

        if (abs($amount - (float) $this->total_amount) > 0.01) {
            return 'Số tiền thanh toán phải đúng bằng tổng tiền hoá đơn (' . number_format((float) $this->total_amount, 0, ',', '.') . 'đ) — không hỗ trợ trả từng phần.';
        }

        return null;
    }

    protected function activityLabel(): string
    {
        $roomCode = $this->resolveContractSafely()?->room?->code;

        return 'Hoá đơn tháng ' . ($this->month?->format('m/Y') ?? '#' . $this->id)
            . ($roomCode ? ' - phòng ' . $roomCode : '');
    }
}
