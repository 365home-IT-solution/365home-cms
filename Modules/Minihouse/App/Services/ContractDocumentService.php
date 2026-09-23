<?php

namespace Modules\Minihouse\App\Services;

use App\Models\User;
use App\Services\AdminNotificationService;
use App\Services\PdfSigning\ContractPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Exceptions\ContractDocumentException;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractDocument;
use Modules\Minihouse\App\Models\ContractDocumentEvent;
use Modules\Minihouse\App\Models\ContractRenewal;
use Modules\Minihouse\App\Models\ContractSignature;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Support\HomestayBridge;

// Điểm hội tụ DUY NHẤT xử lý toàn bộ state machine của hợp đồng điện tử (Mức A — vẽ tay + OTP +
// hash + nhật ký, xem docs/be-minihouse-contract-signing.md) — Controller (admin lẫn Portal) chỉ
// validate input HTTP rồi gọi service, không tự viết logic nghiệp vụ, đúng convention
// InvoiceGenerationService/PortalNotificationService.
//
// draft --send()--> awaiting_tenant --signAsTenant()--> awaiting_owner --signAsOwner()--> signed
//   ^--------------------- recall() (chỉ khi chưa ai ký) ---------------------|
class ContractDocumentService
{
    private const SIGNATURE_MAX_BYTES = 200 * 1024;

    public function getOrCreateDraft(Contract $contract): ContractDocument
    {
        $doc = $contract->document;

        if ($doc) {
            return $doc;
        }

        $contract->loadMissing([
            'room'          => fn ($q) => $q->withoutGlobalScopes(),
            'room.building' => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        return ContractDocument::create([
            'contract_id'  => $contract->id,
            'status'       => ContractDocument::STATUS_DRAFT,
            'no'           => sprintf('HD-%d-%04d', now()->year, $contract->id),
            'signed_place' => $contract->room?->building?->address,
        ]);
    }

    /**
     * Bộ trường theo mục 4.1 — draft đọc SỐNG từ bảng nguồn, đã gửi thì đọc từ snapshot đông cứng.
     * $includeInternal=true (admin) trả kèm ip/user_agent của chữ ký; Portal luôn false (mục 4.4).
     */
    public function buildFields(ContractDocument $doc, bool $includeInternal = false): array
    {
        $contract = Contract::withoutGlobalScopes()->find($doc->contract_id);

        $identity = $doc->status === ContractDocument::STATUS_DRAFT
            ? $this->snapshotFields($contract)
            : ($doc->snapshot ?? []);

        return array_merge($identity, [
            'contract_id'   => $doc->contract_id,
            'status'        => $doc->status,
            'no'            => $doc->no,
            'sign_date'     => $doc->sign_date?->toDateString(),
            'signed_place'  => $doc->signed_place,
            'max_occupants' => $doc->max_occupants,
            'payment_day'   => $doc->payment_day,
            'extra_terms'   => $doc->extra_terms,

            'html_url'    => URL::temporarySignedRoute('api.minihouse.contract-document.html', now()->addMinutes(30), ['document' => $doc->id]),
            'pdf_url'     => $this->currentPdfUrl($doc),
            'sealed_hash' => $doc->sealed_hash,
            'sealed_at'   => $doc->sealed_at?->toIso8601String(),
            'final_hash'  => $doc->final_hash,
            'verify_code' => $doc->verify_code,

            'signatures' => $doc->signatures->map(fn (ContractSignature $s) => $this->signaturePayload($s, $includeInternal))->all(),
            'sent_at'    => $doc->sent_at?->toIso8601String(),

            'created_at' => $doc->created_at?->toIso8601String(),
            'updated_at' => $doc->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Chỉ sửa được khi còn draft — 4 field CCCD cấp ngày/nơi cấp ghi THẲNG xuống Tenant/Building
     * (không lưu trùng ở documents, xem mục 4.2/12), các field còn lại ghi vào chính bản ghi.
     */
    public function update(ContractDocument $doc, array $data): ContractDocument
    {
        if ($doc->status !== ContractDocument::STATUS_DRAFT) {
            throw new ContractDocumentException('Chỉ sửa được khi hợp đồng điện tử đang ở trạng thái nháp.', 409);
        }

        $contract = Contract::withoutGlobalScopes()
            ->with([
                'tenant'        => fn ($q) => $q->withoutGlobalScopes(),
                'room'          => fn ($q) => $q->withoutGlobalScopes(),
                'room.building' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->find($doc->contract_id);

        $ownerCard = array_filter([
            'owner_id_card_issued_date'  => $data['owner_id_card_issued_date'] ?? null,
            'owner_id_card_issued_place' => $data['owner_id_card_issued_place'] ?? null,
        ], fn ($v) => $v !== null);

        if ($ownerCard && $contract->room?->building) {
            $contract->room->building->update($ownerCard);
        }

        $tenantCard = array_filter([
            'id_card_issued_date'  => $data['tenant_id_card_issued_date'] ?? null,
            'id_card_issued_place' => $data['tenant_id_card_issued_place'] ?? null,
        ], fn ($v) => $v !== null);

        if ($tenantCard && $contract->tenant) {
            $contract->tenant->update($tenantCard);
        }

        $doc->fill(array_intersect_key($data, array_flip([
            'sign_date', 'signed_place', 'max_occupants', 'payment_day', 'extra_terms',
        ])));
        $doc->save();

        return $doc->refresh();
    }

    /**
     * Niêm phong + gửi cho khách ký — xem mục 4.3. Sau bước này nội dung hợp đồng BẤT BIẾN (đọc từ
     * snapshot), muốn sửa phải recall() trước (chỉ khi chưa ai ký).
     */
    public function send(ContractDocument $doc, User $actor, Request $request): ContractDocument
    {
        if ($doc->status !== ContractDocument::STATUS_DRAFT) {
            throw new ContractDocumentException('Bản hợp đồng đã gửi cho khách rồi.', 409);
        }

        $contract = Contract::withoutGlobalScopes()
            ->with([
                'tenant'        => fn ($q) => $q->withoutGlobalScopes(),
                'room'          => fn ($q) => $q->withoutGlobalScopes(),
                'room.building' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->find($doc->contract_id);

        if ($contract->status !== Contract::STATUS_ACTIVE) {
            throw new ContractDocumentException('Hợp đồng đã kết thúc, không gửi ký được.', 409);
        }

        $identity = $this->snapshotFields($contract);
        $errors   = $this->validateForSend($doc, $identity);

        if ($errors) {
            throw new ContractDocumentException('Chưa đủ thông tin để gửi hợp đồng cho khách ký.', 422, $errors);
        }

        DB::transaction(function () use ($doc, $identity, $actor, $request) {
            $doc->snapshot         = $identity;
            $doc->template_version = 'v1';
            // Sinh TRƯỚC khi render — verify_code không đổi qua các lần ký sau, cần có mặt ngay ở
            // bản niêm phong đầu tiên để in vào chân trang (mục 9), không thể vá lại SAU khi đã hash
            // (đổi nội dung PDF là đổi hash, tự phá chính bản niêm phong vừa tính).
            $doc->verify_code = $this->generateVerifyCode();

            $rendered = $this->renderAndStorePdf($doc, $this->fieldsForRender($doc, $identity), [], 'sealed.pdf');

            $doc->sealed_pdf_path = $rendered['path'];
            $doc->sealed_hash     = $rendered['hash'];
            $doc->sealed_at       = now();
            $doc->status          = ContractDocument::STATUS_AWAITING_TENANT;
            $doc->sent_at         = now();
            $doc->save();

            $this->logEvent($doc, ContractDocumentEvent::EVENT_SEALED, ContractDocumentEvent::ACTOR_ADMIN, (string) $actor->id, $this->actorName($actor), ['hash' => $doc->sealed_hash], $request);
            $this->logEvent($doc, ContractDocumentEvent::EVENT_SENT, ContractDocumentEvent::ACTOR_ADMIN, (string) $actor->id, $this->actorName($actor), null, $request);
        });

        if ($contract->tenant) {
            PortalNotificationService::notify(
                $contract->tenant,
                'contract_to_sign',
                'Hợp đồng phòng ' . ($contract->room?->code ?? '') . ' chờ bạn ký',
                'Vào Portal để đọc và ký hợp đồng số ' . $doc->no . '.',
                '/minihouse/portal/contracts/' . $contract->id
            );
        }

        return $doc->refresh();
    }

    /** Chỉ khi awaiting_tenant — gửi OTP RIÊNG cho việc ký (namespace cache tách khỏi OTP đăng nhập). */
    public function requestTenantOtp(ContractDocument $doc, Request $request): array
    {
        if ($doc->status !== ContractDocument::STATUS_AWAITING_TENANT) {
            throw new ContractDocumentException('Hợp đồng này đã được xác nhận trước đó.', 409);
        }

        $contract = Contract::withoutGlobalScopes()
            ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes(), 'room' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($doc->contract_id);

        $tenant = $contract->tenant;

        if (! $tenant || blank($tenant->phone)) {
            throw new ContractDocumentException('Khách thuê chưa có số điện thoại để gửi mã xác nhận.', 422);
        }

        $roomCode = $contract->room?->code ?? '';
        $no       = $doc->no;

        $result = app(TenantOtpService::class)->generateAndSend(
            $tenant->phone,
            $tenant->fullname,
            'contract_sign',
            fn (string $code) => "Ma ky hop dong {$no} phong {$roomCode}: {$code}. Hieu luc 5 phut. Khong chia se ma nay."
        );

        if (! $result['sent']) {
            throw new ContractDocumentException($result['reason'] ?? 'Không gửi được mã xác thực.', 422);
        }

        $this->logEvent($doc, ContractDocumentEvent::EVENT_OTP_SENT, ContractDocumentEvent::ACTOR_TENANT, (string) $tenant->id, $tenant->fullname, ['channel' => $result['channel']], $request);

        return $result;
    }

    /** Khách vẽ chữ ký + OTP — chỉ ghi nhận CONSENT + chữ ký thật (không phải PKI), xem mục 5.3. */
    public function signAsTenant(ContractDocument $doc, array $payload, Tenant $tenant, Request $request): ContractDocument
    {
        if ($doc->status !== ContractDocument::STATUS_AWAITING_TENANT) {
            throw new ContractDocumentException('Hợp đồng này đã được xác nhận trước đó.', 409);
        }

        if (! hash_equals((string) $doc->sealed_hash, (string) ($payload['document_hash'] ?? ''))) {
            throw new ContractDocumentException('Bản hợp đồng đã thay đổi, tải lại trước khi ký.', 409);
        }

        $otpResult = app(TenantOtpService::class)->verify(
            $tenant->phone,
            (string) ($payload['otp_code'] ?? ''),
            'contract_sign',
            $payload['otp_request_id'] ?? null
        );

        if (! $otpResult['valid']) {
            $this->logEvent($doc, ContractDocumentEvent::EVENT_OTP_FAILED, ContractDocumentEvent::ACTOR_TENANT, (string) $tenant->id, $tenant->fullname, null, $request);

            throw new ContractDocumentException($otpResult['reason'] ?? 'Mã xác thực không đúng.', 422, ['otp_code' => $otpResult['reason'] ?? 'Mã xác thực không đúng.']);
        }

        if (! ($payload['consent'] ?? false)) {
            throw new ContractDocumentException('Bạn cần đồng ý điều khoản trước khi ký.', 422, ['consent' => 'Bạn cần đồng ý điều khoản trước khi ký.']);
        }

        $signature = $this->decodeAndStoreSignature($doc, (string) ($payload['signature'] ?? ''), 'tenant');
        $dataUri   = 'data:' . $signature['mime'] . ';base64,' . base64_encode($signature['bytes']);

        DB::transaction(function () use ($doc, $payload, $tenant, $request, $signature, $dataUri, $otpResult) {
            $rendered = $this->renderAndStorePdf(
                $doc,
                $this->fieldsForRender($doc, $doc->snapshot ?? []),
                ['tenant' => ['image_url' => $dataUri]],
                'final.pdf'
            );

            ContractSignature::create([
                'document_id'          => $doc->id,
                'party'                => ContractSignature::PARTY_TENANT,
                'signer_type'          => ContractSignature::SIGNER_TYPE_TENANT,
                'signer_id'            => (string) $tenant->id,
                'signer_name'          => filled($payload['signer_name'] ?? null) ? $payload['signer_name'] : $tenant->fullname,
                'signer_phone'         => $tenant->phone,
                'signature_path'       => $signature['path'],
                // Hash của file mà khách ĐÃ KÝ LÊN — bản niêm phong gốc, chưa có chữ ký nào.
                'signed_document_hash' => $doc->sealed_hash,
                'signed_at'            => now(),
                'ip'                   => $request->ip(),
                'user_agent'           => (string) $request->userAgent(),
                'auth_method'          => ($otpResult['channel'] ?? null) === 'sms' ? ContractSignature::AUTH_METHOD_OTP_SMS : ContractSignature::AUTH_METHOD_OTP_ZALO,
                'otp_request_id'       => $payload['otp_request_id'] ?? null,
                'otp_verified_at'      => now(),
                'consent_text'         => (string) ($payload['consent_text'] ?? ''),
                'created_at'           => now(),
            ]);

            $doc->final_pdf_path = $rendered['path'];
            $doc->final_hash     = $rendered['hash'];
            $doc->status         = ContractDocument::STATUS_AWAITING_OWNER;
            $doc->save();

            $this->logEvent($doc, ContractDocumentEvent::EVENT_SIGNED_BY_TENANT, ContractDocumentEvent::ACTOR_TENANT, (string) $tenant->id, $tenant->fullname, ['ip' => $request->ip()], $request);
        });

        $this->notifyAdminsTenantSigned($doc->refresh());

        return $doc;
    }

    /** Chủ trọ ký chốt (phiên admin đã đăng nhập — không cần OTP, xem mục 4.4). */
    public function signAsOwner(ContractDocument $doc, array $payload, User $actor, Request $request): ContractDocument
    {
        if ($doc->status !== ContractDocument::STATUS_AWAITING_OWNER) {
            throw new ContractDocumentException('Chỉ ký chốt được khi hợp đồng đã có chữ ký khách, đang chờ chủ ký.', 409);
        }

        if (! hash_equals((string) $doc->final_hash, (string) ($payload['document_hash'] ?? ''))) {
            throw new ContractDocumentException('Bản hợp đồng đã thay đổi, tải lại trước khi ký.', 409);
        }

        if (! ($payload['consent'] ?? false)) {
            throw new ContractDocumentException('Bạn cần đồng ý điều khoản trước khi ký.', 422, ['consent' => 'Bạn cần đồng ý điều khoản trước khi ký.']);
        }

        $tenantSignatureUri = $this->existingSignatureDataUri($doc, ContractSignature::PARTY_TENANT);
        $signature          = $this->decodeAndStoreSignature($doc, (string) ($payload['signature'] ?? ''), 'owner');
        $ownerSignatureUri  = 'data:' . $signature['mime'] . ';base64,' . base64_encode($signature['bytes']);

        DB::transaction(function () use ($doc, $payload, $actor, $request, $signature, $ownerSignatureUri, $tenantSignatureUri) {
            $previousHash = $doc->final_hash;

            $rendered = $this->renderAndStorePdf(
                $doc,
                $this->fieldsForRender($doc, $doc->snapshot ?? []),
                ['tenant' => ['image_url' => $tenantSignatureUri], 'owner' => ['image_url' => $ownerSignatureUri]],
                'final.pdf'
            );

            ContractSignature::create([
                'document_id'          => $doc->id,
                'party'                => ContractSignature::PARTY_OWNER,
                'signer_type'          => ContractSignature::SIGNER_TYPE_ADMIN,
                'signer_id'            => (string) $actor->id,
                'signer_name'          => filled($payload['signer_name'] ?? null) ? $payload['signer_name'] : $this->actorName($actor),
                'signer_phone'         => $actor->phone ?? null,
                'signature_path'       => $signature['path'],
                // Hash của file mà chủ ĐÃ KÝ LÊN — bản đã có sẵn chữ ký khách, trước khi chủ ký.
                'signed_document_hash' => $previousHash,
                'signed_at'            => now(),
                'ip'                   => $request->ip(),
                'user_agent'           => (string) $request->userAgent(),
                'auth_method'          => ContractSignature::AUTH_METHOD_ADMIN_SESSION,
                'consent_text'         => (string) ($payload['consent_text'] ?? ''),
                'created_at'           => now(),
            ]);

            $doc->final_pdf_path = $rendered['path'];
            $doc->final_hash     = $rendered['hash'];
            $doc->status         = ContractDocument::STATUS_SIGNED;
            $doc->save();

            $this->logEvent($doc, ContractDocumentEvent::EVENT_SIGNED_BY_OWNER, ContractDocumentEvent::ACTOR_ADMIN, (string) $actor->id, $this->actorName($actor), null, $request);
            $this->logEvent($doc, ContractDocumentEvent::EVENT_FINALIZED, ContractDocumentEvent::ACTOR_SYSTEM, null, null, null, $request);
        });

        $doc->refresh();
        $this->notifyTenantSigned($doc);
        $this->emailOwnerFinalPdf($doc);

        return $doc;
    }

    /** Chỉ khi awaiting_tenant VÀ chưa ai ký — xem mục 4.5. */
    public function recall(ContractDocument $doc, User $actor, Request $request): ContractDocument
    {
        if ($doc->status !== ContractDocument::STATUS_AWAITING_TENANT) {
            throw new ContractDocumentException('Chỉ thu hồi được khi đang chờ khách ký.', 409);
        }

        if ($doc->signatures()->exists()) {
            throw new ContractDocumentException('Khách đã ký, không thu hồi được.', 409);
        }

        if ($doc->sealed_pdf_path) {
            Storage::disk('public')->delete($doc->sealed_pdf_path);
        }

        $doc->update([
            'sealed_pdf_path' => null,
            'sealed_hash'     => null,
            'sealed_at'       => null,
            'snapshot'        => null,
            'verify_code'     => null,
            'sent_at'         => null,
            'status'          => ContractDocument::STATUS_DRAFT,
        ]);

        $this->logEvent($doc, ContractDocumentEvent::EVENT_RECALLED, ContractDocumentEvent::ACTOR_ADMIN, (string) $actor->id, $this->actorName($actor), null, $request);

        return $doc->refresh();
    }

    /**
     * Gọi từ ContractController::checkout()/cancel() (API) và EditContract's tương ứng (Filament)
     * khi hợp đồng GỐC kết thúc — xem mục 6. Bản CHƯA signed → cancelled; bản ĐÃ signed → không đụng.
     */
    public function cancelIfUnsigned(?ContractDocument $doc): void
    {
        if (! $doc || in_array($doc->status, [ContractDocument::STATUS_SIGNED, ContractDocument::STATUS_CANCELLED], true)) {
            return;
        }

        $doc->update(['status' => ContractDocument::STATUS_CANCELLED]);
        $this->logEvent($doc, ContractDocumentEvent::EVENT_CANCELLED, ContractDocumentEvent::ACTOR_SYSTEM);
    }

    /** GET .../document ở Portal gọi hàm này — bằng chứng khách ĐÃ ĐƯỢC ĐƯA văn bản trước khi ký. */
    public function markViewedByTenant(ContractDocument $doc, Tenant $tenant, Request $request): void
    {
        $this->logEvent($doc, ContractDocumentEvent::EVENT_VIEWED_BY_TENANT, ContractDocumentEvent::ACTOR_TENANT, (string) $tenant->id, $tenant->fullname, null, $request);
    }

    /** @return array<int, array{event:string, at:?string, actor:?string, meta:?array}> */
    public function auditTrail(ContractDocument $doc): array
    {
        return $doc->events->map(fn (ContractDocumentEvent $e) => [
            'event' => $e->event,
            'at'    => $e->created_at?->toIso8601String(),
            'actor' => $e->actor_name,
            'meta'  => $e->meta,
        ])->all();
    }

    // ------------------------------------------------------------------
    // Nội bộ
    // ------------------------------------------------------------------

    /** Xem mục 3.4 — nhóm trường "sống" đọc từ Contract/Tenant/Building, đúng cấu trúc lưu snapshot. */
    private function snapshotFields(Contract $contract): array
    {
        $contract->loadMissing([
            'room'          => fn ($q) => $q->withoutGlobalScopes(),
            'room.building' => fn ($q) => $q->withoutGlobalScopes(),
            'tenant'        => fn ($q) => $q->withoutGlobalScopes(),
        ]);

        $room     = $contract->room;
        $building = $room?->building;
        $tenant   = $contract->tenant;

        $termMonths = ($contract->start_date && $contract->end_date)
            ? max(1, (int) round($contract->start_date->diffInDays($contract->end_date) / 30))
            : null;

        return [
            'owner_name'                  => $building?->owner_name,
            'owner_id_card_number'        => $building?->owner_id_card_number,
            'owner_id_card_issued_date'   => $building?->owner_id_card_issued_date,
            'owner_id_card_issued_place'  => $building?->owner_id_card_issued_place,
            'owner_permanent_address'     => $building?->owner_address,
            'owner_phone'                 => $building?->owner_phone,

            'tenant_name'                 => $tenant?->fullname,
            'tenant_id_card_number'       => $tenant?->id_card_number,
            'tenant_id_card_issued_date'  => $tenant?->id_card_issued_date?->toDateString(),
            'tenant_id_card_issued_place' => $tenant?->id_card_issued_place,
            'tenant_permanent_address'    => $tenant?->permanent_address,
            'tenant_phone'                => $tenant?->phone,

            'room_code'        => $room?->code,
            'building_name'    => $building?->name,
            'building_address' => $building?->address,

            'monthly_price'        => $contract->monthly_price,
            'deposit_amount'       => $contract->deposit_amount,
            'term_months'          => $termMonths,
            'start_date'           => $contract->start_date?->toDateString(),
            'end_date'             => $contract->end_date?->toDateString(),
            'electric_unit_price'  => $contract->electric_unit_price ?: $building?->electric_unit_price,
            'water_unit_price'     => $contract->water_unit_price ?: $building?->water_unit_price,

            'renewals' => $this->renewalsPayload($contract),
        ];
    }

    // Câu hỏi 3 mục 14 đã xác nhận transfer-room KHÔNG ghi vào minihouse_contract_renewals — chỉ
    // cần đọc thẳng, không phải lọc bớt dòng nào do transfer lẫn vào.
    private function renewalsPayload(Contract $contract): array
    {
        return ContractRenewal::where('contract_id', $contract->id)
            ->orderBy('created_at')
            ->get()
            ->values()
            ->map(function (ContractRenewal $r, int $i) {
                $from = $r->old_end_date?->copy()->addDay();
                $to   = $r->new_end_date;

                return [
                    'index'         => $i + 1,
                    'months'        => ($from && $to) ? max(1, (int) round($from->diffInDays($to) / 30)) : null,
                    'from_date'     => $from?->toDateString(),
                    'to_date'       => $to?->toDateString(),
                    'monthly_price' => $r->new_monthly_price,
                ];
            })
            ->all();
    }

    private function fieldsForRender(ContractDocument $doc, array $identity): array
    {
        return array_merge($identity, [
            'no'            => $doc->no,
            'sign_date'     => $doc->sign_date?->toDateString(),
            'signed_place'  => $doc->signed_place,
            'max_occupants' => $doc->max_occupants,
            'payment_day'   => $doc->payment_day,
            'extra_terms'   => $doc->extra_terms,
            'verify_code'   => $doc->verify_code,
            'sealed_hash'   => $doc->sealed_hash,
            'final_hash'    => $doc->final_hash,
        ]);
    }

    /** @return array{owner:string, tenant:string} */
    private function validateForSend(ContractDocument $doc, array $identity): array
    {
        $errors = [];

        if (blank($identity['owner_name'] ?? null) || blank($identity['owner_id_card_number'] ?? null) || blank($identity['owner_permanent_address'] ?? null)) {
            $errors['owner'] = 'Toà nhà chưa có thông tin chủ hộ (tên, CCCD, địa chỉ).';
        }

        if (blank($identity['tenant_name'] ?? null) || blank($identity['tenant_id_card_number'] ?? null)) {
            $errors['tenant'] = 'Khách thuê chưa có CCCD.';
        }

        if (blank($identity['tenant_phone'] ?? null)) {
            $errors['tenant_phone'] = 'Khách thuê chưa có số điện thoại — không gửi được mã xác thực.';
        }

        if (blank($doc->payment_day)) {
            $errors['payment_day'] = 'Chưa điền ngày đóng tiền hàng tháng.';
        }

        if (blank($doc->max_occupants)) {
            $errors['max_occupants'] = 'Chưa điền số người ở tối đa.';
        }

        if (blank($doc->sign_date)) {
            $errors['sign_date'] = 'Chưa điền ngày ký hợp đồng.';
        }

        return $errors;
    }

    private function generateVerifyCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (ContractDocument::where('verify_code', $code)->exists());

        return $code;
    }

    private function basePath(ContractDocument $doc): string
    {
        return "minihouse/contracts/{$doc->contract_id}/{$doc->id}";
    }

    // "final.pdf" dùng CHUNG 1 đường dẫn xuyên suốt 3 lần ghi đè (niêm phong → khách ký → chủ ký) —
    // trình duyệt/app/CDN có thể cache theo URL, lần đổi nội dung SAU không tự làm mới nếu không đổi
    // URL (bug thật gặp khi test: xem lại pdf_url cũ tưởng thiếu chữ ký chủ trọ, thật ra file server
    // đã đúng, chỉ là bản cache cũ). Gắn ?v=<12 ký tự đầu hash hiện tại> để mỗi lần nội dung đổi là
    // 1 URL khác hẳn, ép trình duyệt/CDN tải lại thay vì phục vụ bản cache.
    private function currentPdfUrl(ContractDocument $doc): ?string
    {
        $path = $doc->final_pdf_path ?: $doc->sealed_pdf_path;

        if (! $path) {
            return null;
        }

        $hash = $doc->final_hash ?: $doc->sealed_hash;

        return Storage::disk('public')->url($path) . ($hash ? '?v=' . substr($hash, 0, 12) : '');
    }

    /**
     * @param  array{tenant?: array{image_url: ?string}, owner?: array{image_url: ?string}}  $signatureDataUris
     * @return array{path: string, hash: string}
     */
    private function renderAndStorePdf(ContractDocument $doc, array $fields, array $signatureDataUris, string $filename): array
    {
        $html = ContractDocumentRenderer::render($fields, $signatureDataUris);
        $pdf  = ContractPdfRenderer::render($html);
        $hash = hash('sha256', $pdf);
        $path = $this->basePath($doc) . '/' . $filename;

        Storage::disk('public')->put($path, $pdf);

        return ['path' => $path, 'hash' => $hash];
    }

    /** @return array{path: string, mime: string, bytes: string} */
    private function decodeAndStoreSignature(ContractDocument $doc, string $dataUri, string $partySlug): array
    {
        if (! preg_match('#^data:(image/svg\+xml|image/png);base64,(.+)$#', trim($dataUri), $m)) {
            throw new ContractDocumentException('Ảnh chữ ký không đúng định dạng (chỉ nhận SVG hoặc PNG dạng data URI).', 422, ['signature' => 'Ảnh chữ ký không đúng định dạng.']);
        }

        [, $mime, $base64] = $m;
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new ContractDocumentException('Ảnh chữ ký không giải mã được.', 422, ['signature' => 'Ảnh chữ ký không giải mã được.']);
        }

        if (strlen($bytes) > self::SIGNATURE_MAX_BYTES) {
            throw new ContractDocumentException('Ảnh chữ ký vượt quá 200KB.', 422, ['signature' => 'Ảnh chữ ký vượt quá 200KB.']);
        }

        if ($mime === 'image/svg+xml') {
            $bytes = $this->sanitizeSvg($bytes);
        }

        $ext  = $mime === 'image/svg+xml' ? 'svg' : 'png';
        $path = $this->basePath($doc) . "/signature-{$partySlug}.{$ext}";

        Storage::disk('public')->put($path, $bytes);

        return ['path' => $path, 'mime' => $mime, 'bytes' => $bytes];
    }

    // Đủ cho SVG do app TỰ SINH (chỉ <svg>+<path>, không ảnh nhúng) — không parse XML đầy đủ, chỉ
    // chặn 3 vector chèn script phổ biến nhất nếu có ai đó chèn tay 1 file khác vào request (mục 10).
    private function sanitizeSvg(string $svg): string
    {
        $svg = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $svg) ?? $svg;
        $svg = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $svg) ?? $svg;

        return $svg;
    }

    private function existingSignatureDataUri(ContractDocument $doc, string $party): ?string
    {
        $signature = $doc->signatures()->where('party', $party)->latest('signed_at')->first();

        if (! $signature || ! Storage::disk('public')->exists($signature->signature_path)) {
            return null;
        }

        $mime  = str_ends_with($signature->signature_path, '.svg') ? 'image/svg+xml' : 'image/png';
        $bytes = Storage::disk('public')->get($signature->signature_path);

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /** @return array{tenant?: array{image_url: ?string}, owner?: array{image_url: ?string}} */
    public function signaturesForRender(ContractDocument $doc): array
    {
        return [
            'tenant' => ['image_url' => $this->existingSignatureDataUri($doc, ContractSignature::PARTY_TENANT)],
            'owner'  => ['image_url' => $this->existingSignatureDataUri($doc, ContractSignature::PARTY_OWNER)],
        ];
    }

    private function signaturePayload(ContractSignature $s, bool $includeInternal): array
    {
        $payload = [
            'party'                 => $s->party,
            'signer_name'           => $s->signer_name,
            'signer_phone'          => $this->maskPhone($s->signer_phone),
            'signature_url'         => Storage::disk('public')->url($s->signature_path),
            'signed_document_hash'  => $s->signed_document_hash,
            'signed_at'             => $s->signed_at?->toIso8601String(),
            'auth_method'           => $s->auth_method,
        ];

        if ($includeInternal) {
            $payload['ip'] = $s->ip;
        }

        return $payload;
    }

    // BUG THẬT đã gặp (2026-09-22): "$actor->fullname ?? $actor->name" — App\Models\User KHÔNG có
    // cột 'name' (chỉ có 'fullname', xem User::$fillable), nên $actor->name LUÔN là null vô nghĩa;
    // và toán tử "??" chỉ rơi xuống vế sau khi vế trước là NULL — 1 tài khoản có fullname='' (chuỗi
    // rỗng, không phải null, VD tài khoản super_admin seed sẵn chưa điền tên) khiến "??" KHÔNG rơi
    // xuống đâu cả, ghi thẳng chuỗi rỗng làm signer_name/actor_name (triệu chứng: chữ ký chủ trọ lưu
    // đúng file nhưng tên hiện rỗng). Dùng filled()/fallback qua email thay vì "??" + cột không tồn
    // tại.
    private function actorName(User $actor): string
    {
        if (filled($actor->fullname)) {
            return $actor->fullname;
        }

        return $actor->email ?? ('#' . $actor->id);
    }

    private function maskPhone(?string $phone): ?string
    {
        if (blank($phone) || strlen($phone) < 6) {
            return $phone;
        }

        return substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 7) . substr($phone, -3);
    }

    private function logEvent(ContractDocument $doc, string $event, ?string $actorType = null, ?string $actorId = null, ?string $actorName = null, ?array $meta = null, ?Request $request = null): void
    {
        ContractDocumentEvent::create([
            'document_id' => $doc->id,
            'event'       => $event,
            'actor_type'  => $actorType,
            'actor_id'    => $actorId,
            'actor_name'  => $actorName,
            'ip'          => $request?->ip(),
            'user_agent'  => $request ? (string) $request->userAgent() : null,
            'meta'        => $meta,
            'created_at'  => now(),
        ]);
    }

    private function notifyAdminsTenantSigned(ContractDocument $doc): void
    {
        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($doc->contract_id);
        $buildingId = $contract?->room?->building_id;

        if (! $buildingId) {
            return;
        }

        $service    = app(AdminNotificationService::class);
        $recipients = $service->recipientsForCategory($buildingId, HomestayBridge::PARTNER_ID);

        $service->notify(
            $recipients,
            'Khách đã ký hợp đồng ' . $doc->no,
            'Phòng ' . ($contract->room?->code ?? '') . ' — vào ký chốt để hoàn tất.',
            ['type' => 'contract_awaiting_owner_signature', 'document_id' => $doc->id, 'contract_id' => $doc->contract_id],
            'heroicon-o-pencil-square',
            'warning',
        );
    }

    private function notifyTenantSigned(ContractDocument $doc): void
    {
        $contract = Contract::withoutGlobalScopes()
            ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes(), 'room' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($doc->contract_id);

        if (! $contract?->tenant) {
            return;
        }

        PortalNotificationService::notify(
            $contract->tenant,
            'contract_signed',
            'Hợp đồng phòng ' . ($contract->room?->code ?? '') . ' đã ký đủ hai bên',
            null,
            '/minihouse/portal/contracts/' . $contract->id
        );
    }

    // Best-effort — lỗi gửi mail KHÔNG được chặn việc ký chốt đã hoàn tất thành công (cùng nguyên
    // tắc PortalNotificationService::pushToTenant()). Bỏ qua nếu Toà chưa khai owner_email (Tenant
    // chưa có cột email nên KHÔNG gửi cho khách thuê ở đợt này — xem docs mục "Điều chỉnh").
    private function emailOwnerFinalPdf(ContractDocument $doc): void
    {
        if (blank($doc->final_pdf_path)) {
            return;
        }

        try {
            $contract = Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($doc->contract_id);

            $ownerEmail = $contract?->room?->building?->owner_email;

            if (blank($ownerEmail)) {
                return;
            }

            Mail::to($ownerEmail)->send(new \App\Mail\ContractDocumentSignedMail($doc));
        } catch (\Throwable $e) {
            Log::warning('ContractDocumentService: gửi email hợp đồng đã ký thất bại', [
                'document_id' => $doc->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
