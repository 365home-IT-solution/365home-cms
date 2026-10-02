<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerLegalDocument;
use App\Services\PartnerOnboardingService;
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
            'partner_type'  => ['required', Rule::in([Partner::TYPE_HOMESTAY, Partner::TYPE_MINIHOUSE])],
            'full_name'     => ['required', 'string', 'max:255'],
            'phone'         => ['required', 'string', 'regex:/^(0|\+84)[0-9]{9}$/'],
            'email'         => ['required', 'email', 'max:255'],
            'business_name' => ['required', 'string', 'max:255'],
            'address'       => ['required', 'string', 'max:500'],
            'note'          => ['nullable', 'string', 'max:2000'],
        ], ['phone.regex' => 'Số điện thoại không hợp lệ.'], PartnerOnboardingService::LABELS);

        $result = $this->service->register($data);

        return response()->json([
            'message' => 'Đã tạo hồ sơ đăng ký hợp tác. Lưu lại mã hồ sơ để tiếp tục nộp giấy tờ và ký hợp đồng.',
            'data'    => ['onboarding_token' => $result['token'], ...$this->service->status($result['partner'])],
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

    // POST /api/public/partner-onboarding/{token}/documents (multipart)
    public function storeDocument(Request $request, string $token): JsonResponse
    {
        $partner = $this->service->findByToken($token);
        $data = $request->validate([
            'type'            => ['required', Rule::in(array_keys(PartnerLegalDocument::TYPES))],
            'name'            => ['nullable', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issuer'          => ['nullable', 'string', 'max:255'],
            'issued_at'       => ['nullable', 'date'],
            'expires_at'      => ['nullable', 'date', 'after_or_equal:issued_at'],
            'file'            => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [], PartnerOnboardingService::LABELS);

        $document = $this->service->addDocument($partner, $data, $request->file('file'));

        return response()->json(['message' => 'Đã nộp giấy tờ.', 'data' => $this->service->formatDocument($document->fresh('media'))], 201);
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
            'tax_code'                 => ['nullable', 'string', 'max:50'],
            'address'                  => ['required', 'string', 'max:500'],
            'email'                    => ['required', 'email', 'max:255'],
            'representative_name'      => ['required', 'string', 'max:255'],
            'representative_id_number' => ['required', 'string', 'regex:/^[0-9]{9}([0-9]{3})?$/'],
            'representative_dob'       => ['nullable', 'date', 'before:today'],
            'business_license_date'    => ['nullable', 'date'],
            'business_license_issuer'  => ['nullable', 'string', 'max:255'],
            'bank_name'                => ['nullable', 'string', 'max:255'],
            'bank_branch'              => ['nullable', 'string', 'max:255'],
            'bank_account_number'      => ['nullable', 'string', 'max:50'],
            'bank_account_holder'      => ['nullable', 'string', 'max:255'],
        ], ['representative_id_number.regex' => 'Số CMND/CCCD phải gồm 9 hoặc 12 chữ số.'], PartnerOnboardingService::LABELS);

        $partner = $this->service->updateContractInfo($partner, $data);

        return response()->json(['message' => 'Đã lưu thông tin ký hợp đồng.', 'data' => $this->service->status($partner)]);
    }

    // POST /api/public/partner-onboarding/{token}/submit
    public function submit(string $token): JsonResponse
    {
        $partner = $this->service->submit($this->service->findByToken($token));

        return response()->json([
            'message' => 'Đã gửi hồ sơ cho 365 Home. Sau khi giấy tờ được duyệt, hợp đồng sẽ được gửi về email của bạn để ký.',
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
