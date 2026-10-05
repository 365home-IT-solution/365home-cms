<?php

namespace Tests\Unit;

use App\Support\CompanionCccdKeys;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// CCCD người đi cùng: web gửi guests[0]..., app gửi guests[2]... cho cùng người đi cùng đầu tiên — server phải nhận cả hai.
class CompanionCccdKeysTest extends TestCase
{
    private function request(array $guestKeys): Request
    {
        $files = [];
        foreach ($guestKeys as $key) {
            $files['guests'][$key] = ['front' => UploadedFile::fake()->image('f.jpg'), 'back' => UploadedFile::fake()->image('b.jpg')];
        }

        return Request::create('/api/guest/bookings', 'POST', [], [], $files);
    }

    public function test_position_based_keys_from_web_keep_offset_zero(): void
    {
        $this->assertSame(0, CompanionCccdKeys::offset($this->request([0])));
        $this->assertSame(0, CompanionCccdKeys::offset($this->request([0, 1, 2])));
    }

    public function test_guest_index_keys_from_the_app_shift_by_two(): void
    {
        // Đúng trường hợp trong log production: file_field_paths = guests.2.front, guests.2.back.
        $request = $this->request([2]);

        $this->assertSame(2, CompanionCccdKeys::offset($request));
        $this->assertTrue($request->hasFile('guests.' . (0 + CompanionCccdKeys::offset($request)) . '.front'));
        $this->assertSame(2, CompanionCccdKeys::offset($this->request([2, 3, 4])));
    }

    public function test_one_based_keys_and_missing_files(): void
    {
        $this->assertSame(1, CompanionCccdKeys::offset($this->request([1, 2])));
        $this->assertSame(0, CompanionCccdKeys::offset($this->request([])));
    }

    public function test_offset_is_measured_from_the_first_companion_that_must_upload(): void
    {
        // Khách đã đăng nhập, 2 người đi cùng đầu đã có trong hồ sơ → chỉ người thứ 3 (vị trí 2) phải upload.
        $this->assertSame(0, CompanionCccdKeys::offset($this->request([2]), 2));  // web: guests[2] = vị trí 2
        $this->assertSame(2, CompanionCccdKeys::offset($this->request([4]), 2));  // app: guests[4] = khách thứ 4
        // Khoá lệch bất thường → giữ cách cũ (theo vị trí).
        $this->assertSame(0, CompanionCccdKeys::offset($this->request([7])));
    }
}
