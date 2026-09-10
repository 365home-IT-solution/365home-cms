<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Tenant;

// NGUỒN DUY NHẤT cho logic dữ liệu dùng CHUNG giữa Portal web (session, xem
// Modules\Minihouse\Http\Controllers\Portal\*) VÀ API Portal (token Sanctum, xem
// app\Http\Controllers\Api\Minihouse\Portal\*) — 2 giao diện PHẢI trả lời "khách này xem/thanh
// toán được đúng những hoá đơn/hợp đồng nào" GIỐNG HỆT NHAU, viết 2 lần dễ lệch (đúng lớp lỗi đã gặp
// nhiều lần trong module này — VD withoutGlobalScopes() lỡ áp nhầm cho Invoice).
class TenantPortalService
{
    // Chuẩn hoá về đúng định dạng lưu trong Tenant.phone (0xxxxxxxxx) — khách có thể gõ có khoảng
    // trắng/dấu chấm/mã +84.
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '84') && strlen($phone) > 9) {
            $phone = '0' . substr($phone, 2);
        }

        return $phone;
    }

    // Đăng nhập bằng SĐT + mật khẩu — KHÔNG dùng Auth::attempt()/Eloquent provider mặc định vì nó
    // chỉ lấy DÒNG ĐẦU TIÊN khớp "phone" rồi so mật khẩu với đúng dòng đó — sai ngay khi 1 SĐT gắn
    // nhiều hồ sơ (VD người thân dùng chung số) mà người đăng nhập không phải dòng đầu tiên. Tự lấy
    // TẤT CẢ hồ sơ khớp SĐT rồi so mật khẩu với TỪNG hồ sơ.
    public static function findTenantByPassword(string $phone, string $password): ?Tenant
    {
        return Tenant::where('phone', $phone)
            ->whereNotNull('password')
            ->get()
            ->first(fn (Tenant $candidate) => Hash::check($password, $candidate->password));
    }

    // TOÀN BỘ hợp đồng khách này có liên quan — đứng tên chính LẪN ở cùng (xem Tenant::contracts()),
    // withoutGlobalScopes() qua quan hệ BelongsToMany không tự áp global scope của Contract nên
    // KHÔNG cần gọi thêm, nhưng vẫn phải nạp lại KHÔNG scope khi cần room/building bên dưới.
    public static function tenantContracts(Tenant $tenant): Collection
    {
        return Contract::withoutGlobalScopes()
            ->whereIn('id', $tenant->contracts()->pluck('minihouse_contracts.id'))
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
            ->get();
    }

    public static function invoiceBelongsToTenant(Invoice $invoice, Tenant $tenant): bool
    {
        if (! $invoice->contract_id) {
            return false;
        }

        return $tenant->contracts()->pluck('minihouse_contracts.id')->contains($invoice->contract_id);
    }

    public static function unpaidTotalForActiveContract(?Contract $activeContract): float
    {
        if (! $activeContract) {
            return 0.0;
        }

        // KHÔNG withoutGlobalScopes() — Invoice dùng SoftDeletes, bỏ TOÀN BỘ scope sẽ kéo theo cả
        // hoá đơn ĐÃ XOÁ MỀM vẫn hiện/tính tiền cho khách. whereNotNull(electric_end, water_end) —
        // xem Invoice::isReadyForTenant(): hoá đơn vừa lập hàng loạt còn thiếu chỉ số điện/nước
        // CHƯA được tính vào đây, chờ nhân viên bổ sung xong.
        return Invoice::where('contract_id', $activeContract->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->whereNotNull('electric_end')->whereNotNull('water_end')
            ->get()
            ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
    }

    // Đếm TOÀN BỘ hợp đồng (không chỉ hợp đồng đang hiệu lực như unpaidTotalForActiveContract() ở
    // trên) vì hoá đơn còn nợ của hợp đồng cũ (đã hết hạn/thanh lý) khách vẫn cần biết để thanh toán
    // nốt, không nên "biến mất" khỏi cảnh báo chỉ vì hợp đồng không còn active.
    public static function unpaidInvoiceCount(Collection $contracts): int
    {
        return Invoice::whereIn('contract_id', $contracts->pluck('id'))
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->whereNotNull('electric_end')->whereNotNull('water_end')
            ->count();
    }

    // VietQR là QR chuyển khoản NGÂN HÀNG TĨNH (không qua cổng thanh toán nào, không hết hạn, không
    // tự đối soát) — khác hẳn PayOS/MoMo/VNPay bên dưới vốn tạo giao dịch động qua API. Đây thường
    // là kiểu MẶC ĐỊNH của toà nhà (chỉ cần điền số tài khoản ngân hàng, không cần đăng ký cổng nào).
    /** @return array{qr_image: string, open_url: null, open_label: string, amount: float, expired_at: null, bank_info: array{holder: ?string, bank: ?string, account: ?string}}|null */
    public static function normalizeVietQrResult(Invoice $invoice, Building $building): ?array
    {
        $amount = $invoice->remainingAmount();
        $roomCode = $invoice->contract?->room?->code;
        $monthLabel = $invoice->month?->format('m/Y');

        $url = VietQrService::imageUrl($building, $amount, 'Tien phong ' . $roomCode . ' thang ' . $monthLabel);

        if (! $url) {
            return null;
        }

        return [
            'qr_image'   => $url,
            'open_url'   => null,
            'open_label' => '',
            'amount'     => $amount,
            'expired_at' => null,
            'bank_info'  => [
                'holder'  => $building->owner_bank_account_holder,
                'bank'    => $building->owner_bank_name,
                'account' => $building->owner_bank_account_number,
            ],
        ];
    }

    /** @return array{qr_image: string, open_url: ?string, open_label: string, amount: float, expired_at: string} */
    public static function normalizePayOsResult(array $result): array
    {
        return [
            'qr_image'   => $result['qr_image'],
            'open_url'   => $result['checkout_url'],
            'open_label' => 'Mở trang thanh toán PayOS',
            'amount'     => $result['amount'],
            'expired_at' => $result['expired_at'],
        ];
    }

    public static function normalizeMomoResult(array $result): array
    {
        return [
            'qr_image'   => $result['qr_image'],
            'open_url'   => $result['pay_url'],
            'open_label' => 'Mở trang thanh toán MoMo',
            'amount'     => $result['amount'],
            'expired_at' => $result['expired_at'],
        ];
    }

    public static function normalizeVnpayResult(array $result): array
    {
        return [
            'qr_image'   => $result['qr_image'],
            'open_url'   => $result['payment_url'],
            'open_label' => 'Mở trang thanh toán VNPay',
            'amount'     => $result['amount'],
            'expired_at' => $result['expired_at'],
        ];
    }
}
