<?php

namespace Tests\Unit;

use App\Support\CccdIdentity;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CccdIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function qr(array $overrides = []): array
    {
        return array_merge([
            'cccd'        => '087204016918', // 087 Đồng Tháp, 2 = nam thế kỷ 21, 04 = năm 2004
            'full_name'   => 'Nguyễn Minh Triết',
            'dob'         => '06/08/2004',
            'gender'      => 'Nam',
            'issued_date' => '17/08/2021',
            'source'      => 'qr',
        ], $overrides);
    }

    public function test_valid_qr_data_passes(): void
    {
        $this->assertNull(CccdIdentity::validate($this->qr()));
        $this->assertSame('Đồng Tháp', CccdIdentity::birthProvince('087204016918'));
        $this->assertNull(CccdIdentity::validate($this->qr(['cccd' => '092190001234', 'dob' => '15/03/1990', 'gender' => 'Nữ'])));
    }

    public function test_rejects_non_qr_source_unless_allowed(): void
    {
        $this->assertNotNull(CccdIdentity::validate($this->qr(['source' => 'ocr'])));
        $this->assertNull(CccdIdentity::validate($this->qr(['source' => 'ocr']), requireQr: false));
        $this->assertNotNull(CccdIdentity::validate(null));
    }

    public function test_first_six_digits_must_match(): void
    {
        $this->assertStringContainsString('3 số đầu', CccdIdentity::validate($this->qr(['cccd' => '088204016918'])));
        $this->assertStringContainsString('thế kỷ', CccdIdentity::validate($this->qr(['cccd' => '087004016918'])));
        $this->assertStringContainsString('5–6', CccdIdentity::validate($this->qr(['cccd' => '087205016918'])));
        $this->assertStringContainsString('giới tính', CccdIdentity::validate($this->qr(['cccd' => '087304016918'])));
        $this->assertNotNull(CccdIdentity::validate($this->qr(['cccd' => '08720401691'])), 'thiếu số');
        $this->assertNotNull(CccdIdentity::validate($this->qr(['dob' => '31/02/2004'])), 'ngày không tồn tại');
        $this->assertNotNull(CccdIdentity::validate($this->qr(['full_name' => ' '])));
        $this->assertNotNull(CccdIdentity::validate($this->qr(['issued_date' => '01/01/2000'])), 'cấp trước ngày sinh');
    }

    public function test_anyone_under_16_is_rejected(): void
    {
        Carbon::setTestNow('2026-09-28');

        // Đúng 16 tuổi hôm nay → được; kém 1 ngày → lỗi (dưới 16 là < 16).
        $this->assertNull(CccdIdentity::ageError($this->qr(['dob' => '28/09/2010']), isBooker: true));
        $this->assertNotNull(CccdIdentity::ageError($this->qr(['dob' => '29/09/2010']), isBooker: true));
        $this->assertNotNull(CccdIdentity::ageError($this->qr(['dob' => '29/09/2010']), isBooker: false));
        $this->assertNotNull(CccdIdentity::ageError($this->qr(['dob' => 'bad']), isBooker: true));

        // Người đặt 15 tuổi (sinh 01/01/2011) → lỗi, dù chỉ còn vài tháng là đủ 16.
        $booker15 = CccdIdentity::ageError($this->qr(['dob' => '01/01/2011']), isBooker: true);
        $this->assertStringContainsString('Người đặt phòng chưa đủ 16 tuổi', (string) $booker15);

        // Tuổi tính theo ngày nhận phòng.
        $child = $this->qr(['dob' => '01/01/2011']);
        $this->assertNotNull(CccdIdentity::ageError($child, isBooker: false, on: Carbon::parse('2026-12-31')));
        $this->assertNull(CccdIdentity::ageError($child, isBooker: false, on: Carbon::parse('2027-01-01')));
    }

    public function test_same_person_detection(): void
    {
        $a = $this->qr();

        $this->assertTrue(CccdIdentity::samePerson($a, $this->qr(['full_name' => 'Người Khác'])), 'trùng số');
        $this->assertTrue(CccdIdentity::samePerson($a, $this->qr(['cccd' => '092204000001', 'full_name' => 'nguyen  minh triet'])), 'trùng tên + ngày sinh');
        $this->assertFalse(CccdIdentity::samePerson($a, $this->qr(['cccd' => '092204000001', 'dob' => '07/08/2004'])), 'cùng tên khác ngày sinh');
        $this->assertFalse(CccdIdentity::samePerson($a, $this->qr(['cccd' => '092204000001', 'full_name' => 'Trần Văn B'])));
    }
}
