<?php

namespace Tests\Unit;

use App\Services\LegalDocumentScanService;
use App\Support\LegalDocumentFields;
use Tests\TestCase;

// Quét giấy tờ pháp lý: dò giá trị theo NHÃN từ đoạn chữ OCR đã đọc (không gọi OCR thật). Mẫu chữ dưới đây là giả lập theo bố cục thường gặp.
class LegalDocumentScanTest extends TestCase
{
    private function scanner(): LegalDocumentScanService
    {
        return new LegalDocumentScanService();
    }

    public function test_business_license_fields_are_read_from_labels(): void
    {
        $text = <<<'TXT'
        SỞ KẾ HOẠCH VÀ ĐẦU TƯ THÀNH PHỐ CẦN THƠ
        PHÒNG ĐĂNG KÝ KINH DOANH
        GIẤY CHỨNG NHẬN ĐĂNG KÝ DOANH NGHIỆP
        CÔNG TY TRÁCH NHIỆM HỮU HẠN MỘT THÀNH VIÊN
        Mã số doanh nghiệp: 1801234567
        Đăng ký lần đầu: ngày 05 tháng 03 năm 2021
        Đăng ký thay đổi lần thứ: 2, ngày 10 tháng 08 năm 2023
        1. Tên công ty
        Tên công ty viết bằng tiếng Việt: CÔNG TY TNHH NHÀ TRỌ AN BÌNH
        2. Địa chỉ trụ sở chính
        Địa chỉ trụ sở chính: 12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, Thành phố Cần Thơ
        Điện thoại: 0912 345 678
        Ngành, nghề kinh doanh: Dịch vụ lưu trú ngắn ngày (5510)
        4. Người đại diện theo pháp luật của công ty
        * Họ và tên: NGUYỄN VĂN AN
        Chức danh: Giám đốc
        Số giấy tờ pháp lý của cá nhân: 092081001234
        TXT;

        $fields = $this->scanner()->parse('business_license', $text);

        $this->assertSame('1801234567', $fields['document_number']);
        $this->assertSame('2021-03-05', $fields['issued_at']);
        $this->assertStringContainsString('PHÒNG ĐĂNG KÝ KINH DOANH', $fields['issuer']);
        $this->assertSame('12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, Thành phố Cần Thơ', $fields['business_address']);
        $this->assertSame('Dịch vụ lưu trú ngắn ngày (5510)', $fields['business_lines']);
        $this->assertSame('NGUYỄN VĂN AN', $fields['legal_representative']);
        $this->assertSame('092081001234', $fields['representative_id_number']);
        $this->assertSame('0912345678', $fields['phone']);
        $this->assertSame('business_license', $this->scanner()->detectType($text));
    }

    public function test_security_order_fields_are_read_from_labels(): void
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
        Đủ điều kiện về an ninh, trật tự để làm ngành, nghề: Kinh doanh dịch vụ lưu trú
        TXT;

        $result = $this->scanner()->suggest('security_order', $text);
        $fields = $result['fields'];

        $this->assertSame('125/GCN', $fields['document_number']);
        $this->assertSame('2022-06-14', $fields['issued_at']);
        $this->assertStringContainsString('PHÒNG CẢNH SÁT', $fields['issuer']);
        $this->assertSame('NHÀ TRỌ AN BÌNH', $fields['business_name']);
        $this->assertSame('12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, TP Cần Thơ', $fields['business_address']);
        $this->assertSame('TRẦN THỊ BÌNH', $fields['responsible_person']);
        $this->assertSame('092185004321', $fields['responsible_id_number']);
        $this->assertTrue($result['type_matches']);
        $this->assertSame(7, $result['total']);
    }

    public function test_fire_safety_fields_and_stage_are_read(): void
    {
        $text = <<<'TXT'
        CÔNG AN THÀNH PHỐ CẦN THƠ
        PHÒNG CẢNH SÁT PCCC VÀ CNCH
        Số: 48/TD-PCCC
        Cần Thơ, ngày 02 tháng 11 năm 2023
        GIẤY CHỨNG NHẬN THẨM DUYỆT THIẾT KẾ VỀ PHÒNG CHÁY VÀ CHỮA CHÁY
        Chủ đầu tư/chủ phương tiện: CÔNG TY TNHH NHÀ TRỌ AN BÌNH
        Người đại diện: Ông Nguyễn Văn An
        Chức danh: Giám đốc
        Địa điểm xây dựng: 12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, TP Cần Thơ
        TXT;

        $result = $this->scanner()->suggest('fire_safety', $text);
        $fields = $result['fields'];

        $this->assertSame('48/TD-PCCC', $fields['document_number']);
        $this->assertSame('2023-11-02', $fields['issued_at']);
        $this->assertStringContainsString('PHÒNG CẢNH SÁT PCCC', $fields['issuer']);
        $this->assertSame('CÔNG TY TNHH NHÀ TRỌ AN BÌNH', $fields['investor']);
        $this->assertSame('Ông Nguyễn Văn An', $fields['representative']);
        $this->assertSame('Giám đốc', $fields['representative_title']);
        $this->assertSame('12 Lê Lợi, Phường Cái Khế, Quận Ninh Kiều, TP Cần Thơ', $fields['site_address']);
        $this->assertSame('design_approved', $result['fire_safety_stage']['code']);
        $this->assertSame([], $result['warnings']);
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
        $antt = "GIẤY CHỨNG NHẬN ĐỦ ĐIỀU KIỆN VỀ AN NINH, TRẬT TỰ\nSố: 9/GCN";

        $wrong = $this->scanner()->suggest('fire_safety', $antt);
        $this->assertSame('security_order', $wrong['detected_type']);
        $this->assertFalse($wrong['type_matches']);
        $this->assertNotEmpty($wrong['warnings']);

        $empty = $this->scanner()->suggest('business_license', '');
        $this->assertFalse($empty['text_found']);
        $this->assertSame(0, $empty['found']);
        $this->assertSame(8, $empty['total']);
        $this->assertNull($empty['fields']['document_number']);
    }
}
