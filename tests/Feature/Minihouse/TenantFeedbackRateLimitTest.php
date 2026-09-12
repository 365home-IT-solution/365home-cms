<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Audit phát hiện: POST /minihouse/feedback là endpoint công khai (không đăng nhập, không captcha)
// nhưng KHÔNG giới hạn tần suất — 1 script có thể spam hàng loạt đánh giá giả mạo tên/SĐT khách thuê
// thật cho bất kỳ phòng nào. Test này khoá lại throttle:5,1 vừa thêm ở Modules/Minihouse/Routes/web.php.
class TenantFeedbackRateLimitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_feedback_submissions_are_rate_limited_per_ip(): void
    {
        $payload = ['rating' => 5, 'content' => 'Tốt'];

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post('/minihouse/feedback', $payload);
            $response->assertRedirect();
            $response->assertSessionHas('feedback_sent');
        }

        // Lần thứ 6 trong cùng 1 phút phải bị chặn (429), không được tạo thêm bản ghi.
        $response = $this->post('/minihouse/feedback', $payload);
        $response->assertStatus(429);
    }
}
