<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Models\RoomRating;
use App\Models\User;
use App\Services\ContractSigning\ContractSigningManager;
use App\Services\PartnerContractWorkflowService;
use App\Services\PartnerLegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerController extends Controller
{
    public function index(Request $request, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->superAdmin($request);
        $partners = Partner::query()->where('partner_type', $this->partnerType($request))
            ->with('subscription.plan')
            ->where('id', '!=', \Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID)
            ->latest()->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($partners->through(fn (Partner $partner) => $this->format($partner, $documents)));
    }

    public function store(Request $request, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate([...$this->rules(), 'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id']], $this->messages());
        // Gói dùng thử được chọn lúc tạo (không phải cột của partners). Bỏ trống → gói mặc định của loại đối tác.
        $planId = $data['plan_id'] ?? null;
        unset($data['plan_id']);
        $plan = $planId ? \App\Models\SubscriptionPlan::findOrFail($planId) : null;
        if ($plan && ($plan->partner_type !== $this->partnerType($request) || ! $plan->is_active)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['plan_id' => 'Gói không áp dụng cho loại đối tác này.']);
        }
        $partner = Partner::create([...$data, 'partner_type' => $this->partnerType($request), 'name' => $data['legal_name'], 'created_by' => $request->user()->id, 'verification_status' => 'pending', 'contract_status' => 'draft', 'status' => true]);
        PartnerStatusLog::create(['partner_id' => $partner->id, 'to_status' => 'pending', 'note' => 'Hồ sơ đối tác được tạo qua API.', 'changed_by' => $request->user()->id]);
        if ($plan) {
            app(\App\Services\SubscriptionService::class)->startTrialWithPlan($partner, $plan);
        }

        return response()->json(['message' => 'Đã tạo đối tác.', 'data' => $this->format($partner, $documents)], 201);
    }

    public function show(Request $request, Partner $partner, PartnerLegalDocumentService $documents): JsonResponse
    {
        abort_if($partner->isSystemPartner(), 404);
        $this->partnerAccess($request, $partner);

        return response()->json(['data' => $this->format($partner, $documents)]);
    }

    public function update(Request $request, Partner $partner, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate($this->rules(true, $partner), $this->messages());
        if (isset($data['legal_name'])) {
            $data['name'] = $data['legal_name'];
        }
        $partner->update($data);

        return response()->json(['message' => 'Đã cập nhật đối tác.', 'data' => $this->format($partner->fresh(), $documents)]);
    }

    public function contract(Request $request, Partner $partner, PartnerLegalDocumentService $documents): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $version = $partner->contractVersions()->first();

        return response()->json(['data' => [
            'partner_id' => $partner->id,
            'contract_status' => $partner->contract_status,
            'can_create_contract' => $documents->isContractEligible($partner),
            'version_id' => $version?->id,
            'content_hash' => $version?->content_hash,
            // Văn bản đầy đủ như bản in/PDF (giống trang Filament “Xem toàn văn hợp đồng”) và thân hợp đồng dùng tính content_hash.
            'content' => $version ? \App\Support\PartnerContractRenderer::renderFramed($version->content, $partner, $version) : null,
            'content_body' => $version?->content,
            'partner_confirmed_at' => $version?->partner_confirmed_at?->toIso8601String(),
            'platform_signed_at' => $version?->platform_signed_at?->toIso8601String(),
            'is_fully_signed' => $version?->isFullySigned() ?? false,
            // Cách nền tảng (Bên A) ký: auto (mặc định) | usb — xem Cấu hình web > Chữ ký số. Đang chờ nền tảng ký khi đối tác đã xác nhận mà Bên A chưa ký.
            'platform_sign_method' => app(ContractSigningManager::class)->platformSignMethod(),
            // Cách ký đang bật: mặc định chỉ tải file về ký (cả nền tảng và đối tác). Ký tự động / OTP chỉ có khi bật CONTRACT_ALLOW_AUTO_SIGN / CONTRACT_ALLOW_OTP.
            'platform_auto_sign_allowed' => ContractSigningManager::platformAutoAllowed(),
            'otp_allowed' => ContractSigningManager::otpAllowed(),
            // Nền tảng ký được bất cứ lúc nào chưa ký (trước hay sau đối tác); hợp đồng có hiệu lực khi cả hai bên đã ký.
            'awaiting_platform_sign' => $version ? ! $version->isPlatformSigned() : false,
            'awaiting_partner_sign' => $version ? ($version->isPlatformSigned() && ! $version->isPartnerConfirmed()) : false,
            'can_platform_sign' => $version ? (! $version->isPlatformSigned() && $documents->isContractEligible($partner)) : false,
            'signing_order' => $version && ($version->isPartnerConfirmed() || $version->isPlatformSigned())
                ? ($version->isFullySigned()
                    ? (($version->platform_signed_at && $version->partner_confirmed_at && $version->partner_confirmed_at->lt($version->platform_signed_at)) ? 'partner_first' : 'platform_first')
                    : ($version->isPlatformSigned() ? 'platform_first' : 'partner_first'))
                : null,
            'platform_sign_prepared_at' => $version?->platform_sign_prepared_at?->toIso8601String(),
            // Nhà cung cấp sẽ dùng để ký tự động; is_test = true nghĩa là ký THỬ NGHIỆM (Local), không có giá trị pháp lý.
            'platform_signing_provider_in_use' => app(ContractSigningManager::class)->providerNameForSide(ContractSigningManager::SIDE_HOMESTAY),
            'platform_signing_is_test' => app(ContractSigningManager::class)->isTestProvider(),
            // Hợp đồng đã ký thử nghiệm (Local) và nay đã có nhà cung cấp thật → có thể ký lại (POST .../contract/platform-resign).
            'can_platform_resign' => $version ? ($version->isPlatformSigned() && $version->platform_signing_provider === 'local' && ! app(ContractSigningManager::class)->isTestProvider()) : false,
            'platform_signed_method' => $version?->platform_sign_method,
            'partner_sign_method' => $version?->partner_sign_method ?? ($version?->isPartnerConfirmed() ? 'otp' : null),
            'partner_certificate' => $version?->isPartnerSignedByCks() ? [
                'subject' => $version->partner_signature_certificate['cert_subject'] ?? null,
                'organization' => $version->partner_signature_certificate['cert_org'] ?? null,
                'issuer' => $version->partner_signature_certificate['cert_issuer'] ?? null,
                'tax_code' => $version->partner_signature_certificate['tax_code'] ?? null,
                'valid_to' => $version->partner_signature_certificate['valid_to'] ?? null,
            ] : null,
            'platform_certificate' => $version?->isPlatformSigned() ? [
                'subject' => $version->platform_signature_certificate['cert_subject'] ?? null,
                'organization' => $version->platform_signature_certificate['cert_org'] ?? null,
                'issuer' => $version->platform_signature_certificate['cert_issuer'] ?? null,
                'valid_to' => $version->platform_signature_certificate['valid_to'] ?? null,
            ] : null,
        ]]);
    }

    // GET /api/admin/partners/{partner}/contract/versions — lịch sử phiên bản/phụ lục hợp đồng (Filament: "Lịch sử phiên bản hợp đồng").
    public function contractVersions(Request $request, Partner $partner): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');

        return response()->json(['data' => $partner->contractVersions()->with(['changedBy', 'media'])->get()->map(fn ($v) => $this->versionData($v))->values()]);
    }

    // POST /api/admin/partners/{partner}/contract/versions — "Tải lên phụ lục mới" (multipart: version_label, change_note, document).
    public function storeContractVersion(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $data = $request->validate([
            'version_label' => ['required', 'string', 'max:50'],
            'change_note'   => ['nullable', 'string', 'max:2000'],
            'document'      => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], ['version_label.required' => 'Vui lòng nhập tên phiên bản.', 'document.mimes' => 'Tài liệu phải là PDF hoặc ảnh (jpg, png, webp).', 'document.max' => 'Tài liệu tối đa 10 MB.']);

        $version = $partner->contractVersions()->create(['version_label' => $data['version_label'], 'change_note' => $data['change_note'] ?? null, 'changed_by' => $request->user()->id]);
        if ($request->hasFile('document')) {
            $version->addMediaFromRequest('document')->toMediaCollection('document');
        }

        return response()->json(['message' => 'Đã thêm phiên bản hợp đồng mới.', 'data' => $this->versionData($version->fresh(['changedBy', 'media']))], 201);
    }

    // POST /api/admin/partners/{partner}/contract/renew — "Gia hạn hợp đồng": +12 tháng từ ngày hết hạn hiện tại (hoặc từ hôm nay nếu đã hết hạn).
    public function renewContract(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $base = $partner->contract_expires_at && $partner->contract_expires_at->isFuture() ? $partner->contract_expires_at : now();
        $partner->update(['contract_expires_at' => $base->copy()->addYear(), 'contract_status' => 'active']);

        return response()->json(['message' => 'Đã gia hạn hợp đồng thêm 12 tháng.', 'data' => ['contract_status' => $partner->contract_status, 'contract_expires_at' => $partner->contract_expires_at?->toDateString()]]);
    }

    // POST /api/admin/partners/{partner}/contract/terminate — "Yêu cầu chấm dứt": đánh dấu hợp đồng chấm dứt (quy trình pháp lý thực hiện ngoài hệ thống).
    public function terminateContract(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        abort_if($partner->contract_status === 'terminated', 422, 'Hợp đồng đã được đánh dấu chấm dứt.');
        $partner->update(['contract_status' => 'terminated']);
        // Luồng tiền mới: ngưng bán, bắt đầu thời gian giữ ký quỹ nhận khiếu nại.
        app(\App\Services\EscrowService::class)->onContractTerminated($partner->fresh(), $request->user());

        return response()->json(['message' => 'Đã đánh dấu hợp đồng chấm dứt.', 'data' => ['contract_status' => 'terminated']]);
    }

    private function versionData($version): array
    {
        $doc = $version->getFirstMedia('document');

        return [
            'id' => $version->id, 'kind' => $version->kind, 'version_label' => $version->version_label, 'change_note' => $version->change_note,
            'changed_by' => $version->changedBy ? ['id' => $version->changedBy->id, 'name' => $version->changedBy->fullname] : null,
            'content_hash' => $version->content_hash, 'is_partner_confirmed' => $version->isPartnerConfirmed(), 'is_platform_signed' => $version->isPlatformSigned(),
            // Cách mỗi bên đã ký: partner = otp | cks; platform = auto | usb (null khi chưa ký hoặc bản cũ). *_provider cho biết ký bằng nhà cung cấp nào (local = thử nghiệm).
            'partner_sign_method' => $version->partner_sign_method ?? ($version->isPartnerConfirmed() ? 'otp' : null),
            'partner_signing_provider' => $version->partner_signing_provider,
            'platform_sign_method' => $version->platform_sign_method,
            'platform_signing_provider' => $version->platform_signing_provider,
            'document' => $doc ? ['name' => $doc->file_name, 'mime_type' => $doc->mime_type, 'size' => $doc->size, 'url' => $doc->getUrl()] : null,
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    public function createContract(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $result = $workflow->createAndSend($partner, $request->user());

        return response()->json(['message' => 'Đã tạo hợp đồng và xử lý gửi email.', 'data' => [
            'version_id' => $result['version']->id, 'contract_code' => $partner->fresh()->contract_code, 'content_hash' => $result['version']->content_hash,
            'mail_sent' => $result['mailSent'], 'email' => $result['email'], 'signing_url' => $result['signingUrl'],
        ]], 201);
    }

    // POST …/contract/addendum — phụ lục "Ký quỹ và thanh toán" cho Homestay đã ký hợp đồng mẫu cũ (365home thu hộ). Ký bằng đúng luồng OTP của hợp đồng;
    // chỉ khi phụ lục có hiệu lực (nền tảng ký) đối tác mới chuyển sang luồng tiền đặt phòng về thẳng tài khoản của mình.
    public function createAddendum(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $result = $workflow->createAddendumAndSend($partner, $request->user());

        return response()->json(['message' => 'Đã tạo phụ lục và xử lý gửi email.', 'data' => [
            'version_id' => $result['version']->id, 'kind' => 'addendum', 'content_hash' => $result['version']->content_hash,
            'mail_sent' => $result['mailSent'], 'email' => $result['email'], 'signing_url' => $result['signingUrl'],
        ]], 201);
    }

    // POST /api/admin/partners/{partner}/contract/platform-sign — nền tảng ký TỰ ĐỘNG qua nhà cung cấp chữ ký số từ xa (ký trước hay sau đối tác đều được). Body tuỳ chọn: method = auto|usb (mặc định theo
    // Cấu hình web > Chữ ký số; mặc định hệ thống là auto). Cách usb: dùng GET .../contract/pdf-to-sign rồi POST .../contract/platform-upload.
    public function platformSign(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): StreamedResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $manager = app(ContractSigningManager::class);
        $method = $request->validate(['method' => ['nullable', Rule::in([ContractSigningManager::PLATFORM_SIGN_USB, ContractSigningManager::PLATFORM_SIGN_AUTO])]])['method'] ?? $manager->platformSignMethod();
        if ($method !== ContractSigningManager::PLATFORM_SIGN_AUTO) {
            throw ValidationException::withMessages(['method' => 'Nền tảng đang ký bằng USB token: tải PDF cần ký (GET contract/pdf-to-sign), ký trên máy có USB rồi tải bản đã ký lên (POST contract/platform-upload). Muốn ký tự động hãy gửi method=auto.']);
        }
        $result = $workflow->platformSign($partner, $request->user(), (string) $request->ip(), (string) $request->userAgent());

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // POST /api/admin/partners/{partner}/contract/platform-resign — KÝ LẠI phần nền tảng cho hợp đồng đã ký THỬ NGHIỆM (Local) sau khi đã cấu hình nhà cung cấp thật.
    // Chỉ thay chữ ký và PDF; không đổi trạng thái hợp đồng/luồng tiền. Trả về PDF mới. 422 nếu bản đã ký bằng chữ ký số thật hoặc nhà cung cấp vẫn là Local.
    public function platformResign(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): StreamedResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $result = $workflow->platformResign($partner, $request->user(), (string) $request->ip(), (string) $request->userAgent());

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // GET /api/admin/partners/{partner}/contract/pdf-to-sign — tải về để ký (USB token hoặc CKS), bước 1: PDF hợp đồng cần ký; đối tác đã ký CKS thì là file đã có chữ ký của họ.
    public function pdfToSign(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): StreamedResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $result = $workflow->preparePlatformPdf($partner);

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // POST /api/admin/partners/{partner}/contract/platform-upload — tải về để ký, bước 2: tải PDF đã ký lên (multipart signed_pdf). Hệ thống kiểm tra chữ ký rồi lưu lại; hợp đồng hoàn tất khi đối tác cũng đã ký.
    public function platformUpload(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $request->validate(['signed_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480']]);
        $result = $workflow->platformUpload($partner, $request->user(), (string) $request->ip(), (string) $request->userAgent(), (string) file_get_contents($request->file('signed_pdf')->getRealPath()));
        $cert = $result['certificate'];

        $fresh = $partner->contractVersions()->first();

        return response()->json(['message' => $fresh?->isFullySigned() ? 'Đã kiểm tra chữ ký số và hoàn tất hợp đồng.' : 'Đã kiểm tra và lưu chữ ký số của nền tảng; hợp đồng có hiệu lực khi đối tác ký.', 'data' => [
            'is_fully_signed' => (bool) $fresh?->isFullySigned(),
            'awaiting_partner_sign' => ! ($fresh?->isPartnerConfirmed() ?? false),
            'contract_status' => $partner->fresh()->contract_status,
            'platform_certificate' => [
                'subject' => $cert['cert_subject'] ?? null, 'organization' => $cert['cert_org'] ?? null, 'issuer' => $cert['cert_issuer'] ?? null,
                'serial' => $cert['serial'] ?? null, 'valid_from' => $cert['valid_from'] ?? null, 'valid_to' => $cert['valid_to'] ?? null,
                'tax_code' => $cert['tax_code'] ?? null, 'signing_time' => $cert['signing_time'] ?? null,
            ],
        ]]);
    }

    // GET /api/admin/partners/{partner}/contract/partner-pdf-to-sign — PDF hợp đồng CHƯA ký để ĐỐI TÁC ký bằng chữ ký số (CKS) của họ (admin gửi hộ khi đối tác không dùng link).
    public function partnerPdfToSign(Request $request, Partner $partner, \App\Services\PartnerContractCksService $cks): StreamedResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $version = $partner->contractVersions()->first();
        abort_unless($version, 404, 'Đối tác chưa có hợp đồng.');
        $result = $cks->preparePdf($version);

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // POST /api/admin/partners/{partner}/contract/partner-upload — tải lên PDF đã được đối tác ký bằng CKS (multipart signed_pdf, signer_name?). Kiểm tra như khi đối tác tự tải lên ở link ký.
    public function partnerUpload(Request $request, Partner $partner, \App\Services\PartnerContractCksService $cks): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        $version = $partner->contractVersions()->first();
        abort_unless($version, 404, 'Đối tác chưa có hợp đồng.');
        $data = $request->validate(['signed_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'], 'signer_name' => ['nullable', 'string', 'max:255']]);

        $result = $cks->submit($version, (string) file_get_contents($request->file('signed_pdf')->getRealPath()), $data['signer_name'] ?? null, (string) $request->ip(), (string) $request->userAgent());
        $cert = $result['certificate'];

        return response()->json(['message' => 'Đã kiểm tra chữ ký số của đối tác và ghi nhận hợp đồng.', 'data' => [
            'partner_confirmed_at' => $version->fresh()->partner_confirmed_at->toIso8601String(),
            'partner_sign_method' => 'cks',
            'partner_certificate' => [
                'subject' => $cert['cert_subject'] ?? null, 'organization' => $cert['cert_org'] ?? null, 'issuer' => $cert['cert_issuer'] ?? null,
                'tax_code' => $cert['tax_code'] ?? null, 'valid_to' => $cert['valid_to'] ?? null,
            ],
        ]]);
    }

    // GET /api/admin/partners/{partner}/contract/display-pdf — BẢN HIỂN THỊ PDF đúng như màn hình xem hợp đồng trên web (khung ĐẠI DIỆN BÊN A / BÊN B có tên và giờ
    // ký lấy từ chữ ký). Chỉ để xem/in, không mang chữ ký số; bản có giá trị ký số là GET .../contract/signed-pdf. Super Admin hoặc chủ đối tác của chính hợp đồng.
    public function displayPdf(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): StreamedResponse
    {
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        abort_unless(config('contract_signing.allow_display_pdf', false), 404, 'Bản hiển thị PDF đang tắt (CONTRACT_ALLOW_DISPLAY_PDF).');
        $this->partnerAccess($request, $partner);
        $result = $workflow->displayPdf($partner);

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // GET /api/admin/partners/{partner}/contract/signed-pdf — tải lại PDF hợp đồng đã ký số lưu trên server (không ký lại).
    // Super Admin: như cũ (hợp đồng cũ chưa lưu file thì xuất và lưu ở lần đầu). CHỦ ĐỐI TÁC (tài khoản của chính đối tác này): chỉ tải được
    // bản đã lưu của hợp đồng mình — không kích hoạt việc ký/xuất lại.
    public function downloadSignedPdf(Request $request, Partner $partner, PartnerContractWorkflowService $workflow): StreamedResponse
    {
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        if (! $request->user()->isSuperAdmin()) {
            $this->partnerAccess($request, $partner);
            $version = $partner->contractVersions()->first();
            $media = $version && $version->isPlatformSigned() ? $version->getFirstMedia('signed_pdf') : null;
            abort_unless($media && is_file($media->getPath()), 404, 'Hợp đồng chưa có bản PDF đã ký.');

            return response()->streamDownload(fn () => print (file_get_contents($media->getPath())), $media->file_name, ['Content-Type' => 'application/pdf']);
        }
        $result = $workflow->platformSign($partner, $request->user(), (string) $request->ip(), (string) $request->userAgent(), reExport: true);

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    public function financial(Request $request, Partner $partner): JsonResponse
    {
        $this->partnerAccess($request, $partner);

        return response()->json(['data' => $this->financialData($partner)]);
    }

    // POST /api/admin/minihouse/partners/{partner}/signup/approve — Super Admin DUYỆT đăng ký MiniHouse: tặng dùng thử, tạo tài khoản, gửi email đăng nhập.
    public function approveSignup(Request $request, Partner $partner, \App\Services\PartnerOnboardingService $onboarding): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->isMinihouse() && ! $partner->isSystemPartner(), 404);
        $result = $onboarding->approveSignup($partner, $request->user());
        abort_unless($result['created'], 422, $result['reason'] ?: 'Không tạo được tài khoản đăng nhập cho đối tác.');

        return response()->json([
            'message' => 'Đã duyệt đăng ký, tặng dùng thử và ' . ($result['mail_sent'] ? 'gửi email tài khoản đăng nhập cho đối tác.' : 'tạo tài khoản (chưa gửi được email — dùng “Gửi lại tài khoản đăng nhập”).'),
            'data'    => ['email' => $result['email'], 'mail_sent' => $result['mail_sent'], 'trial_expires_at' => $result['trial_expires_at']] + $this->format($partner->fresh(), app(PartnerLegalDocumentService::class)),
        ]);
    }

    // POST /api/admin/minihouse/partners/{partner}/signup/reject — Super Admin từ chối đăng ký MiniHouse (email lý do cho đối tác).
    public function rejectSignup(Request $request, Partner $partner, \App\Services\PartnerOnboardingService $onboarding): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->isMinihouse() && ! $partner->isSystemPartner(), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'Vui lòng nhập lý do từ chối.']);
        $onboarding->rejectSignup($partner, $data['reason'], $request->user());

        return response()->json(['message' => 'Đã từ chối đăng ký.', 'data' => $this->format($partner->fresh(), app(PartnerLegalDocumentService::class))]);
    }

    // POST /api/admin/{minihouse/}partners/{partner}/financial — Super Admin sửa toàn bộ thông tin tài chính; CHỦ ĐỐI TÁC (tài khoản vai trò partner /
    // Quản lý MiniHouse của chính đối tác này) tự thiết lập TÀI KHOẢN NGÂN HÀNG sau khi đăng nhập (chỉ các trường bank_*, trường khác bỏ qua).
    // Cùng cột partners.bank_* với tab Tài chính của Super Admin nên hai bên tự đồng bộ.
    // POST /api/admin/{minihouse/}partners/{partner}/resend-credentials — nút Filament "Gửi lại tài khoản đăng nhập": tạo tài khoản (nếu chưa có)
    // hoặc đặt mật khẩu mới cho tài khoản chủ đối tác rồi gửi email. Cùng điều kiện hiển thị với nút Filament.
    public function resendCredentials(Request $request, Partner $partner, \App\Services\PartnerOnboardingService $onboarding): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($partner->isSystemPartner(), 404);
        $eligible = $partner->usesContract()
            ? ($partner->contract_status === 'active' && $partner->canProvisionOwnerAccount())
            : (filled($partner->onboarding_token) && $partner->subscription?->expires_at !== null);
        abort_unless($eligible, 422, $partner->usesContract() ? 'Chỉ gửi lại tài khoản khi hợp đồng đã có hiệu lực.' : 'Chỉ gửi lại tài khoản khi đối tác đã được kích hoạt gói.');

        $result = $onboarding->resendCredentials($partner);
        abort_unless($result['created'] || $result['mail_sent'], 422, $result['reason'] ?: 'Không gửi được tài khoản đăng nhập.');

        return response()->json([
            'message' => $result['mail_sent'] ? 'Đã gửi thông tin đăng nhập cho đối tác.' : 'Đã tạo/đặt lại tài khoản nhưng chưa gửi được email — kiểm tra cấu hình SMTP.',
            'data'    => ['email' => $result['email'], 'created' => $result['created'], 'mail_sent' => $result['mail_sent']],
        ]);
    }

    // POST /api/admin/{minihouse/}partners/{partner}/suspend — nút Filament "Tạm dừng hồ sơ": verification_status = suspended, khoá đối tác (status = false).
    // DELETE /api/admin/{minihouse/}partners/{partner} — Super Admin XOÁ đối tác (xoá mềm, như nút Xoá ở trang quản trị): đối tác biến mất khỏi danh sách,
    // tài khoản của đối tác không đăng nhập được nữa. Không xoá được đối tác hệ thống; hợp đồng đang hiệu lực phải chấm dứt trước.
    public function destroy(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($partner->isSystemPartner(), 404);
        abort_if((bool) ($reason = $partner->deletionBlockedReason()), 422, (string) $reason);

        $from = $partner->verification_status;
        try {
            $partner->delete();
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
        PartnerStatusLog::create(['partner_id' => $partner->id, 'from_status' => $from, 'to_status' => $from, 'note' => 'Xoá đối tác', 'changed_by' => $request->user()->id]);

        return response()->json(['message' => 'Đã xoá đối tác.']);
    }

    // POST /api/admin/{minihouse/}partners/{partner}/reactivate — Super Admin MỞ LẠI hồ sơ đang tạm dừng: trả về trạng thái ngay trước khi tạm dừng
    // (thường là "approved"; nếu trước đó đang chờ duyệt thì về "pending").
    public function reactivate(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($partner->isSystemPartner(), 404);
        abort_unless($partner->verification_status === 'suspended', 422, 'Hồ sơ không ở trạng thái tạm dừng.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        app(PartnerLegalDocumentService::class)->reactivate($partner, $request->user(), $data['note'] ?? null);

        return response()->json(['message' => 'Đã mở lại hồ sơ.', 'data' => $this->format($partner->fresh(), app(PartnerLegalDocumentService::class))]);
    }

    // GET /api/admin/{minihouse/}partners/{partner}/status-logs — lịch sử thay đổi trạng thái hồ sơ (mới nhất trước): ai đổi, lúc nào, ghi chú.
    public function statusLogs(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($partner->isSystemPartner(), 404);
        $logs = PartnerStatusLog::query()->where('partner_id', $partner->id)->with('changedBy:id,fullname')->latest('id')
            ->paginate(min($request->integer('per_page', 50), 100));

        return response()->json($logs->through(fn (PartnerStatusLog $log) => [
            'id'          => $log->id,
            'from_status' => $log->from_status,
            'to_status'   => $log->to_status,
            'note'        => $log->note,
            'changed_by'  => $log->changedBy ? ['id' => $log->changedBy->id, 'name' => $log->changedBy->fullname] : null,
            'created_at'  => $log->created_at?->toIso8601String(),
        ]));
    }

    // POST /api/admin/minihouse/partners/{partner}/subscription/extend {months} — Super Admin GIA HẠN gói MiniHouse thêm N tháng (như nút "Gia hạn"
    // ở trang Gói dịch vụ): cộng nối tiếp sau ngày hết hạn nếu còn hạn, hoặc tính từ hôm nay nếu đã hết hạn. Gói dùng thử chuyển thành gói thường.
    public function extendSubscription(Request $request, Partner $partner, \App\Services\SubscriptionService $subscriptions): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($partner->isMinihouse() && ! $partner->isSystemPartner(), 404);
        $data = $request->validate(['months' => ['required', 'integer', 'min:1', 'max:60']], [], ['months' => 'số tháng gia hạn']);
        $sub = $partner->subscription;
        abort_unless($sub, 422, 'Đối tác chưa có gói dịch vụ để gia hạn.');

        $sub = $subscriptions->extend($sub, (int) $data['months']);
        PartnerStatusLog::create(['partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status,
            'note' => "Gia hạn gói thêm {$data['months']} tháng, đến " . $sub->expires_at->format('d/m/Y'), 'changed_by' => $request->user()->id]);

        return response()->json([
            'message' => 'Đã gia hạn gói đến ' . $sub->expires_at->format('d/m/Y') . '.',
            'data'    => ['expires_at' => $sub->expires_at->toIso8601String()] + $this->format($partner->fresh(), app(PartnerLegalDocumentService::class)),
        ]);
    }

    public function suspend(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($partner->isSystemPartner(), 404);
        abort_if($partner->verification_status === 'suspended', 422, 'Hồ sơ đã ở trạng thái tạm dừng.');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        app(PartnerLegalDocumentService::class)->suspend($partner, $request->user(), $data['note'] ?? null);

        return response()->json(['message' => 'Đã tạm dừng hồ sơ.', 'data' => $this->format($partner->fresh(), app(PartnerLegalDocumentService::class))]);
    }

    public function updateFinancial(Request $request, Partner $partner): JsonResponse
    {
        $user = $request->user();
        if (! $user->isSuperAdmin()) {
            abort_unless($user->partner_id === $partner->id && ! $partner->isSystemPartner()
                && ($user->hasRole('partner') || $user->hasRole('Quản lý MiniHouse') || $user->can('update_partner')), 403, 'Chỉ chủ đối tác hoặc Super Admin được cập nhật thông tin ngân hàng.');
        }
        $data = $request->validate([
            'bank_code' => ['nullable', 'string', Rule::in(\App\Support\Banks::codes())], 'bank_name' => ['nullable', 'string', Rule::in(\App\Support\Banks::shortNames())], 'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'], 'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'momo_phone' => ['nullable', 'string', 'max:30', 'regex:/^(0|\+84)[0-9]{9,10}$/'], 'zalopay_id' => ['nullable', 'string', 'max:100'],
            'vnpay_id' => ['nullable', 'string', 'max:100'], 'paypal_email' => ['nullable', 'email', 'max:255'],
            'wise_account' => ['nullable', 'string', 'max:255'], 'swift_code' => ['nullable', 'string', 'max:50'],
            'payment_cycle' => ['nullable', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'bank_card_image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], $this->messages());
        // Chủ đối tác chỉ được sửa tài khoản ngân hàng (ví điện tử, chu kỳ thanh toán... do Super Admin quản lý).
        if (! $user->isSuperAdmin()) {
            $data = \Illuminate\Support\Arr::only($data, ['bank_code', 'bank_name', 'bank_branch', 'bank_account_number', 'bank_account_holder']);
        }
        // Ngân hàng CHỌN từ danh sách: bank_code → lưu tên chuẩn vào bank_name.
        if (! empty($data['bank_code'])) {
            $data['bank_name'] = \App\Support\Banks::find($data['bank_code'])['short_name'];
        }
        $partner->update(collect($data)->except(['bank_card_image', 'bank_code'])->all());
        app(\App\Services\PartnerOnboardingService::class)->logBankUpdate($partner, $user);
        if ($user->isSuperAdmin() && $request->hasFile('bank_card_image')) {
            $partner->addMediaFromRequest('bank_card_image')->toMediaCollection('bank_card_image');
        }

        return response()->json(['message' => 'Đã cập nhật thông tin tài chính.', 'data' => $this->financialData($partner->fresh())]);
    }

    public function facilities(Request $request, Partner $partner): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        $items = Category::query()->where('partner_id', $partner->id)->whereNull('parent_id')->where('category_type', 'product')->withCount('products')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn (Category $item) => $this->facilityData($item)), 'summary' => [
            'total_facilities' => $items->count(), 'active_facilities' => $items->where('status', true)->count(),
            'total_rooms' => Product::query()->where('partner_id', $partner->id)->count(),
        ]]);
    }

    public function storeFacility(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate($this->facilityRules());
        $facility = Category::create([...$data, 'slug' => $data['slug'] ?? Str::slug($data['name']), 'category_type' => 'product', 'parent_id' => null, 'partner_id' => $partner->id]);

        return response()->json(['message' => 'Đã tạo cơ sở lưu trú.', 'data' => $this->facilityData($facility)], 201);
    }

    public function showFacility(Request $request, Partner $partner, Category $facility): JsonResponse
    {
        $this->partnerAccess($request, $partner);
        $this->assertFacility($partner, $facility);

        return response()->json(['data' => $this->facilityData($facility)]);
    }

    public function updateFacility(Request $request, Partner $partner, Category $facility): JsonResponse
    {
        $this->superAdmin($request);
        $this->assertFacility($partner, $facility);
        $facility->update($request->validate($this->facilityRules(true)));

        return response()->json(['message' => 'Đã cập nhật cơ sở lưu trú.', 'data' => $this->facilityData($facility->fresh())]);
    }

    public function branchAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $options = $partner->assignableBranchesQuery()
            ->with('partner:id,legal_name,name')
            ->orderBy('name')
            ->get(['id', 'name', 'partner_id'])
            ->map(fn (Category $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'partner_id' => $branch->partner_id,
                'partner_name' => $branch->partner?->legal_name ?: $branch->partner?->name,
            ]);

        return response()->json(['data' => ['selected_ids' => $options->where('partner_id', $partner->id)->pluck('id')->values(), 'options' => $options->values()]]);
    }

    // Homestay (PUT /api/admin/partners/{partner}/branch-assignments) nhận branch_ids; MiniHouse
    // (PUT /api/admin/minihouse/partners/{partner}/building-assignments) nhận building_ids, vẫn chấp
    // nhận branch_ids cho client cũ. Danh sách gửi lên là TOÀN BỘ chi nhánh/tòa nhà của đối tác sau khi
    // lưu — cái nào đang thuộc đối tác mà không có trong danh sách sẽ bị bỏ gán (xem
    // Partner::releasedBranchPartnerId()).
    public function updateBranchAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $field = $partner->isMinihouse() && $request->has('building_ids') ? 'building_ids' : 'branch_ids';
        $data = $request->validate([$field => ['present', 'array'], $field.'.*' => ['integer', 'distinct']]);
        $ids = array_values(array_unique(array_map('intval', $data[$field])));
        $label = $partner->isMinihouse() ? 'tòa nhà' : 'chi nhánh';

        $assignableIds = $partner->assignableBranchesQuery()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($invalid = array_values(array_diff($ids, $assignableIds))) {
            return response()->json([
                'message' => $partner->isMinihouse()
                    ? 'Chỉ được gán tòa nhà đang thuộc đối tác MiniHouse.'
                    : 'Chỉ được gán chi nhánh Homestay hoặc chi nhánh chưa có đối tác.',
                'invalid_ids' => $invalid,
            ], 422);
        }

        $releasedPartnerId = $partner->releasedBranchPartnerId();
        $removed = Category::query()->whereNull('parent_id')->where('category_type', 'product')
            ->where('partner_id', $partner->id)->whereNotIn('id', $ids)->pluck('id')->all();
        if ($removed && $releasedPartnerId === $partner->id) {
            return response()->json([
                'message' => 'Không thể bỏ gán tòa nhà khỏi đối tác MiniHouse nội bộ — hãy gán tòa nhà đó sang đối tác MiniHouse khác.',
                'invalid_ids' => $removed,
            ], 422);
        }

        DB::transaction(function () use ($partner, $ids, $releasedPartnerId) {
            Category::query()->whereNull('parent_id')->where('category_type', 'product')->where(fn ($q) => $q->where('partner_id', $partner->id)->orWhereIn('id', $ids))->get()->each(function (Category $branch) use ($partner, $ids, $releasedPartnerId) {
                $newPartnerId = in_array((int) $branch->id, $ids, true) ? $partner->id : $releasedPartnerId;
                if ($branch->partner_id !== $newPartnerId) {
                    $branch->update(['partner_id' => $newPartnerId]);
                }
            });
        });

        return response()->json(['message' => "Đã cập nhật {$label} của đối tác.", 'data' => ['selected_ids' => $ids, 'released_ids' => $removed, 'released_to_partner_id' => $removed ? $releasedPartnerId : null]]);
    }

    public function userAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $users = User::query()->whereDoesntHave('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')))
            ->where(fn ($query) => $query->whereNull('partner_id')->orWhereHas('partner', fn ($partnerQuery) => $partnerQuery->where('partner_type', $partner->partner_type)))
            ->orderBy('fullname')->get(['id', 'fullname', 'email', 'partner_id']);

        return response()->json(['data' => ['selected_ids' => $users->where('partner_id', $partner->id)->pluck('id')->values(), 'options' => $users]]);
    }

    public function updateUserAssignments(Request $request, Partner $partner): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['uuid', 'distinct', Rule::exists('users', 'id')]]);
        $ids = $data['user_ids'];
        abort_if(User::query()->whereIn('id', $ids)->whereHas('roles', fn ($q) => $q->where('name', config('filament-shield.super_admin.name')))->exists(), 422, 'Không thể gán tài khoản Super Admin.');
        abort_if(User::query()->whereIn('id', $ids)->whereHas('partner', fn ($query) => $query->where('partner_type', '!=', $partner->partner_type))->exists(), 422, 'Không thể chuyển người dùng giữa Homestay và MiniHouse.');
        DB::transaction(function () use ($partner, $ids) {
            User::query()->where(fn ($q) => $q->where('partner_id', $partner->id)->orWhereIn('id', $ids))->get()->each(function (User $user) use ($partner, $ids) {
                $newPartnerId = in_array($user->id, $ids, true) ? $partner->id : null;
                if ($user->partner_id === $newPartnerId) {
                    return;
                }
                $user->update(['partner_id' => $newPartnerId]);
                $permissions = UserBranchPermission::where('user_id', $user->id);
                if ($newPartnerId === null) {
                    $permissions->get()->each->delete();
                } else {
                    $permissions->whereHas('branch', fn ($q) => $q->where('partner_id', '!=', $newPartnerId))->get()->each->delete();
                }
            });
        });

        return response()->json(['message' => 'Đã cập nhật người dùng của đối tác.', 'data' => ['selected_ids' => $ids]]);
    }

    private function financialData(Partner $partner): array
    {
        $media = $partner->getFirstMedia('bank_card_image');

        return ['bank_code' => \App\Support\Banks::findByShortName($partner->bank_name)['code'] ?? null, ...$partner->only(['bank_name', 'bank_branch', 'bank_account_number', 'bank_account_holder', 'momo_phone', 'zalopay_id', 'vnpay_id', 'paypal_email', 'wise_account', 'swift_code', 'payment_cycle']),
            'bank_card_image' => $media ? ['name' => $media->file_name, 'mime_type' => $media->mime_type, 'size' => $media->size, 'url' => $media->getUrl()] : null,
        ];
    }

    private function facilityRules(bool $update = false): array
    {
        return [
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'], 'slug' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'status' => ['sometimes', 'boolean'],
            'lodging_type' => ['nullable', Rule::in(['hotel', 'villa', 'homestay', 'apartment'])],
            'timezone' => ['nullable', Rule::in(['Asia/Ho_Chi_Minh'])], 'operation_manager_name' => ['nullable', 'string', 'max:255'],
            'area_sqm' => ['nullable', 'numeric', 'min:0'], 'established_year' => ['nullable', 'integer', 'min:1990', 'max:'.now()->year],
            'checkin_time' => ['nullable', 'date_format:H:i'], 'checkout_time' => ['nullable', 'date_format:H:i'],
            'default_policy' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function facilityData(Category $facility): array
    {
        $roomIds = $facility->products()->pluck('products.id');

        return [...$facility->only(['id', 'partner_id', 'name', 'slug', 'description', 'status', 'lodging_type', 'timezone', 'operation_manager_name', 'area_sqm', 'established_year', 'checkin_time', 'checkout_time', 'default_policy']),
            'room_count' => $facility->products_count ?? $roomIds->count(),
            'monthly_revenue' => (float) Order::query()->where('category_id', $facility->id)->where('status', 'paid')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum(DB::raw('COALESCE(amount, full_amount)')),
            'rating' => ['average' => ($average = RoomRating::query()->whereIn('room_id', $roomIds)->avg('star')) !== null ? (float) $average : null, 'count' => RoomRating::query()->whereIn('room_id', $roomIds)->count()],
        ];
    }

    private function assertFacility(Partner $partner, Category $facility): void
    {
        abort_unless($facility->partner_id === $partner->id && $facility->parent_id === null && $facility->category_type === 'product', 404);
    }

    // Thông báo tiếng Việt cho các quy tắc định dạng (mặc định hiện tên trường tiếng Anh/“today” khó hiểu).
    private function messages(): array
    {
        return [
            'tax_code.regex' => 'Mã số thuế gồm 10 số (hoặc 13 số cho đơn vị phụ thuộc, vd 0312345678-001).',
            'phone.regex' => 'Số điện thoại phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.',
            'representative_phone_secondary.regex' => 'Số điện thoại phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.',
            'momo_phone.regex' => 'Số điện thoại MoMo phải dạng 0xxxxxxxxx hoặc +84xxxxxxxxx.',
            'representative_id_number.regex' => 'Số giấy tờ gồm 6–20 chữ/số, không dấu cách.',
            'representative_dob.before_or_equal' => 'Người đại diện phải đủ 18 tuổi.',
            'business_license_date.before_or_equal' => 'Ngày cấp giấy phép kinh doanh không được ở tương lai.',
            'contract_expires_at.after' => 'Ngày hết hạn hợp đồng phải sau hôm nay.',
            'bank_account_number.regex' => 'Số tài khoản chỉ gồm 6–20 chữ số.',
            'bank_code.in' => 'Ngân hàng không có trong danh sách — vui lòng chọn lại.',
            'bank_name.in' => 'Ngân hàng không có trong danh sách — vui lòng chọn lại.',
        ];
    }

    private function rules(bool $update = false, ?Partner $partner = null): array
    {
        $sometimes = $update ? 'sometimes' : 'required';
        $vnPhone = 'regex:/^(0|\+84)[0-9]{9,10}$/';

        return [
            'legal_name' => [$sometimes, 'string', 'max:255'],
            'tax_code' => ['nullable', 'string', 'regex:/^\d{10}(-?\d{3})?$/'],
            'phone' => ['nullable', 'string', 'max:30', $vnPhone], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'], 'province_code' => ['nullable', 'integer', 'exists:provinces,code'], 'representative_name' => [$sometimes, 'string', 'max:255'],
            'representative_dob' => ['nullable', 'date', 'before_or_equal:' . now()->subYears(18)->toDateString()],
            'representative_id_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{6,20}$/'],
            'representative_position' => ['nullable', 'string', 'max:100'],
            'representative_id_issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'representative_id_issued_place' => ['nullable', 'string', 'max:255'],
            'representative_phone_secondary' => ['nullable', 'string', 'max:30', $vnPhone],
            'business_license_date' => ['nullable', 'date', 'before_or_equal:today'], 'business_license_issuer' => ['nullable', 'string', 'max:255'],
            'contract_type' => ['nullable', Rule::in(['e_contract', 'paper'])],
            'contract_expires_at' => ['nullable', 'date', 'after:today'],
            'commission_rate' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && app(\App\Services\PartnerContractWorkflowService::class)->commissionValue((string) $value) === null) {
                    $fail('Tỷ lệ hoa hồng phải là số từ 0 đến 100 (vd 10 hoặc 10%).');
                }
            }],
            'cancellation_policy' => ['nullable', 'string', 'max:5000'],
            // Tắt = đơn/phòng của đối tác không vào số liệu tổng hợp của Super Admin (đối tác dùng thử, dữ liệu test).
            'count_in_platform_stats' => ['sometimes', 'boolean'],
        ];
    }

    private function format(Partner $partner, PartnerLegalDocumentService $documents): array
    {
        $sub = $partner->subscription()->with('plan:id,code,name')->first();

        return ['subscription' => $sub ? ['plan' => $sub->plan?->only(['id', 'code', 'name']), 'status' => $sub->state(), 'status_label' => \App\Models\PartnerSubscription::STATES[$sub->state()] ?? $sub->state(), 'is_trial' => $sub->is_trial, 'started_at' => $sub->started_at?->toIso8601String(), 'expires_at' => $sub->expires_at?->toIso8601String(), 'days_left' => $sub->daysLeft()] : null, ...$partner->only(['id', 'partner_type', 'legal_name', 'tax_code', 'phone', 'email', 'address', 'representative_name', 'representative_dob', 'representative_position', 'representative_id_number', 'representative_id_issued_at', 'representative_id_issued_place', 'business_license_date', 'business_license_issuer', 'verification_status', 'contract_code', 'contract_type', 'contract_status', 'contract_signed_at', 'contract_expires_at', 'commission_rate', 'count_in_platform_stats', 'province_code']), 'province_name' => $partner->province_code !== null ? \App\Models\Province::query()->where('code', $partner->province_code)->value('name') : null, 'fee_free_until' => $partner->fee_free_until?->toIso8601String(), 'fee_free_active' => $partner->isInFeeFreePeriod(), 'verification' => $documents->readiness($partner)];
    }

    private function partnerType(Request $request): string
    {
        return $request->is('api/admin/minihouse/*') ? Partner::TYPE_MINIHOUSE : Partner::TYPE_HOMESTAY;
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }

    private function partnerAccess(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }
}
