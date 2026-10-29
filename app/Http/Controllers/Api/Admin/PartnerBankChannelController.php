<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Payment\PartnerBankChannelService;
use App\Services\Payment\PayOsAccountResolver;
use App\Support\Banks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Payment\Entities\BankTransaction;

/**
 * Kênh CHUYỂN KHOẢN NGÂN HÀNG (VietQR + SePay) của đối tác Homestay — dùng khi đối tác không đăng ký được PayOS: khách chuyển thẳng
 * vào tài khoản đối tác, SePay báo về webhook để tự xác nhận đơn (xem App\Services\Payment\SepayWebhookService).
 *
 * Xem: Super Admin và mọi tài khoản của chính đối tác (không lộ khoá webhook). Lưu / cấp lại khoá: chỉ Super Admin — đây là nơi đổi
 * tiền chảy về tài khoản nào. Khoá webhook chỉ trả về cho Super Admin để dán vào SePay.
 */
class PartnerBankChannelController extends Controller
{
    public function __construct(private PartnerBankChannelService $channels) {}

    // GET /api/admin/partners/{partner}/bank-channel
    public function show(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);

        return response()->json(['data' => $this->data($request, $partner)]);
    }

    // POST /api/admin/partners/{partner}/bank-channel — body: use_financial_account?, bank_code?, account_number?, account_holder?, is_active?, note?
    // Tạo mới không gửi tài khoản (hoặc use_financial_account=true) → lấy đúng tài khoản ở tab Tài chính. Nhập tài khoản khác thì chủ tài
    // khoản phải trùng tab Tài chính, sai → 422. Tạo mới tự sinh khoá webhook (data.webhook_key) để dán vào mục Bảo mật trên SePay.
    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $data = $request->validate([
            'use_financial_account' => ['sometimes', 'boolean'],
            'bank_code'             => ['sometimes', 'string', Rule::in(Banks::codes())],
            'account_number'        => ['sometimes', 'string', 'regex:/^[0-9A-Za-z]{4,30}$/'],
            'account_holder'        => ['nullable', 'string', 'max:255'],
            'is_active'             => ['sometimes', 'boolean'],
            'note'                  => ['nullable', 'string', 'max:255'],
        ]);

        $this->channels->save($partner, $data, $request->user()->id);

        return response()->json(['message' => 'Đã lưu kênh chuyển khoản của đối tác.', 'data' => $this->data($request, $partner->fresh())]);
    }

    // POST /api/admin/partners/{partner}/bank-channel/regenerate-key — cấp khoá webhook mới (khoá cũ hết hiệu lực ngay, phải dán lại vào SePay).
    public function regenerateKey(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $this->channels->regenerateKey($partner, $request->user()->id);

        return response()->json(['message' => 'Đã cấp khoá mới — dán lại vào mục Bảo mật của webhook trên SePay.', 'data' => $this->data($request, $partner->fresh())]);
    }

    // GET /api/admin/partners/{partner}/bank-transactions?status=matched|unmatched|amount_mismatch|needs_review — tiền vào SePay báo về.
    public function transactions(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);
        $request->validate(['status' => ['nullable', Rule::in([BankTransaction::STATUS_MATCHED, BankTransaction::STATUS_UNMATCHED, BankTransaction::STATUS_AMOUNT_MISMATCH, BankTransaction::STATUS_NEEDS_REVIEW])]]);

        $page = BankTransaction::query()
            ->where('partner_id', $partner->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->with('order:id,order_code,status')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        $page->getCollection()->transform(fn (BankTransaction $txn) => [
            'id'             => $txn->id,
            'amount'         => $txn->amount,
            'content'        => $txn->content,
            'gateway'        => $txn->gateway,
            'account_number' => $txn->account_number,
            'reference_code' => $txn->reference_code,
            'transacted_at'  => $txn->transacted_at?->toIso8601String(),
            'status'         => $txn->status,
            'applied_as'     => $txn->applied_as,
            'note'           => $txn->note,
            'order'          => $txn->order ? ['order_code' => $txn->order->order_code, 'status' => $txn->order->status] : null,
        ]);

        return response()->json($page);
    }

    private function data(Request $request, Partner $partner): array
    {
        $channel = $partner->bankChannel;
        $isSuperAdmin = $request->user()->isSuperAdmin();

        return [
            'partner_id'        => $partner->id,
            'configured'        => $channel !== null,
            'is_active'         => (bool) $channel?->is_active,
            // Đang thực sự dùng để nhận tiền đặt phòng chưa (kênh bật + hợp đồng thu trực tiếp đã hiệu lực).
            'in_use'            => PayOsAccountResolver::bankChannelFor($partner->id) !== null,
            'flow_effective_at' => $partner->payment_flow_effective_at?->toIso8601String(),
            'bank_code'         => $channel?->bank_code,
            'bank_name'         => $channel?->bank()['short_name'] ?? null,
            'account_number'    => $channel?->account_number,
            'account_holder'    => $channel?->account_holder,
            // Tài khoản ở tab Tài chính (nhập lúc đăng ký đối tác) và kênh có đang dùng đúng tài khoản đó không.
            'financial_account' => $this->channels->financialAccount($partner),
            'matches_financial' => $this->channels->matchesFinancial($partner, $channel),
            'note'              => $channel?->note,
            'last_webhook_at'   => $channel?->last_webhook_at?->toIso8601String(),
            'webhook_url'       => route('api.webhook.sepay'),
            'webhook_key'       => $isSuperAdmin ? $channel?->webhook_key : null,
            'can_manage'        => $isSuperAdmin,
            // Từng chi nhánh của đối tác: tiền đặt phòng online đang về đâu.
            'branches'          => $this->channels->branchDestinations($partner),
        ];
    }

    private function authorizeView(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }

    private function authorizeManage(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Kênh chuyển khoản của đối tác do 365home cấu hình — liên hệ 365home để được hỗ trợ.');
    }
}
