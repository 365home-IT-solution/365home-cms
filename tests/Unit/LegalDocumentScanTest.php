<?php

namespace Tests\Unit;

use App\Services\LegalDocumentScanService;
use App\Support\LegalDocumentFields;
use Tests\TestCase;

// Quét giấy tờ pháp lý: dò giá trị từ đoạn chữ OCR đã đọc (không gọi OCR thật).
// tests/Fixtures/legal-documents/*.txt = chữ OCR.space trả về cho 3 FILE MẪU THẬT (ĐKKD Cần Thơ 2025, GCN ANTT phường 2026, GCN thẩm duyệt PCCC 2019),
// giữ nguyên lỗi OCR (rụng dấu, tách dòng, dính quốc hiệu); thông tin cá nhân đã được thay bằng dữ liệu giả.
class LegalDocumentScanTest extends TestCase
{
    private function scan(string $type, string $fixture): array
    {
        return (new LegalDocumentScanService())->suggest($type, (string) file_get_contents(base_path("tests/Fixtures/legal-documents/{$fixture}.txt")));
    }

    public function test_business_license_sample(): void
    {
        $result = $this->scan('business_license', 'dkkd');

        $this->assertSame([
            'dkkd_document_number'          => '1800000047',
            // "Đăng ký lần đầu", không lấy ngày đăng ký thay đổi.
            'dkkd_issued_at'                => '2021-09-28',
            // Khối tiêu đề, đơn vị cấp dưới đứng trước; OCR đọc "THÀNH PHÔ CÂN THƠ" được sửa dấu.
            'dkkd_issuer'                   => 'PHÒNG ĐĂNG KÝ KINH DOANH, SỞ TÀI CHÍNH, THÀNH PHỐ CẦN THƠ',
            // Mục "2. Địa chỉ trụ sở chính": giá trị ở 2 dòng dưới tiêu đề mục.
            'dkkd_business_address'         => 'Số 252-254 Đường Xuân Thủy, KDC Cái Sơn Hàng Bàng, Phường An Bình, Thành phố Cần Thơ, Việt Nam',
            // Giấy mẫu không in ngành nghề → để trống.
            'dkkd_business_lines'           => null,
            // "Họ, chữ đệm và tên" trong mục người đại diện theo pháp luật.
            'dkkd_legal_representative'     => 'NGUYỄN VĂN AN',
            'dkkd_representative_id_number' => '092088000001',
            'dkkd_phone'                    => '0900000188',
        ], $result['fields']);
        $this->assertTrue($result['type_matches']);
        $this->assertSame(7, $result['found']);
        $this->assertStringContainsString('Ngành, nghề kinh doanh', implode(' ', $result['warnings']));
    }

    public function test_security_order_sample(): void
    {
        $result = $this->scan('security_order', 'antt');

        $this->assertSame([
            'antt_document_number'       => '31/GCN',
            // Dòng địa danh + ngày cuối giấy. KHÔNG lấy "cấp ngày 06 tháng 11 năm 2025" (ngày của ĐKKD) hay ngày cấp CCCD / ngày sinh trong thân giấy.
            'antt_issued_at'             => '2026-06-10',
            'antt_issuer'                => 'CÔNG AN PHƯỜNG AN BÌNH, THÀNH PHỐ CẦN THƠ',
            'antt_business_name'         => 'Công ty TNHH truyền thông và dịch vụ vận tải CẦN THƠ EXPRESS',
            'antt_business_address'      => 'Số 252-254 đường Xuân Thủy, KDC Cái Sơn Hàng Bàng, Phường An Bình, Thành phố Cần Thơ',
            'antt_responsible_person'    => 'Nguyễn Văn An',
            'antt_responsible_id_number' => '092088000001',
        ], $result['fields']);
        $this->assertTrue($result['type_matches']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_security_order_issue_date_is_left_empty_when_only_quoted_dates_exist(): void
    {
        // Bỏ dòng ngày ký cuối giấy: chỉ còn các ngày được dẫn lại trong thân giấy → ngày cấp phải để trống, không lấy nhầm.
        $text = preg_replace('/^.*10 tháng 06 năm 2026.*$/mu', '', (string) file_get_contents(base_path('tests/Fixtures/legal-documents/antt.txt')));
        $fields = (new LegalDocumentScanService())->parse('security_order', $text);

        $this->assertNull($fields['antt_issued_at']);
        $this->assertSame('31/GCN', $fields['antt_document_number']);
    }

    public function test_fire_safety_sample(): void
    {
        $result = $this->scan('fire_safety', 'pccc');
        $fields = $result['fields'];

        // OCR tách "Số:" và "82 TD-PCCC" thành 2 dòng, rụng dấu "/" → ghép lại.
        $this->assertSame('82/TD-PCCC', $fields['pccc_document_number']);
        $this->assertSame('design_approved', $result['fire_safety_stage']['code']);
        // Dòng "Lâm Đồng, ngày 16 tháng 4 năm 2019" (không lấy 31/7/2014, 16/12/2014 của các căn cứ pháp lý).
        $this->assertSame('2019-04-16', $fields['pccc_issued_at']);
        // Tên cơ quan/tỉnh được sửa dấu; quốc hiệu dính cùng dòng bị cắt bỏ.
        $this->assertSame('PHÒNG CẢNH SÁT PCCC & CNCH, CÔNG AN TỈNH LÂM ĐỒNG', $fields['pccc_issuer']);
        $this->assertSame('Tiều khu 156, lố 60 Đặng Thái Thân, P. 3, Đà Lạt, Lâm Đồng', $fields['pccc_site_address']);
        // Chữ viết tay: giữ đúng những gì OCR đọc được (người nhập kiểm tra lại).
        $this->assertSame('Công ty TNHH Lậm Phần', $fields['pccc_investor']);
        $this->assertSame('Lê Ich Phần', $fields['pccc_representative']);
        $this->assertSame('Giám độc', $fields['pccc_representative_title']);
        $this->assertTrue($result['type_matches']);
    }

    public function test_older_label_layout_is_still_read(): void
    {
        $text = <<<'TXT'
        CÔNG AN THÀNH PHỐ CẦN THƠ
        PHÒNG CẢNH SÁT QLHC VỀ TTXH
        Số: 125/GCN
        Cần Thơ, ngày 14 tháng 6 năm 2022
        GIẤY CHỨNG NHẬN ĐỦ ĐIỀU KIỆN VỀ AN NINH, TRẬT TỰ
        Tên cơ sở kinh doanh: NHÀ TRỌ AN BÌNH
        Địa chỉ: 12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, TP Cần Thơ
        Người chịu trách nhiệm về an ninh, trật tự của cơ sở kinh doanh:
        Họ và tên: TRẦN THỊ BÌNH
        Số CCCD: 092185004321
        TXT;

        $fields = (new LegalDocumentScanService())->parse('security_order', $text);

        $this->assertSame('125/GCN', $fields['antt_document_number']);
        $this->assertSame('2022-06-14', $fields['antt_issued_at']);
        $this->assertSame('PHÒNG CẢNH SÁT QLHC VỀ TTXH, CÔNG AN THÀNH PHỐ CẦN THƠ', $fields['antt_issuer']);
        $this->assertSame('NHÀ TRỌ AN BÌNH', $fields['antt_business_name']);
        $this->assertSame('12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, TP Cần Thơ', $fields['antt_business_address']);
        $this->assertSame('TRẦN THỊ BÌNH', $fields['antt_responsible_person']);
        $this->assertSame('092185004321', $fields['antt_responsible_id_number']);
    }

    public function test_fire_safety_stage_follows_the_document_number(): void
    {
        $this->assertSame('design_approved', LegalDocumentFields::fireSafetyStage('48/TD-PCCC')['code']);
        $this->assertSame('Thẩm duyệt: Chưa hoạt động', LegalDocumentFields::fireSafetyStage('48/td-pccc')['label']);
        $this->assertSame('accepted', LegalDocumentFields::fireSafetyStage('17/NT-PCCC')['code']);
        $this->assertSame('accepted', LegalDocumentFields::fireSafetyStage('05/BB-KT')['code']);
        $this->assertSame('Đã nghiệm thu: Chuẩn bị hoạt động', LegalDocumentFields::fireSafetyStage('22/GXN-PCCC')['label']);
        $this->assertNull(LegalDocumentFields::fireSafetyStage('123/2024'));
        $this->assertNull(LegalDocumentFields::fireSafetyStage(null));
    }

    public function test_mismatched_or_unreadable_file_returns_warnings_not_values(): void
    {
        $wrong = $this->scan('fire_safety', 'antt');
        $this->assertSame('security_order', $wrong['detected_type']);
        $this->assertFalse($wrong['type_matches']);
        $this->assertStringContainsString('kiểm tra lại loại giấy tờ', $wrong['warnings'][0]);

        $empty = (new LegalDocumentScanService())->suggest('business_license', '');
        $this->assertFalse($empty['text_found']);
        $this->assertSame(0, $empty['found']);
        $this->assertSame(8, $empty['total']);
        $this->assertNull($empty['fields']['dkkd_document_number']);
    }
}
