<?php

namespace Tests\Feature\Api;

use App\Models\Partner;
use App\Services\LegalDocumentScanService;
use App\Services\PartnerOnboardingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Giấy tờ pháp lý có BỘ Ô RIÊNG theo loại (ĐKKD / ANTT / PCCC — App\Support\LegalDocumentFields) và API QUÉT gợi ý giá trị cho các ô đó.
// OCR được thay bằng đoạn chữ giả lập (không gọi OCR.space thật).
class LegalDocumentFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private string $token = 'legal-fields-test-token';

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Test', 'legal_name' => 'Homestay Test', 'phone' => '0970000201',
            'email' => 'legal-fields@example.test', 'address' => '12 Lê Lợi', 'status' => false, 'verification_status' => 'pending',
            'contract_status' => 'draft', 'onboarding_token' => PartnerOnboardingService::hashToken($this->token),
        ]);
    }

    public function test_schema_endpoint_lists_the_three_forms(): void
    {
        $response = $this->getJson('/api/public/legal-document-types')->assertOk();

        $this->assertSame(['business_license', 'security_order', 'fire_safety'], array_column($response->json('data.forms'), 'type'));
        $this->assertSame(
            ['document_number', 'issued_at', 'issuer', 'business_address', 'business_lines', 'legal_representative', 'representative_id_number', 'phone'],
            array_column($response->json('data.forms.0.fields'), 'key'),
        );
        $this->assertSame('Số văn bản', $response->json('data.forms.2.fields.0.label'));
        $this->assertCount(8, $response->json('data.types'));
    }

    public function test_each_type_stores_only_its_own_fields(): void
    {
        $response = $this->upload([
            'type' => 'fire_safety', 'document_number' => '48/TD-PCCC', 'issued_at' => '2023-11-02', 'issuer' => 'Phòng Cảnh sát PCCC và CNCH',
            'investor' => 'Công ty TNHH Nhà trọ An Bình', 'representative' => 'Nguyễn Văn An', 'representative_title' => 'Giám đốc',
            'site_address' => '12 Lê Lợi, Cần Thơ',
            // Ô của loại khác gửi kèm phải bị bỏ qua.
            'business_lines' => 'Lưu trú ngắn ngày',
        ])->assertCreated();

        $response->assertJsonPath('data.document_number', '48/TD-PCCC')
            ->assertJsonPath('data.extra.investor', 'Công ty TNHH Nhà trọ An Bình')
            ->assertJsonPath('data.extra.representative_title', 'Giám đốc')
            ->assertJsonMissingPath('data.extra.business_lines')
            ->assertJsonPath('data.fire_safety_stage.code', 'design_approved')
            ->assertJsonPath('data.fields.0.label', 'Số văn bản')
            ->assertJsonPath('data.fields.6.value', '12 Lê Lợi, Cần Thơ');

        // MySQL tự sắp lại khoá của cột JSON nên so sánh không phụ thuộc thứ tự.
        $this->assertEquals(
            ['investor' => 'Công ty TNHH Nhà trọ An Bình', 'representative' => 'Nguyễn Văn An', 'representative_title' => 'Giám đốc', 'site_address' => '12 Lê Lợi, Cần Thơ'],
            $this->partner->legalDocuments()->first()->extra,
        );
    }

    public function test_type_specific_fields_are_optional_but_validated_when_sent(): void
    {
        // Chỉ loại + tệp vẫn nộp được như trước (client cũ không bị vỡ).
        $this->upload(['type' => 'security_order'])->assertCreated()->assertJsonPath('data.fire_safety_stage', null);

        $this->upload(['type' => 'business_license', 'representative_id_number' => '12345', 'phone' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors(['representative_id_number', 'phone']);
    }

    public function test_scan_returns_suggestions_without_saving(): void
    {
        $this->app->bind(LegalDocumentScanService::class, fn () => new class extends LegalDocumentScanService
        {
            public function isConfigured(): bool
            {
                return true;
            }

            protected function extractText(UploadedFile $file): string
            {
                return "CÔNG AN THÀNH PHỐ CẦN THƠ\nPHÒNG CẢNH SÁT QLHC VỀ TTXH\nSố: 125/GCN\nCần Thơ, ngày 14 tháng 6 năm 2022\n"
                    . "GIẤY CHỨNG NHẬN ĐỦ ĐIỀU KIỆN VỀ AN NINH, TRẬT TỰ\nTên cơ sở kinh doanh: NHÀ TRỌ AN BÌNH\nĐịa chỉ: 12 Lê Lợi, Cần Thơ";
            }
        });

        $this->post("/api/public/partner-onboarding/{$this->token}/documents/scan", [
            'type' => 'security_order', 'file' => UploadedFile::fake()->create('antt.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.fields.document_number', '125/GCN')
            ->assertJsonPath('data.fields.issued_at', '2022-06-14')
            ->assertJsonPath('data.fields.business_name', 'NHÀ TRỌ AN BÌNH')
            ->assertJsonPath('data.fields.responsible_person', null)
            ->assertJsonPath('data.type_matches', true)
            ->assertJsonPath('data.total', 7);

        $this->assertSame(0, $this->partner->legalDocuments()->count());

        // Chỉ quét được 3 loại có bộ ô riêng.
        $this->post("/api/public/partner-onboarding/{$this->token}/documents/scan", [
            'type' => 'other', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    private function upload(array $fields)
    {
        return $this->post("/api/public/partner-onboarding/{$this->token}/documents", $fields + [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }
}
