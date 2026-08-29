<?php

namespace Modules\BladeThemeV1\Services\Zns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use App\Services\ZaloTokenService;
use Modules\Zns\App\Models\ZnsNotification;

class ZaloZnsService
{
    protected $appId;
    protected $appSecret;
    protected $oauthUrl;
    protected $apiUrl;

    public function __construct(protected ZaloTokenService $tokenService)
    {
        $this->appId = Config::get('services.zalo.app_id');
        $this->appSecret = Config::get('services.zalo.app_secret');
        $this->oauthUrl = Config::get('services.zalo.oauth_url');
        $this->apiUrl = Config::get('services.zalo.api_url');
    }

    /**
     * Lấy access token (tự động refresh nếu hết hạn) — dùng chung ZaloTokenService
     * với ZaloOtpService vì cùng 1 Zalo OA, tránh 2 nơi refresh độc lập đụng độ
     * refresh_token (Zalo chỉ cho dùng refresh_token 1 lần).
     */
    protected function getAccessToken()
    {
        return $this->tokenService->getAccessToken();
    }

    /**
     * Gửi ZNS thông báo đặt phòng thành công
     */
    public function sendBookingSuccessNotification($order, $accessCode)
    {
        $templateId = Config::get('services.zalo.templates.booking_success');

        if (!$templateId) {
            throw new \Exception('Zalo template booking_success chưa được cấu hình.');
        }

        // Lấy thông tin chi nhánh
        $branchName = $order->category ? $order->category->name : 'Chi nhánh';
        $firstItem = $order->items->first();

        $templateData = [
            'customer_name' => $order->buyer_name,
            'branch_name' => $branchName,
            'room_name' => $firstItem->name ?? 'Phòng',
            'checkin_date' => $firstItem->checkin_date ? $firstItem->checkin_date->format('d/m/Y H:i') : 'Chưa xác định',
            'checkout_date' => $firstItem->checkout_date ? $firstItem->checkout_date->format('d/m/Y H:i') : 'Chưa xác định',
            'access_code' => $accessCode->code,
            'gate_location' => $accessCode->gate_location ?? '',
            'amount' => number_format($order->amount, 0, ',', '.') . ' VNĐ',
            'order_code' => $order->order_code,
        ];

        return $this->sendZns(
            $order->id,
            $accessCode->id,
            $order->buyer_phone,
            $order->buyer_name,
            $templateId,
            'booking_success',
            $templateData
        );
    }

    /**
     * Gửi ZNS thông báo nhắc nhở (trước khi checkin)
     */
    public function sendBookingReminderNotification($order, $accessCode)
    {
        $templateId = Config::get('services.zalo.templates.booking_reminder');

        if (!$templateId) {
            return ['success' => false, 'error' => 'Template chưa được cấu hình'];
        }

        $firstItem = $order->items->first();

        $templateData = [
            'customer_name' => $order->buyer_name,
            'room_name' => $firstItem->name ?? 'Phòng',
            'checkin_date' => $firstItem->checkin_date ?
                $firstItem->checkin_date->format('d/m/Y H:i') : '',
            'access_code' => $accessCode->code,
        ];

        return $this->sendZns(
            $order->id,
            $accessCode->id,
            $order->buyer_phone,
            $order->buyer_name,
            $templateId,
            'booking_reminder',
            $templateData
        );
    }

    /**
     * Gửi ZNS thông báo hủy đơn
     */
    public function sendBookingCancelledNotification($order)
    {
        $templateId = Config::get('services.zalo.templates.booking_cancelled');

        if (!$templateId) {
            return ['success' => false, 'error' => 'Template chưa được cấu hình'];
        }

        $templateData = [
            'customer_name' => $order->buyer_name,
            'order_code' => $order->order_code,
            'amount' => number_format($order->amount, 0, ',', '.') . ' VNĐ',
            'cancel_reason' => 'Khách hàng hủy đơn',
        ];

        return $this->sendZns(
            $order->id,
            null,
            $order->buyer_phone,
            $order->buyer_name,
            $templateId,
            'booking_cancelled',
            $templateData
        );
    }

    /**
     * Gửi ZNS (Core function)
     */
    public function sendZns(
        $orderId,
        $accessCodeId,
        $phoneNumber,
        $recipientName,
        $templateId,
        $notificationType,
        $templateData
    ) {
        // Tạo record notification
        $notification = ZnsNotification::create([
            'order_id' => $orderId,
            'access_code_id' => $accessCodeId,
            'phone_number' => $phoneNumber,
            'recipient_name' => $recipientName,
            'template_id' => $templateId,
            'template_name' => $notificationType,
            'template_data' => $templateData,
            'status' => 'pending',
            'notification_type' => $notificationType,
        ]);

        try {
            // Lấy access token (tự động refresh nếu cần)
            $accessToken = $this->getAccessToken();

            $formattedPhone = $this->formatPhoneNumber($phoneNumber);

            // Gửi request tới Zalo API
            $response = Http::timeout(30)
                ->withHeaders([
                    'access_token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->apiUrl, [
                    'phone'           => $formattedPhone,
                    'template_id'     => $templateId,
                    'template_data'   => $templateData,
                    'tracking_id'     => (string) $orderId,
                    'appsecret_proof' => hash_hmac('sha256', $accessToken, $this->appSecret),
                ]);

            $result = $response->json();

            // Kiểm tra response
            if ($response->successful() && isset($result['error']) && $result['error'] == 0) {
                $notification->markAsSent(
                    $result['data']['msg_id'] ?? null,
                    $result
                );

                return [
                    'success' => true,
                    'notification_id' => $notification->id,
                    'message_id' => $result['data']['msg_id'] ?? null,
                ];
            } else {
                $errorMessage = $result['message'] ?? 'Unknown error';
                $errorCode = $result['error'] ?? null;

                $notification->markAsFailed($errorMessage, $errorCode, $result);

                return [
                    'success' => false,
                    'notification_id' => $notification->id,
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                ];
            }
        } catch (\Exception $e) {

            $notification->markAsFailed($e->getMessage());

            return [
                'success' => false,
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format số điện thoại cho Zalo (84xxxxxxxxx)
     */
    protected function formatPhoneNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (substr($phone, 0, 3) === '+84') {
            $phone = '84' . substr($phone, 3);
        }

        if (substr($phone, 0, 1) === '0') {
            $phone = '84' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Retry failed notifications
     */
    public function retryFailedNotifications($limit = 10)
    {
        $failed = ZnsNotification::needsRetry()
            ->limit($limit)
            ->get();

        $results = [
            'total' => $failed->count(),
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ($failed as $notification) {
            if (!$notification->canRetry()) {
                continue;
            }

            $notification->resetForRetry();

            $result = $this->sendZns(
                $notification->order_id,
                $notification->access_code_id,
                $notification->phone_number,
                $notification->recipient_name,
                $notification->template_id,
                $notification->notification_type,
                $notification->template_data
            );

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $results['details'][] = [
                'notification_id' => $notification->id,
                'result' => $result,
            ];
        }

        return $results;
    }

    /**
     * Kiểm tra trạng thái kết nối Zalo
     */
    public function checkConnection()
    {
        try {
            $this->getAccessToken();

            return [
                'success' => true,
                'message' => 'Kết nối Zalo thành công',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Không thể kết nối Zalo: ' . $e->getMessage(),
            ];
        }
    }
}
