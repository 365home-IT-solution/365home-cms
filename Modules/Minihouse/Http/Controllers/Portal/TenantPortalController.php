<?php

namespace Modules\Minihouse\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\TenantPortalService;
use Modules\Minihouse\Http\Controllers\Portal\Concerns\InteractsWithTenantPortalData;

// Portal khách thuê ĐÃ ĐĂNG NHẬP (guard "tenant", xem TenantAuthController) — xem hợp đồng/hoá đơn/
// thanh toán CỦA CHÍNH MÌNH qua giao diện WEB (session). Bản API tương đương (token Sanctum) xem
// app\Http\Controllers\Api\Minihouse\Portal\TenantPortalApiController — cả 2 dùng CHUNG
// InteractsWithTenantPortalData + TenantPortalService cho mọi logic truy vấn/kiểm tra quyền sở
// hữu, tránh lệch nhau (đúng lớp lỗi đã gặp nhiều lần trong module này).
class TenantPortalController extends Controller
{
    use InteractsWithTenantPortalData;

    public function dashboard(): View
    {
        $tenant = $this->tenant();

        $contracts = TenantPortalService::tenantContracts($tenant);
        $activeContract = $contracts->firstWhere('status', Contract::STATUS_ACTIVE);

        $unpaidTotal = TenantPortalService::unpaidTotalForActiveContract($activeContract);
        $unpaidInvoiceCount = TenantPortalService::unpaidInvoiceCount($contracts);

        $unreadNotificationCount = PortalNotification::where('tenant_id', $tenant->id)
            ->whereNull('read_at')
            ->count();

        return view('minihouse::portal.dashboard', [
            'tenant'                  => $tenant,
            'activeContract'          => $activeContract,
            'unpaidTotal'             => $unpaidTotal,
            'unpaidInvoiceCount'      => $unpaidInvoiceCount,
            'building'                => $activeContract?->room?->building,
            'unreadNotificationCount' => $unreadNotificationCount,
        ]);
    }

    // "Thông báo" — gộp 4 nguồn (hoá đơn mới, nhắc việc đến hạn, thông báo chung, phản hồi đã xử lý)
    // vào 1 bảng duy nhất (xem PortalNotificationService) — xem là tự đánh dấu ĐÃ ĐỌC toàn bộ luôn,
    // đơn giản hơn nút "đánh dấu đã đọc" riêng cho từng dòng mà không mất nhiều giá trị thực tế.
    public function notifications(): View
    {
        $tenant = $this->tenant();

        $notifications = PortalNotification::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        PortalNotification::where('tenant_id', $tenant->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('minihouse::portal.notifications.index', ['notifications' => $notifications]);
    }

    public function showFeedbackForm(): View
    {
        $tenant = $this->tenant();
        $activeContract = TenantPortalService::tenantContracts($tenant)->firstWhere('status', Contract::STATUS_ACTIVE);

        return view('minihouse::portal.feedback', ['tenant' => $tenant, 'activeContract' => $activeContract]);
    }

    public function storeFeedback(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->createFeedback($this->tenant(), $data);

        return redirect()->route('minihouse.portal.dashboard')
            ->with('portal_info', 'Đã gửi phản hồi — cảm ơn bạn! Chủ nhà sẽ xem và phản hồi lại sớm.');
    }

    // "Lịch sử thanh toán" — CHỈ tính InvoicePayment (từng lần ghi nhận tiền vào), khác "Hoá đơn"
    // (từng kỳ phải trả) — 1 hoá đơn có thể trả làm nhiều lần, khách muốn đối chiếu từng lần chuyển
    // khoản/tiền mặt thực tế đã ghi nhận, không phải từng kỳ hoá đơn.
    public function payments(): View
    {
        $payments = $this->paymentsQuery($this->tenant())->paginate(20);

        return view('minihouse::portal.payments.index', ['payments' => $payments]);
    }

    public function contracts(): View
    {
        $tenant = $this->tenant();
        $contracts = TenantPortalService::tenantContracts($tenant)->sortByDesc('start_date');

        return view('minihouse::portal.contracts.index', ['contracts' => $contracts]);
    }

    public function showContract(int $id): View
    {
        $tenant = $this->tenant();
        $contract = TenantPortalService::tenantContracts($tenant)->firstWhere('id', $id);

        abort_unless($contract, 403);

        return view('minihouse::portal.contracts.show', ['contract' => $contract]);
    }

    public function invoices(): View
    {
        $invoices = $this->invoicesQuery($this->tenant())->paginate(12);

        return view('minihouse::portal.invoices.index', ['invoices' => $invoices]);
    }

    public function showInvoice(int $id): View
    {
        $invoice = $this->findOwnedInvoice($this->tenant(), $id);

        return view('minihouse::portal.invoices.show', ['invoice' => $invoice]);
    }

    public function payInvoice(Request $request, int $id): View|RedirectResponse
    {
        $invoice = $this->findOwnedInvoiceForPayment($this->tenant(), $id);

        if ($invoice->remainingAmount() <= 0) {
            return redirect()->route('minihouse.portal.invoices.show', $invoice->id)
                ->with('portal_info', 'Hoá đơn này đã được thanh toán đủ.');
        }

        $returnUrl = route('minihouse.portal.invoices.show', $invoice->id);

        try {
            $payment = $this->createPayment($invoice, $returnUrl, $request->ip());
        } catch (\Throwable $e) {
            return redirect()->route('minihouse.portal.invoices.show', $invoice->id)
                ->withErrors(['payment' => $e->getMessage()]);
        }

        if (! $payment) {
            return redirect()->route('minihouse.portal.invoices.show', $invoice->id)
                ->withErrors(['payment' => 'Chủ nhà chưa cấu hình thanh toán trực tuyến cho toà nhà này — vui lòng liên hệ trực tiếp để thanh toán.']);
        }

        return view('minihouse::portal.invoices.pay', ['invoice' => $invoice, 'payment' => $payment]);
    }

    // Trang khách thuê tự đặt/đổi mật khẩu — cách đăng nhập THAY THẾ cho OTP (miễn phí, không tốn
    // gửi Zalo/SMS mỗi lần, xem TenantAuthController::loginWithPassword()). KHÔNG bắt nhập mật khẩu
    // cũ dù đã có sẵn — đã vào được trang này tức đã đăng nhập hợp lệ (bằng OTP HOẶC mật khẩu cũ),
    // đủ xác minh danh tính rồi; bắt nhập lại mật khẩu cũ chỉ tạo ra 1 lối kẹt vĩnh viễn nếu khách
    // quên mật khẩu (không có luồng "quên mật khẩu" riêng), vì mật khẩu mã hoá một chiều không xem
    // lại được — trong khi OTP LUÔN LÀ đường khôi phục an toàn sẵn có nếu ai đó mượn máy đổi mật khẩu
    // trái phép (chủ tài khoản vẫn nhận được OTP về đúng SĐT của họ để lấy lại quyền truy cập).
    public function showPasswordForm(): View
    {
        return view('minihouse::portal.password', ['tenant' => $this->tenant()]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $tenant->update(['password' => $data['password']]);

        return back()->with('portal_info', 'Đã đặt mật khẩu — lần sau bạn có thể đăng nhập bằng SĐT + mật khẩu, không cần chờ mã OTP nữa.');
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Auth::guard('tenant')->user();

        return $tenant;
    }
}
