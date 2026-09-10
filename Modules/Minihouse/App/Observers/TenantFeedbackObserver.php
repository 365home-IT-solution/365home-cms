<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\TenantFeedback;
use Modules\Minihouse\App\Services\PortalNotificationService;

// Báo lại cho khách thuê trong Portal khi chủ nhà/nhân viên đánh dấu "Đã xử lý" phản hồi của họ —
// CHỈ áp dụng cho phản hồi gửi qua Portal (có tenant_id); phản hồi công khai ẩn danh (tenant_id NULL,
// xem TenantFeedbackController) không có tài khoản nào để báo lại.
class TenantFeedbackObserver
{
    public function updated(TenantFeedback $feedback): void
    {
        if (! $feedback->tenant_id) {
            return;
        }

        if (! $feedback->wasChanged('is_reviewed') || ! $feedback->is_reviewed) {
            return;
        }

        PortalNotificationService::notify(
            $feedback->tenant,
            PortalNotification::TYPE_FEEDBACK_REPLY,
            'Phản hồi của bạn đã được xử lý',
            $feedback->staff_note ?: 'Cảm ơn bạn đã gửi phản hồi — chủ nhà đã xem và xử lý.',
        );
    }
}
