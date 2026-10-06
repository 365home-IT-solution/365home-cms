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

        $this->assertSame(['business_license', 'security_order', 'fire_safety', 'citizen_id'], array_column($response->json('data.forms'), 'type'));
        $this->assertSame(
            ['dkkd_document_number', 'dkkd_issued_at', 'dkkd_issuer', 'dkkd_business_address', 'dkkd_business_lines', 'dkkd_legal_representative', 'dkkd_representative_id_number', 'dkkd_phone'],
            array_column($response->json('data.forms.0.fields'), 'key'),
        );
        $this->assertSame('Số văn bản', $response->json('data.forms.2.fields.0.label'));
        $this->assertSame('pccc_document_number', $response->json('data.forms.2.fields.0.key'));

        // Không ô nào của loại này trùng tên cột với loại khác — kể cả số / ngày cấp / nơi cấp.
        $keys = array_merge(...array_map(fn ($form) => array_column($form['fields'], 'key'), $response->json('data.forms')));
        $this->assertCount(29, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertCount(9, $response->json('data.types'));
    }

    public function test_each_type_stores_its_fields_in_its_own_columns(): void
    {
        $response = $this->upload([
            'type' => 'fire_safety', 'pccc_document_number' => '48/TD-PCCC', 'pccc_issued_at' => '2023-11-02', 'pccc_issuer' => 'Phòng Cảnh sát PCCC và CNCH',
            'pccc_investor' => 'Công ty TNHH Nhà trọ An Bình', 'pccc_representative' => 'Nguyễn Văn An', 'pccc_representative_title' => 'Giám đốc',
            'pccc_site_address' => '12 Lê Lợi, Cần Thơ',
            // Ô của loại khác gửi kèm phải bị bỏ qua — kể cả ô trùng tên (ngày cấp, nơi cấp).
            'dkkd_issued_at' => '2020-01-01', 'antt_issuer' => 'Công an tỉnh', 'dkkd_business_lines' => 'Lưu trú ngắn ngày',
        ])->assertCreated();

        $response->assertJsonPath('data.fire_safety_stage.code', 'design_approved')
            ->assertJsonPath('data.fields.0.key', 'pccc_document_number')
            ->assertJsonPath('data.fields.0.value', '48/TD-PCCC')
            ->assertJsonPath('data.fields.1.value', '2023-11-02')
            ->assertJsonPath('data.fields.6.value', '12 Lê Lợi, Cần Thơ')
            // 3 cột chung chỉ là bản tóm tắt chép từ cột riêng.
            ->assertJsonPath('data.document_number', '48/TD-PCCC')
            ->assertJsonPath('data.issuer', 'Phòng Cảnh sát PCCC và CNCH')
            ->assertJsonPath('data.issued_at', '2023-11-02');

        $document = $this->partner->legalDocuments()->first();
        $this->assertSame('Công ty TNHH Nhà trọ An Bình', $document->pccc_investor);
        $this->assertSame('2023-11-02', $document->pccc_issued_at->toDateString());
        foreach (['dkkd_issued_at', 'dkkd_business_lines', 'dkkd_document_number', 'antt_issuer', 'antt_issued_at'] as $otherTypeColumn) {
            $this->assertNull($document->{$otherTypeColumn});
        }
    }

    public function test_changing_type_clears_the_old_types_columns(): void
    {
        $this->upload(['type' => 'business_license', 'dkkd_document_number' => '1801234567', 'dkkd_issuer' => 'Phòng ĐKKD', 'dkkd_issued_at' => '2021-03-05'])->assertCreated();
        $document = $this->partner->legalDocuments()->first();

        $document->update(['type' => 'security_order', 'antt_document_number' => '125/GCN']);
        $document->refresh();

        $this->assertNull($document->dkkd_document_number);
        $this->assertNull($document->dkkd_issuer);
        $this->assertSame('125/GCN', $document->antt_document_number);
        $this->assertSame('125/GCN', $document->document_number);
        // Nơi cấp / ngày cấp của ĐKKD không trôi sang ANTT.
        $this->assertNull($document->antt_issuer);
        $this->assertNull($document->issuer);
        $this->assertNull($document->issued_at);
    }

    public function test_type_specific_fields_are_optional_but_validated_when_sent(): void
    {
        // Chỉ loại + tệp vẫn nộp được như trước (client cũ không bị vỡ); client cũ gửi cột chung thì được chép vào cột riêng của loại.
        $this->upload(['type' => 'security_order', 'document_number' => '9/GCN'])->assertCreated()
            ->assertJsonPath('data.fire_safety_stage', null)
            ->assertJsonPath('data.fields.0.value', '9/GCN');

        $this->upload(['type' => 'business_license', 'dkkd_representative_id_number' => '12345', 'dkkd_phone' => 'abc', 'dkkd_issued_at' => '2999-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['dkkd_representative_id_number', 'dkkd_phone', 'dkkd_issued_at']);
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
            ->assertJsonPath('data.fields.antt_document_number', '125/GCN')
            ->assertJsonPath('data.fields.antt_issued_at', '2022-06-14')
            ->assertJsonPath('data.fields.antt_business_name', 'NHÀ TRỌ AN BÌNH')
            ->assertJsonPath('data.fields.antt_responsible_person', null)
            ->assertJsonPath('data.type_matches', true)
            ->assertJsonPath('data.total', 7);

        $this->assertSame(0, $this->partner->legalDocuments()->count());

        // Chỉ quét được các loại có bộ ô riêng.
        $this->post("/api/public/partner-onboarding/{$this->token}/documents/scan", [
            'type' => 'other', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_admin_api_stores_updates_and_scans_per_type_fields(): void
    {
        $admin = \App\Models\User::create(['fullname' => 'Super Admin Test', 'email' => 'legal-fields-admin@example.test', 'password' => 'secret-secret']);
        $admin->assignRole(config('filament-shield.super_admin.name'));
        $headers = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $base = "/api/admin/partners/{$this->partner->id}/legal-documents";

        // Danh sách trả kèm cấu trúc form của 4 loại (ĐKKD / ANTT / PCCC / CCCD).
        $this->get($base, $headers)->assertOk()->assertJsonCount(4, 'document_forms');

        // Thêm: lưu vào cột riêng của đúng loại, bỏ ô của loại khác.
        $id = $this->post($base, [
            'type' => 'business_license', 'dkkd_document_number' => '1800000047', 'dkkd_issued_at' => '2021-09-28', 'dkkd_issuer' => 'Phòng ĐKKD',
            'dkkd_legal_representative' => 'Nguyễn Văn An', 'pccc_investor' => 'Không thuộc loại này',
            'file' => UploadedFile::fake()->create('dkkd.pdf', 100, 'application/pdf'),
        ], $headers)->assertCreated()
            ->assertJsonPath('data.fields.0.value', '1800000047')
            ->assertJsonPath('data.document_number', '1800000047')
            ->assertJsonPath('data.fields.5.value', 'Nguyễn Văn An')
            ->json('data.id');
        $this->assertNull($this->partner->legalDocuments()->find($id)->pccc_investor);

        // Sửa: gửi ô nào cập nhật ô đó, ô khác giữ nguyên; cột tóm tắt chung đổi theo.
        $this->post("{$base}/{$id}", ['dkkd_issuer' => 'Phòng Đăng ký kinh doanh, Sở Tài chính', 'dkkd_phone' => '0900000188'], $headers)->assertOk()
            ->assertJsonPath('data.issuer', 'Phòng Đăng ký kinh doanh, Sở Tài chính')
            ->assertJsonPath('data.fields.0.value', '1800000047')
            ->assertJsonPath('data.fields.7.value', '0900000188');

        // Quét phía admin dùng chung bộ dò với phía công khai.
        $this->app->bind(LegalDocumentScanService::class, fn () => new class extends LegalDocumentScanService
        {
            public function isConfigured(): bool
            {
                return true;
            }

            protected function extractText(UploadedFile $file): string
            {
                return (string) file_get_contents(base_path('tests/Fixtures/legal-documents/pccc.txt'));
            }
        });
        $this->post("{$base}/scan", ['type' => 'fire_safety', 'file' => UploadedFile::fake()->create('pccc.jpg', 100, 'image/jpeg')], $headers)->assertOk()
            ->assertJsonPath('data.fields.pccc_document_number', '82/TD-PCCC')
            ->assertJsonPath('data.fire_safety_stage.code', 'design_approved');
    }

    // CCCD qua API admin: CÙNG quy tắc với API đăng ký của đối tác — ảnh phải đọc được mã QR, ô lấy từ QR do QR quyết định (thêm lẫn sửa).
    public function test_admin_api_applies_the_same_citizen_id_qr_rule(): void
    {
        config(['cache.default' => 'array']);
        $admin = \App\Models\User::create(['fullname' => 'Super Admin Test', 'email' => 'legal-cccd-admin@example.test', 'password' => 'secret-secret']);
        $admin->assignRole(config('filament-shield.super_admin.name'));
        $headers = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $base = "/api/admin/partners/{$this->partner->id}/legal-documents";
        $qr = fn (?array $data) => $this->mock(\Modules\Payment\App\Services\CccdScannerService::class, fn ($mock) => $mock->shouldReceive('scanQrImage')->andReturn($data));
        $image = fn (int $width = 800) => UploadedFile::fake()->image('cccd.jpg', $width, 500);
        $back = fn (int $width = 810) => UploadedFile::fake()->image('cccd-sau.jpg', $width, 500);

        // Không đọc được mã QR (hoặc tệp PDF) → từ chối, không lưu.
        $qr(null);
        $this->post("{$base}/scan", ['type' => 'citizen_id', 'file' => $image(), 'file_back' => $back()], $headers)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($base, ['type' => 'citizen_id', 'cccd_document_number' => '092088001234', 'file' => $image(), 'file_back' => $back()], $headers)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post($base, ['type' => 'citizen_id', 'file' => UploadedFile::fake()->create('cccd.pdf', 100, 'application/pdf'), 'file_back' => $back()], $headers)->assertStatus(422)->assertJsonValidationErrors('file');
        // CCCD phải đủ hai mặt: thiếu mặt sau → từ chối ở ô mặt sau.
        $this->post($base, ['type' => 'citizen_id', 'file' => $image()], $headers)->assertStatus(422)->assertJsonValidationErrors('file_back');
        $this->assertSame(0, $this->partner->legalDocuments()->where('type', 'citizen_id')->count());

        // Đọc được QR → lưu theo QR, bỏ qua số CCCD gửi tay; "Nơi cấp" do người nhập.
        $qr(['cccd' => '092088001234', 'full_name' => 'NGUYỄN VĂN AN', 'dob' => '05/03/1988', 'gender' => 'Nam', 'address' => '12 Lê Lợi, Cần Thơ', 'issued_date' => '10/07/2021', 'source' => 'qr']);
        $this->post("{$base}/scan", ['type' => 'citizen_id', 'file' => $image(), 'file_back' => $back()], $headers)->assertOk()->assertJsonPath('data.fields.cccd_full_name', 'NGUYỄN VĂN AN');
        $response = $this->post($base, ['type' => 'citizen_id', 'cccd_document_number' => '111111111111', 'cccd_issuer' => 'Cục CS QLHC về TTXH', 'file' => $image(), 'file_back' => $back()], $headers)
            ->assertCreated()->assertJsonPath('data.document_number', '092088001234')
            ->assertJsonPath('data.needs_back_side', true)->assertJsonPath('data.file_back.name', 'cccd-sau.jpg');
        $id = $response->json('data.id');
        $this->assertStringContainsString('side=back', $response->json('data.file_back.download_url'));
        $this->get($response->json('data.file_back.download_url'), $headers)->assertOk();

        // Sửa không kèm tệp: ô lấy từ QR không sửa tay được (đọc lại từ tệp đã lưu), Nơi cấp sửa được.
        $this->post("{$base}/{$id}", ['cccd_full_name' => 'TÊN SỬA TAY', 'cccd_issuer' => 'Bộ Công an'], $headers)->assertOk();
        $document = $this->partner->legalDocuments()->findOrFail($id);
        $this->assertSame('NGUYỄN VĂN AN', $document->cccd_full_name);
        $this->assertSame('Bộ Công an', $document->cccd_issuer);

        // Sửa kèm tệp mới không đọc được QR → từ chối, giữ nguyên dữ liệu cũ.
        $qr(null);
        $this->post("{$base}/{$id}", ['file' => $image(900), 'file_back' => $back(910)], $headers)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame('092088001234', $document->fresh()->cccd_document_number);
    }

    private function upload(array $fields)
    {
        return $this->post("/api/public/partner-onboarding/{$this->token}/documents", $fields + [
            'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }
}
