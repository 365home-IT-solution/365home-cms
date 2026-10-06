<?php

namespace Tests\Feature\Api\Admin;

use App\Filament\Resources\PartnerResource\Pages\EditPartner;
use App\Filament\Resources\PartnerResource\RelationManagers\LegalDocumentsRelationManager;
use App\Models\Partner;
use App\Models\User;
use App\Services\LegalDocumentScanService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Trang quản trị → Đối tác → bảng "Hồ sơ pháp lý": form có bộ ô riêng theo loại, nút "Quét giấy tờ để tự điền" và nút "Xem" cho mọi giấy tờ.
class LegalDocumentsRelationManagerTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $admin = User::create(['fullname' => 'Super Admin Test', 'email' => 'legal-rm-admin@example.test', 'password' => 'secret-secret']);
        $admin->assignRole(config('filament-shield.super_admin.name'));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => 'Homestay Test', 'legal_name' => 'Homestay Test', 'phone' => '0970000301',
            'email' => 'legal-rm@example.test', 'address' => '12 Lê Lợi', 'status' => false, 'verification_status' => 'pending', 'contract_status' => 'draft',
        ]);
    }

    private function manager()
    {
        return Livewire::test(LegalDocumentsRelationManager::class, ['ownerRecord' => $this->partner, 'pageClass' => EditPartner::class]);
    }

    public function test_form_shows_only_the_fields_of_the_selected_type(): void
    {
        $this->manager()->assertSuccessful()
            ->mountTableAction('create')
            ->setTableActionData(['type' => 'fire_safety'])
            ->assertFormFieldIsVisible('pccc_document_number', 'mountedTableActionForm')
            ->assertFormFieldIsVisible('pccc_site_address', 'mountedTableActionForm')
            ->assertFormFieldIsHidden('dkkd_issued_at', 'mountedTableActionForm')
            ->assertFormFieldIsHidden('antt_issuer', 'mountedTableActionForm')
            ->assertFormFieldIsHidden('document_number', 'mountedTableActionForm')
            ->assertFormFieldIsHidden('expires_at', 'mountedTableActionForm')
            ->setTableActionData(['type' => 'tax_registration'])
            ->assertFormFieldIsHidden('pccc_document_number', 'mountedTableActionForm')
            ->assertFormFieldIsVisible('document_number', 'mountedTableActionForm')
            ->assertFormFieldIsVisible('expires_at', 'mountedTableActionForm');
    }

    // CCCD trên trang quản trị: cùng quy tắc với API — ảnh không đọc được mã QR thì không lưu; đọc được thì lưu theo QR.
    public function test_citizen_id_requires_a_readable_qr_code(): void
    {
        config(['cache.default' => 'array']);
        \Illuminate\Support\Facades\Storage::fake('local');
        $qr = fn (?array $data) => $this->mock(\Modules\Payment\App\Services\CccdScannerService::class, fn ($mock) => $mock->shouldReceive('scanQrImage')->andReturn($data));

        $qr(null);
        $this->manager()->mountTableAction('create')
            ->setTableActionData(['type' => 'citizen_id', 'file' => [UploadedFile::fake()->image('cccd.jpg', 800, 500)], 'file_back' => [UploadedFile::fake()->image('cccd-sau.jpg', 810, 500)]])
            ->assertFormFieldIsVisible('file_back', 'mountedTableActionForm')
            ->assertFormFieldIsVisible('cccd_document_number', 'mountedTableActionForm')
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['file']);
        $this->assertSame(0, $this->partner->legalDocuments()->where('type', 'citizen_id')->count());

        $qr(['cccd' => '092088001234', 'full_name' => 'NGUYỄN VĂN AN', 'dob' => '05/03/1988', 'gender' => 'Nam', 'address' => '12 Lê Lợi, Cần Thơ', 'issued_date' => '10/07/2021', 'source' => 'qr']);
        $this->manager()->mountTableAction('create')
            ->setTableActionData(['type' => 'citizen_id', 'cccd_issuer' => 'Bộ Công an', 'file' => [UploadedFile::fake()->image('cccd.jpg', 820, 500)], 'file_back' => [UploadedFile::fake()->image('cccd-sau.jpg', 830, 500)]])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $document = $this->partner->legalDocuments()->where('type', 'citizen_id')->firstOrFail();
        $this->assertSame('092088001234', $document->cccd_document_number);
        $this->assertSame('092088001234', $document->document_number);
        $this->assertSame('1988-03-05', $document->cccd_dob->toDateString());
        $this->assertSame('Bộ Công an', $document->cccd_issuer);
        $this->assertTrue($document->hasMedia('file') && $document->hasMedia('file_back'));
    }

    public function test_scan_button_fills_empty_fields_only(): void
    {
        $this->app->bind(LegalDocumentScanService::class, fn () => new class extends LegalDocumentScanService
        {
            public function isConfigured(): bool
            {
                return true;
            }

            protected function extractText(UploadedFile $file): string
            {
                return (string) file_get_contents(base_path('tests/Fixtures/legal-documents/antt.txt'));
            }
        });

        $this->manager()
            ->mountTableAction('create')
            ->setTableActionData([
                'type' => 'security_order',
                'antt_issuer' => 'Đã nhập tay',
                'file' => [UploadedFile::fake()->create('antt.pdf', 100, 'application/pdf')],
            ])
            ->callFormComponentAction('file', 'scan', formName: 'mountedTableActionForm')
            ->assertTableActionDataSet([
                'antt_document_number' => '31/GCN',
                'antt_responsible_person' => 'Nguyễn Văn An',
                // Ô đã nhập tay không bị ghi đè.
                'antt_issuer' => 'Đã nhập tay',
            ]);
    }

    public function test_view_action_shows_per_type_fields_of_a_pending_document(): void
    {
        $document = $this->partner->legalDocuments()->create([
            'type' => 'business_license', 'status' => 'pending_review', 'dkkd_document_number' => '1800000047', 'dkkd_legal_representative' => 'Nguyễn Văn An',
        ]);

        $this->manager()
            ->assertTableActionVisible('view', $document)
            // Đang chờ duyệt: không sửa được nhưng vẫn xem được đủ các ô riêng.
            ->assertTableActionHidden('edit', $document)
            ->mountTableAction('view', $document)
            ->assertTableActionDataSet(['dkkd_document_number' => '1800000047', 'dkkd_legal_representative' => 'Nguyễn Văn An']);
    }
}
