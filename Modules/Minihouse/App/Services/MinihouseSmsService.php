<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\SmsNotification;
use Modules\Minihouse\App\Models\SmsSetting;
use Modules\Minihouse\App\Support\ReminderRecipientResolver;

// Gửi SMS Brandname (kênh "chăm sóc khách hàng", KHÔNG phải quảng cáo) cho khách thuê MiniHouse qua
// eSMS.vn — dùng tài khoản SmsSetting RIÊNG của MiniHouse, tách biệt hoàn toàn khỏi mọi cấu hình SMS
// khác của Home nếu có. KHÁC Zalo ZNS (MinihouseZaloService): SMS không cần mẫu được duyệt trước
// theo từng loại nhắc việc — nội dung tự soạn trực tiếp, chỉ cần Brandname đã đăng ký + được eSMS
// duyệt là gửi được ngay.
//
// API dùng: eSMS "Gửi tin chăm sóc khách hàng" (SmsType=2), xem
// https://developers.esms.vn/esms-api/cac-api-khac/gui-tin-cham-soc-khach-hang-dang-get.
class MinihouseSmsService
{
    private const ENDPOINT = 'https://rest.esms.vn/MainService.svc/json/SendMultipleMessage_V4_get';

    // SmsType=2 — "Chăm sóc khách hàng" (thông tin/dịch vụ, KHÁC quảng cáo cần đăng ký nội dung mẫu
    // riêng và KHÁC OTP) — đúng loại cho nhắc đóng tiền/nhắc việc, xem tài liệu eSMS.
    private const SMS_TYPE_CSKH = 2;

    public function isConfigured(): bool
    {
        return SmsSetting::current()->isConfigured();
    }

    // Cùng nguyên tắc MinihouseZaloService::sendReminderNotification() — luôn trả về mảng có
    // 'success' => bool, 'skipped' => true kèm 'reason' tiếng Việt khi bị bỏ qua (chưa cấu hình/
    // không có khách để gửi), để hiển thị được cho nhân viên khi bấm nút "Gửi SMS" thủ công.
    public function sendReminderNotification(Reminder $reminder): array
    {
        $settings = SmsSetting::current();

        if (! $settings->isConfigured()) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Chưa cấu hình tài khoản SMS (ApiKey/SecretKey/Brandname) — vào mục "Cấu hình SMS".'];
        }

        $tenant = ReminderRecipientResolver::resolve($reminder);

        if (! $tenant) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Không xác định được khách thuê để gửi — nhắc việc này chưa gắn hợp đồng, hoặc phòng đang trống.'];
        }

        if (blank($tenant->phone)) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Khách thuê "' . $tenant->fullname . '" chưa có số điện thoại trong hồ sơ.'];
        }

        $content = $this->buildContent($reminder);

        return $this->sendSms($reminder->id, $tenant->phone, $tenant->fullname, $content, $settings);
    }

    // Gửi SMS RỜI, không gắn Reminder nào — dùng cho OTP đăng nhập Portal khách thuê (xem
    // TenantOtpService) và bất kỳ nhu cầu gửi SMS tuỳ ý nào khác sau này. Cùng nguyên tắc trả về
    // mảng 'success'/'reason' như sendReminderNotification().
    public function sendRaw(string $phone, string $content, ?string $recipientName = null): array
    {
        $settings = SmsSetting::current();

        if (! $settings->isConfigured()) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Chưa cấu hình tài khoản SMS (ApiKey/SecretKey/Brandname) — vào mục "Cấu hình SMS".'];
        }

        if (blank($phone)) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Không có số điện thoại để gửi.'];
        }

        return $this->sendSms(null, $phone, $recipientName, $content, $settings);
    }

    // SMS không bị ràng buộc tham số cố định như ZNS (xem MinihouseZaloService::buildTemplateData) —
    // tự soạn nội dung đầy đủ trực tiếp. Nhắc đóng tiền có gắn hoá đơn cụ thể thì kèm luôn số tiền
    // còn nợ + hạn nhắc, các loại khác dùng content/title sẵn có của Reminder.
    private function buildContent(Reminder $reminder): string
    {
        if ($reminder->type === Reminder::TYPE_PAYMENT && $reminder->invoice_id && $reminder->invoice) {
            $invoice = $reminder->invoice;
            $roomCode = $reminder->room?->code ?? ReminderRecipientResolver::resolveContract($reminder)?->room?->code ?? '';

            return sprintf(
                'Nhắc đóng tiền: Hoá đơn tháng %s phòng %s còn nợ %sđ. Hạn: %s. Vui lòng thanh toán đúng hạn, xin cảm ơn.',
                $invoice->month?->format('m/Y') ?? '',
                $roomCode,
                number_format(InvoiceContentRenderer::totalOwed($invoice), 0, '.', '.'),
                $reminder->remind_date?->format('d/m/Y') ?? ''
            );
        }

        return $reminder->content ?: $reminder->title;
    }

    private function sendSms(?int $reminderId, string $phone, ?string $recipientName, string $content, SmsSetting $settings): array
    {
        $formattedPhone = $this->formatPhoneNumber($phone);

        try {
            $response = Http::timeout(30)->get(self::ENDPOINT, [
                'Phone'      => $formattedPhone,
                'Content'    => $content,
                'ApiKey'     => $settings->api_key,
                'SecretKey'  => $settings->secret_key,
                'Brandname'  => $settings->brandname,
                'SmsType'    => self::SMS_TYPE_CSKH,
                // PHẢI khớp với nội dung thật gửi đi: buildContent() viết tiếng Việt CÓ DẤU (payment
                // reminder tự soạn ở trên, các loại khác lấy nguyên Reminder.content/title do nhân
                // viên tự gõ — cũng thường có dấu). Set IsUnicode=0 trong khi nội dung có dấu sẽ
                // khiến SMS hiển thị lỗi font (mojibake) cho khách — ưu tiên đọc đúng hơn tối ưu chi
                // phí (mỗi đoạn SMS có dấu chỉ ~70 ký tự so với ~160 ký tự không dấu, tốn thêm 1-2
                // đoạn là chấp nhận được).
                'IsUnicode'  => 1,
            ]);

            $result = $response->json();
            $codeResult = $result['CodeResult'] ?? null;

            // eSMS trả HTTP 200 ngay cả khi CodeResult báo lỗi (sai key, brandname không hợp lệ...)
            // — PHẢI kiểm tra CodeResult, không chỉ dựa vào response()->successful().
            if (! $response->successful() || $codeResult !== '100') {
                $errorMessage = $this->describeErrorCode($codeResult) . ' (CodeResult=' . ($codeResult ?? 'null') . ')';

                Log::warning('MinihouseSmsService: gửi SMS thất bại', [
                    'reminder_id' => $reminderId,
                    'response'    => $result,
                ]);

                SmsNotification::create([
                    'reminder_id'    => $reminderId,
                    'phone_number'   => $formattedPhone,
                    'recipient_name' => $recipientName,
                    'content'        => $content,
                    'status'         => SmsNotification::STATUS_FAILED,
                    'error_message'  => $errorMessage,
                ]);

                return ['success' => false, 'error' => $errorMessage];
            }

            SmsNotification::create([
                'reminder_id'    => $reminderId,
                'phone_number'   => $formattedPhone,
                'recipient_name' => $recipientName,
                'content'        => $content,
                'status'         => SmsNotification::STATUS_SENT,
                'sms_id'         => $result['SMSID'] ?? null,
                'sent_at'        => now(),
            ]);

            return ['success' => true, 'sms_id' => $result['SMSID'] ?? null];
        } catch (\Throwable $e) {
            Log::warning('MinihouseSmsService: gửi SMS thất bại (exception)', [
                'reminder_id' => $reminderId,
                'error'       => $e->getMessage(),
            ]);

            SmsNotification::create([
                'reminder_id'    => $reminderId,
                'phone_number'   => $formattedPhone,
                'recipient_name' => $recipientName,
                'content'        => $content,
                'status'         => SmsNotification::STATUS_FAILED,
                'error_message'  => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // Bảng mã CodeResult chính của eSMS (tài liệu chính thức) — dịch sẵn ra tiếng Việt để nhân viên
    // đọc hiểu ngay khi bấm "Gửi SMS" thủ công, không cần tra tài liệu.
    private function describeErrorCode(?string $code): string
    {
        return match ($code) {
            '101'   => 'Sai ApiKey/SecretKey — kiểm tra lại ở mục "Cấu hình SMS".',
            '104'   => 'Brandname không hợp lệ hoặc chưa được duyệt.',
            '118'   => 'Số điện thoại không hợp lệ.',
            '119'   => 'Tài khoản eSMS hết tiền hoặc hết hạn sử dụng Brandname.',
            '124'   => 'Trùng mã yêu cầu (RequestId).',
            '99'    => 'Yêu cầu không hợp lệ (thiếu tham số).',
            default => 'Gửi SMS thất bại, không rõ nguyên nhân.',
        };
    }

    // eSMS yêu cầu số điện thoại Việt Nam bắt đầu bằng "0" (không phải mã quốc tế "84" như PayOS/
    // MoMo) — chỉ cần loại bỏ ký tự không phải số, KHÔNG đổi tiền tố như MinihouseZaloService/
    // InvoicePayOsService đang làm cho các API khác.
    private function formatPhoneNumber(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? $phone;
    }
}
