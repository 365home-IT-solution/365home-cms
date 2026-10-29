<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Payment\Entities\PartnerSePayAccount;

class PartnerSePayAccountController extends Controller
{
    public function show(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeAccess($request, $partner, false);

        return response()->json(['data' => $this->data($partner)]);
    }

    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeAccess($request, $partner, true);
        $account = PartnerSePayAccount::query()->where('partner_id', $partner->id)->first();
        $banks = config('banks.list', []);
        $data = $request->validate([
            'bank_code' => ['required', Rule::in(array_keys($banks))],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z.-]+$/', Rule::unique('partner_sepay_accounts')->ignore($account?->id)],
            'account_holder' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if (($data['is_active'] ?? true) && \Modules\Payment\Entities\PartnerPayOsAccount::query()->where('partner_id', $partner->id)->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Đối tác đang dùng kênh PayOS riêng. Tắt kênh PayOS trước khi bật SePay (mỗi đối tác chỉ dùng một kênh).',
                'errors' => ['is_active' => ['Đối tác đang dùng kênh PayOS riêng.']],
            ], 422);
        }

        $bank = $banks[$data['bank_code']];
        $account = PartnerSePayAccount::query()->updateOrCreate(
            ['partner_id' => $partner->id],
            [...$data, 'bank_bin' => $bank['bin'], 'account_number' => trim($data['account_number']), 'account_holder' => mb_strtoupper(trim($data['account_holder'])), 'updated_by' => $request->user()->id]
        );

        return response()->json(['message' => 'Đã lưu tài khoản SePay của đối tác.', 'data' => $this->data($partner->fresh())]);
    }

    private function data(Partner $partner): array
    {
        $account = $partner->sePayAccount;

        return [
            'configured' => (bool) $account,
            'is_active' => (bool) $account?->is_active,
            'bank_code' => $account?->bank_code,
            'bank_bin' => $account?->bank_bin,
            'account_number' => $account?->account_number,
            'account_holder' => $account?->account_holder,
            'note' => $account?->note,
            'eligible' => $partner->usesDirectPayment(),
            'webhook_url' => \App\Support\SePayConfig::get('webhook_url') ?: route('webhook.sepay'),
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
