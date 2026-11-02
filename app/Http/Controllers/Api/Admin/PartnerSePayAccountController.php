<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\PartnerSePayAccount;

// Tài khoản nhận tiền chuyển khoản của đối tác (SePay / Pay2S / xác nhận thủ công). Mỗi đối tác có MỘT tài khoản mặc định (cả đối tác) và có thể thêm
// MỘT tài khoản riêng cho từng chi nhánh (category_id) — đơn của chi nhánh dùng tài khoản của chi nhánh (hoặc chi nhánh cha), không có thì dùng tài khoản mặc định.
class PartnerSePayAccountController extends Controller
{
    /**
     * Danh sách tài khoản nhận tiền của MỌI đối tác — cùng dữ liệu và bộ lọc với bảng CMS "SePay / Pay2S theo đối tác". Chỉ Super Admin.
     * Lọc: provider, is_active, category_id, q (tên đối tác hoặc số tài khoản), per_page.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin xem được danh sách tài khoản của mọi đối tác.');
        $filters = $request->validate([
            'provider' => ['nullable', Rule::in(array_keys(PartnerSePayAccount::PROVIDERS))],
            'is_active' => ['nullable', 'boolean'],
            'category_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PartnerSePayAccount::query()->with(['partner', 'category'])->orderByDesc('updated_at');

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $query->where(fn ($q) => $q->where('account_number', 'like', $like)->orWhere('account_holder', 'like', $like)
                ->orWhereHas('partner', fn ($p) => $p->where('name', 'like', $like)->orWhere('legal_name', 'like', $like)));
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $page->getCollection()->transform(fn (PartnerSePayAccount $account) => [
            'partner' => $account->partner ? ['id' => $account->partner->id, 'name' => $account->partner->name] : null,
            'category_id' => $account->category_id,
            'branch' => $account->category_id ? ($account->category?->name) : null,
            'provider' => $account->provider,
            'bank_code' => $account->bank_code,
            'bank_bin' => $account->bank_bin,
            'account_number' => $account->account_number,
            'account_holder' => $account->account_holder,
            'is_active' => (bool) $account->is_active,
            'has_webhook_token' => $account->provider === PartnerSePayAccount::PROVIDER_PAY2S && (bool) $account->webhook_token,
            'eligible' => (bool) $account->partner?->usesDirectPayment(),
            'note' => $account->note,
            'updated_at' => $account->updated_at?->toIso8601String(),
        ]);

        return response()->json($page);
    }

    /** Tài khoản của 1 phạm vi: mặc định (không gửi category_id) hoặc của chi nhánh (?category_id=). */
    public function show(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeAccess($request, $partner, false);
        $categoryId = $this->branchId($request, $partner);

        return response()->json(['data' => $this->data($partner, $this->accountOf($partner, $categoryId), $categoryId)]);
    }

    /** Mọi tài khoản của đối tác: mặc định (category_id = null) và của từng chi nhánh. */
    public function accounts(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeAccess($request, $partner, false);

        $accounts = $partner->sePayAccounts()->with('category')->orderByRaw('category_id is not null')->orderBy('category_id')->get()
            ->map(fn (PartnerSePayAccount $account) => $this->data($partner, $account, $account->category_id))->values();

        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeAccess($request, $partner, true);
        $categoryId = $this->branchId($request, $partner);
        $account = $this->accountOf($partner, $categoryId);
        $banks = config('banks.list', []);
        $data = $request->validate([
            'provider' => ['sometimes', Rule::in(array_keys(PartnerSePayAccount::PROVIDERS))],
            'category_id' => ['nullable', 'integer'],
            'webhook_token' => ['nullable', 'string', 'max:500'],
            'bank_code' => ['required', Rule::in(array_keys($banks))],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z.-]+$/', Rule::unique('partner_sepay_accounts')->ignore($account?->id)],
            'account_holder' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (($data['is_active'] ?? true) && \Modules\Payment\Entities\PartnerPayOsAccount::query()->where('partner_id', $partner->id)->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Đối tác đang dùng kênh PayOS riêng. Tắt kênh PayOS trước khi bật SePay / Pay2S (mỗi đối tác chỉ dùng một kênh).',
                'errors' => ['is_active' => ['Đối tác đang dùng kênh PayOS riêng.']],
            ], 422);
        }

        if ($categoryId && ($data['is_active'] ?? true) && BranchPayOsAccount::query()->where('category_id', $categoryId)->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Chi nhánh này đang dùng kênh PayOS riêng. Tắt kênh PayOS của chi nhánh trước khi bật tài khoản chuyển khoản cho chi nhánh.',
                'errors' => ['category_id' => ['Chi nhánh đang dùng kênh PayOS riêng.']],
            ], 422);
        }

        $bank = $banks[$data['bank_code']];
        $provider = $data['provider'] ?? $account?->provider ?? PartnerSePayAccount::PROVIDER_SEPAY;
        // Token webhook chỉ dùng cho Pay2S; để trống/không gửi = giữ nguyên token đang lưu.
        if ($provider !== PartnerSePayAccount::PROVIDER_PAY2S || ! filled($data['webhook_token'] ?? null)) {
            unset($data['webhook_token']);
        }
        unset($data['category_id']);

        $account = PartnerSePayAccount::query()->updateOrCreate(
            ['partner_id' => $partner->id, 'category_id' => $categoryId],
            [...$data, 'provider' => $provider, 'bank_bin' => $bank['bin'], 'account_number' => trim($data['account_number']), 'account_holder' => mb_strtoupper(trim($data['account_holder'])), 'updated_by' => $request->user()->id]
        );

        return response()->json(['message' => 'Đã lưu tài khoản ' . (PartnerSePayAccount::PROVIDERS[$provider] ?? 'SePay') . ($categoryId ? ' của chi nhánh.' : ' của đối tác.'), 'data' => $this->data($partner->fresh(), $account->fresh(), $categoryId)]);
    }

    /** Xoá tài khoản của một phạm vi (?category_id= để xoá tài khoản của chi nhánh; không gửi = xoá tài khoản mặc định). Link đã tạo vẫn được tra cứu. */
    public function destroy(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeAccess($request, $partner, true);
        $categoryId = $this->branchId($request, $partner);
        $account = $this->accountOf($partner, $categoryId);

        if (! $account) {
            return response()->json(['message' => 'Không có tài khoản nhận tiền ở phạm vi này.'], 404);
        }

        $account->delete();

        return response()->json(['message' => $categoryId ? 'Đã xoá tài khoản của chi nhánh — chi nhánh dùng tài khoản mặc định của đối tác (nếu có).' : 'Đã xoá tài khoản mặc định của đối tác.']);
    }

    /**
     * Đối tác bấm "Liên kết ngân hàng Pay2S": tạo phiên Hosted Link. App mở data.hosted_link_url (webview / tab mới) để đối tác nhập
     * thông tin ngân hàng + OTP trên trang của Pay2S, rồi gọi GET …/pay2s-link/{session_id} mỗi 3–5 giây cho tới khi connected / failed.
     * Gửi category_id để liên kết tài khoản riêng cho một chi nhánh (không gửi = tài khoản mặc định của đối tác).
     */
    public function pay2sLinkStart(Request $request, Partner $partner, \App\Services\Payment\Pay2SBankHubService $bankHub): JsonResponse
    {
        $this->authorizeAccess($request, $partner, true);
        $categoryId = $this->branchId($request, $partner);

        if (\Modules\Payment\Entities\PartnerPayOsAccount::query()->where('partner_id', $partner->id)->where('is_active', true)->exists()) {
            return response()->json(['message' => 'Đối tác đang dùng kênh PayOS riêng. Tắt kênh PayOS trước khi liên kết Pay2S (mỗi đối tác chỉ dùng một kênh).'], 422);
        }

        if (! $bankHub->configured()) {
            return response()->json(['message' => 'Chưa cấu hình khoá Partner API của Pay2S. Liên hệ 365 HOME để bật tính năng liên kết ngân hàng.'], 422);
        }

        try {
            $session = $bankHub->createSession($partner, $request->user()->id, $categoryId);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['message' => 'Đã tạo phiên liên kết. Mở hosted_link_url để đối tác nhập thông tin ngân hàng và OTP.', 'data' => $bankHub->present($session)], 201);
    }

    /** Hỏi kết quả phiên liên kết; khi connected hệ thống đã tự lưu tài khoản (và token webhook nếu Pay2S cấp). */
    public function pay2sLinkStatus(Request $request, Partner $partner, string $session, \App\Services\Payment\Pay2SBankHubService $bankHub): JsonResponse
    {
        $this->authorizeAccess($request, $partner, true);
        $record = \Modules\Payment\Entities\PartnerPay2sLinkSession::query()->where('partner_id', $partner->id)->where('session_id', $session)->firstOrFail();

        try {
            $record = $bankHub->refresh($record);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $account = $record->applied ? $this->data($partner->fresh(), $this->accountOf($partner, $record->category_id), $record->category_id) : null;

        return response()->json(['data' => $bankHub->present($record) + ['account' => $account]]);
    }

    /** Nút "Kiểm tra liên kết": tài khoản Pay2S của đối tác (hoặc của chi nhánh, ?category_id=) đã liên kết xong / đủ token webhook / đã nhận giao dịch chưa. */
    public function pay2sLinkCheck(Request $request, Partner $partner, \App\Services\Payment\Pay2SBankHubService $bankHub): JsonResponse
    {
        $this->authorizeAccess($request, $partner, false);
        $account = $this->accountOf($partner, $this->branchId($request, $partner));

        if (! $account) {
            return response()->json(['message' => 'Đối tác chưa khai báo tài khoản nhận tiền.'], 404);
        }

        return response()->json(['data' => $bankHub->verify($account)]);
    }

    /** category_id gửi lên (body hoặc query) phải là chi nhánh / khu vực của chính đối tác này. null = tài khoản mặc định. */
    private function branchId(Request $request, Partner $partner): ?int
    {
        $categoryId = $request->input('category_id');

        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        if (! is_numeric($categoryId) || ! app(PartnerPayOsChannelService::class)->ownsBranch($partner, (int) $categoryId)) {
            throw ValidationException::withMessages(['category_id' => 'Chi nhánh không thuộc đối tác này.']);
        }

        return (int) $categoryId;
    }

    private function accountOf(Partner $partner, ?int $categoryId): ?PartnerSePayAccount
    {
        return PartnerSePayAccount::query()->where('partner_id', $partner->id)->where('category_id', $categoryId)->first();
    }

    private function data(Partner $partner, ?PartnerSePayAccount $account, ?int $categoryId = null): array
    {
        return [
            'configured' => (bool) $account,
            // Phạm vi: category_id = null là tài khoản mặc định của cả đối tác; có giá trị là tài khoản riêng của chi nhánh đó.
            'category_id' => $categoryId,
            'branch' => $categoryId ? ($account?->category?->name ?? \Modules\Category\Entities\Category::withoutGlobalScopes()->whereKey($categoryId)->value('name')) : null,
            'provider' => $account?->provider ?? PartnerSePayAccount::PROVIDER_SEPAY,
            // Token chỉ có nghĩa với Pay2S (SePay xác thực bằng khoá chung HMAC).
            'has_webhook_token' => $account?->provider === PartnerSePayAccount::PROVIDER_PAY2S && (bool) $account->webhook_token,
            'is_active' => (bool) $account?->is_active,
            'bank_code' => $account?->bank_code,
            'bank_bin' => $account?->bank_bin,
            'account_number' => $account?->account_number,
            'account_holder' => $account?->account_holder,
            'note' => $account?->note,
            'eligible' => $partner->usesDirectPayment(),
            // Xác nhận thủ công không có webhook.
            'webhook_url' => match ($account?->provider ?? 'sepay') {
                PartnerSePayAccount::PROVIDER_MANUAL => null,
                PartnerSePayAccount::PROVIDER_PAY2S => \App\Support\Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s'),
                default => \App\Support\SePayConfig::get('webhook_url') ?: route('webhook.sepay'),
            },
            'code_prefix' => \App\Support\SePayConfig::get('code_prefix', 'DH'),
        ];
    }

    private function authorizeAccess(Request $request, Partner $partner, bool $manage): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        $user = $request->user();
        $isOwner = $user->partner_id === $partner->id && $partner->payos_self_setup_enabled;
        abort_unless($user->isSuperAdmin() || (!$manage && $user->partner_id === $partner->id) || ($manage && $isOwner), 403);
    }
}
