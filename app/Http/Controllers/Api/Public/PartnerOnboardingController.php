<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerLegalDocument;
use App\Services\LegalDocumentScanService;
use App\Services\PartnerOnboardingService;
use App\Support\LegalDocumentFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Đăng ký hợp tác CÔNG KHAI — khách chưa có tài khoản đối tác, gửi hồ sơ để admin duyệt (xem PartnerOnboardingService).
// Luồng: đăng ký → giấy tờ + thông tin → gửi duyệt → (admin duyệt) → ký hợp đồng → (admin ký) → cấp tài khoản. Các bước sau đăng ký xác thực bằng mã hồ sơ {token} trả về ở bước 1.
class PartnerOnboardingController extends Controller
{
    public function __construct(private readonly PartnerOnboardingService $service)
    {
    }

    // POST /api/public/partner-onboarding
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'partner_type'  => ['required', Rule::in(config('partner_flow.minihouse_contract_enabled') ? [Partner::TYPE_HOMESTAY, Partner::TYPE_MINIHOUSE] : [Partner::TYPE_HOMESTAY])],
            'full_name'     => ['required', 'string', 'max:255'],
            'phone'         => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'email'         => ['required', 'email', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            // Địa chỉ: gửi dạng có cấu trúc (khuyến nghị) HOẶC chuỗi `address` như bản cũ.
            'address'               => ['required_without:address_street', 'nullable', 'string', 'max:500'],
            'address_street'        => ['required_without:address', 'nullable', 'string', 'max:255'],
            'address_province_code' => ['required_with:address_street', 'nullable', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\Province::class, 'code')],
            'address_ward_code'     => ['required_with:address_street', 'nullable', 'integer', \Illuminate\Validation\Rule::exists(\App\Models\Ward::class, 'code')],
            'address_unit'          => ['nullable', 'string', 'max:100'],
            'address_building'      => ['nullable', 'string', 'max:150'],
            'postal_code'           => ['nullable', 'regex:/^[0-9]{5,6}$/'],
            'note'          => ['nullable', 'string', 'max:2000'],
        ] + \App\Services\TermsService::rulesFor(\App\Services\TermsService::typeForPartner((string) $request->input('partner_type'))), \App\Services\TermsService::ACCEPT_MESSAGES + ['partner_type.in' => 'MiniHouse không đăng ký đối tác/ký hợp đồng: vui lòng mua gói dịch vụ MiniHouse (POST /api/public/minihouse-purchase).', 'phone.regex' => 'Số điện thoại không hợp lệ.', 'postal_code.regex' => 'Mã bưu điện gồm 5–6 chữ số.', 'address_street.required_without' => 'Vui lòng nhập số nhà, tên đường/phố.', 'address.required_without' => 'Vui lòng nhập địa chỉ.'], PartnerOnboardingService::LABELS);

        $terms = app(\App\Services\TermsService::class);
        $termsType = \App\Services\TermsService::typeForPartner($data['partner_type']);
        $version = $terms->required($termsType) ? $terms->currentOrFail($termsType, isset($data['terms_version_id']) ? (int) $data['terms_version_id'] : null) : null;

        $data['address'] = $this->service->composeAddress($data);
        $result = $this->service->register($data);
        // Công tắc Điều khoản tắt → đăng ký như trước, không đòi/không ghi lịch sử đồng ý.
        $acceptance = $version ? $terms->record($version, $result['partner'], $data, $request, ['source' => 'api', 'meta' => ['partner_type' => $data['partner_type']]]) : null;

        return response()->json([
            'message' => 'Đã tạo hồ sơ đăng ký hợp tác. Lưu lại mã hồ sơ để tiếp tục nộp giấy tờ và ký hợp đồng.',
            'data'    => ['onboarding_token' => $result['token'], ...($acceptance ? ['terms_acceptance' => ['id' => $acceptance->id, 'version' => $acceptance->terms_version_label, 'accepted_at' => $acceptance->accepted_at->toIso8601String()]] : []), ...$this->service->status($result['partner'])],
        ], 201);
    }

    // POST /api/public/partner-onboarding/recover — gửi lại link hồ sơ (mã mới) về email đã đăng ký
    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'email' => ['required', 'email', 'max:255'],
        ], ['phone.regex' => 'Số điện thoại không hợp lệ.'], PartnerOnboardingService::LABELS);

        $this->service->recover($data['phone'], $data['email']);

        return response()->json(['message' => 'Nếu thông tin khớp hồ sơ đã đăng ký, liên kết tiếp tục hồ sơ đã được gửi về email đó.']);
    }

    // GET /api/public/partner-onboarding/{token}
    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->service->status($this->service->findByToken($token))]);
    }

    // GET /api/public/legal-document-types — loại giấy tờ + BỘ Ô NHẬP RIÊNG của ĐKKD / CCCD / ANTT / PCCC để client dựng form (loại khác dùng form chung).
    public function documentTypes(): JsonResponse
    {
        return response()->json(['data' => [
            'types'              => collect(PartnerLegalDocument::TYPES)->map(fn ($label, $type) => ['type' => $type, 'label' => $label, 'has_form' => LegalDocumentFields::has($type)])->values(),
            'forms'              => LegalDocumentFields::schema(),
            // Giấy tờ bắt buộc khi đăng ký theo loại đối tác (config partner_flow.registration_required_documents).
            'required'           => [
                Partner::TYPE_HOMESTAY  => PartnerLegalDocument::registrationRequiredFor(Partner::TYPE_HOMESTAY),
                Partner::TYPE_MINIHOUSE => PartnerLegalDocument::registrationRequiredFor(Partner::TYPE_MINIHOUSE),
            ],
            'fire_safety_stages' => LegalDocumentFields::FIRE_SAFETY_STAGES,
        ]]);
    }

    // POST /api/public/partner-onboarding/{token}/documents/scan (multipart: type, file) — QUÉT giấy tờ, trả giá trị GỢI Ý cho các ô của loại đó.
    // Không lưu gì: client điền vào form cho khách kiểm tra/sửa rồi mới gọi POST .../documents.
    public function scanDocument(Request $request, string $token, LegalDocumentScanService $scanner): JsonResponse
    {
        $this->service->findByToken($token);

        return self::scanResponse($request, $scanner);
    }

    /** Dùng chung cho API quét công khai và admin. */
    public static function scanResponse(Request $request, LegalDocumentScanService $scanner): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(LegalDocumentFields::types())],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            // CCCD: file = ảnh MẶT TRƯỚC, file_back = ảnh MẶT SAU (bắt buộc đủ hai mặt; mã QR nằm ở mặt nào cũng được).
            'file_back' => ['required_if:type,citizen_id', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], ['type.in' => 'Chỉ quét được Giấy phép kinh doanh, CCCD, Giấy chứng nhận an ninh trật tự và Hồ sơ phòng cháy chữa cháy.',
            'file_back.required_if' => 'CCCD phải có ảnh mặt sau.'], PartnerOnboardingService::LABELS + ['file_back' => 'ảnh mặt sau']);

        // CCCD: BẮT BUỘC đọc được mã QR trên thẻ (không OCR), thử cả mặt trước và mặt sau — không đọc được thì báo lỗi ở ô tệp để khách chụp lại.
        if ($data['type'] === 'citizen_id') {
            $result = $scanner->scan($request->file('file'), $data['type'], $request->file('file_back'));
            if (! $result['qr']) {
                throw \Illuminate\Validation\ValidationException::withMessages(['file' => $result['warnings']]);
            }

            return response()->json([
                'message' => 'Đã đọc mã QR trên CCCD. Vui lòng kiểm tra lại trước khi nộp.',
                'data'    => collect($result)->except('text_found')->all(),
            ]);
        }

        abort_unless($scanner->isConfigured(), 503, 'Chức năng quét giấy tờ chưa được cấu hình. Vui lòng tự nhập thông tin.');

        $result = $scanner->scan($request->file('file'), $data['type']);

        return response()->json([
            'message' => match (true) {
                ! $result['text_found'] => 'Không đọc được nội dung tệp. Vui lòng tự nhập thông tin.',
                $result['found'] === 0  => 'Chưa nhận ra thông tin nào trên giấy tờ. Vui lòng tự nhập.',
                default                 => "Đã đọc được {$result['found']}/{$result['total']} ô. Vui lòng kiểm tra lại trước khi nộp.",
            },
            'data' => collect($result)->except('text_found')->all(),
        ]);
    }

    // POST /api/public/partner-onboarding/{token}/documents (multipart)
    public function storeDocument(Request $request, string $token): JsonResponse
    {
        $partner = $this->service->findByToken($token);
        $data = $request->validate(LegalDocumentFields::rules() + [
            'type'            => ['required', Rule::in(array_keys(PartnerLegalDocument::TYPES))],
            'name'            => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issuer'          => ['nullable', 'string', 'max:255'],
            'issued_at'       => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at'      => ['nullable', 'date', 'after_or_equal:issued_at', 'after:today'],
            'file'            => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            // CCCD: file = ảnh MẶT TRƯỚC, file_back = ảnh MẶT SAU (bắt buộc đủ hai mặt).
            'file_back'       => ['required_if:type,citizen_id', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], LegalDocumentFields::messages() + [
            'file_back.required_if' => 'CCCD phải có ảnh mặt sau.',
            'issued_at.before_or_equal' => 'Ngày cấp không được ở tương lai.',
            'expires_at.after' => 'Giấy tờ đã hết hạn — vui lòng nộp giấy tờ còn hiệu lực.',
            'expires_at.after_or_equal' => 'Ngày hết hạn phải sau hoặc bằng ngày cấp.',
        ], PartnerOnboardingService::LABELS + LegalDocumentFields::attributes() + ['file_back' => 'ảnh mặt sau']);

        $document = $this->service->addDocument($partner, $data, $request->file('file'), $request->file('file_back'));

        return response()->json(['message' => 'Đã nộp giấy tờ.', 'data' => $this->service->formatDocument($document->fresh('media'))], 201);
    }

    // POST /api/public/partner-onboarding/{token}/contract — luồng "đối tác ký trước": tạo hợp đồng điều khoản chuẩn để ký ngay (trước khi gửi duyệt).
    // Trả trạng thái hồ sơ; contract.signing_token dùng cho /api/partner-contracts/{token} (xem nội dung, gửi OTP, xác nhận).
    public function prepareContract(string $token): JsonResponse
    {
        $partner = $this->service->prepareContract($this->service->findByToken($token));

        return response()->json(['message' => 'Hợp đồng đã sẵn sàng để ký.', 'data' => $this->service->status($partner)]);
    }

    // DELETE /api/public/partner-onboarding/{token}/documents/{document}
    public function destroyDocument(string $token, string $document): JsonResponse
    {
        $partner = $this->service->findByToken($token);
        $this->service->deleteDocument($partner, $partner->legalDocuments()->whereKey($document)->firstOrFail());

        return response()->json(['message' => 'Đã xoá giấy tờ.']);
    }

    // PUT /api/public/partner-onboarding/{token}/contract-info
    public function updateContractInfo(Request $request, string $token): JsonResponse
    {
        $partner = $this->service->findByToken($token);
        $data = $request->validate([
            'legal_name'               => ['required', 'string', 'max:255'],
            'tax_code'                 => ['nullable', 'string', 'regex:/^\d{10}(-?\d{3})?$/'],
            'address'                  => ['required', 'string', 'max:500'],
            'email'                    => ['required', 'email', 'max:255'],
            'representative_name'      => ['required', 'string', 'max:255'],
            'representative_id_number' => ['required', 'string', 'regex:/^[0-9]{9}([0-9]{3})?$/'],
            'representative_position'  => ['nullable', 'string', 'max:100'],
            'representative_id_issued_at' => ['required', 'date', 'before_or_equal:today'],
            'representative_id_issued_place' => ['nullable', 'string', 'max:255'],
            'representative_dob'       => ['nullable', 'date', 'before_or_equal:' . now()->subYears(18)->toDateString()],
            'business_license_date'    => ['nullable', 'date', 'before_or_equal:today'],
            'business_license_issuer'  => ['nullable', 'string', 'max:255'],
        ], [
            'representative_id_number.regex' => 'Số CMND/CCCD phải gồm 9 hoặc 12 chữ số.',
            'tax_code.regex'                 => 'Mã số thuế gồm 10 số (hoặc 13 số cho đơn vị phụ thuộc, vd 0312345678-001).',
            'representative_dob.before_or_equal' => 'Người đại diện phải đủ 18 tuổi.',
            'representative_id_issued_at.required' => 'Vui lòng nhập ngày cấp CMND/CCCD.',
            'representative_id_issued_at.before_or_equal' => 'Ngày cấp CMND/CCCD không được ở tương lai.',
            'business_license_date.before_or_equal' => 'Ngày cấp giấy phép kinh doanh không được ở tương lai.',
        ], PartnerOnboardingService::LABELS);

        $partner = $this->service->updateContractInfo($partner, $data);

        return response()->json(['message' => 'Đã lưu thông tin ký hợp đồng.', 'data' => $this->service->status($partner)]);
    }

    // POST /api/public/partner-onboarding/{token}/submit
    public function submit(string $token): JsonResponse
    {
        $partner = $this->service->submit($this->service->findByToken($token));

        return response()->json([
            'message' => $partner->usesContract()
                ? 'Đã gửi hồ sơ cho 365 Home. Sau khi giấy tờ được duyệt, hợp đồng sẽ được gửi về email của bạn để ký.'
                : 'Đã gửi giấy tờ cho 365 Home. Sau khi giấy tờ được duyệt, tài khoản dùng thử sẽ được gửi về email của bạn.',
            'data'    => $this->service->status($partner),
        ]);
    }

    // POST /api/public/partner-onboarding/{token}/withdraw — rút hồ sơ đã gửi (chưa được duyệt) để sửa
    public function withdraw(string $token): JsonResponse
    {
        $partner = $this->service->withdraw($this->service->findByToken($token));

        return response()->json([
            'message' => 'Đã rút hồ sơ về bản nháp. Bạn có thể chỉnh sửa rồi gửi duyệt lại.',
            'data'    => $this->service->status($partner),
        ]);
    }
}
