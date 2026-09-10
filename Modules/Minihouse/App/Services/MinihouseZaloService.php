<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\ZaloNotification;
use Modules\Minihouse\App\Models\ZaloSetting;
use Modules\Minihouse\App\Support\ReminderRecipientResolver;

// Gửi ZNS (Zalo Notification Service) cho khách thuê MiniHouse — dùng Zalo OA RIÊNG (xem ZaloSetting,
// MinihouseZaloTokenService), KHÔNG đụng gì tới ZaloZnsService/ZaloSettings của Home. Mỗi loại nhắc
// việc (Reminder::TYPE_PAYMENT/TYPE_CONTRACT/TYPE_MAINTENANCE) cần 1 mẫu ZNS ĐÃ ĐƯỢC ZALO DUYỆT
// riêng (template_payment_reminder/template_contract_expiry/template_maintenance ở ZaloSetting) —
// chưa duyệt/chưa điền Template ID thì bỏ qua, không gửi (không throw ra ngoài, xem
// ReminderNotificationService::notify() gọi hàm này ở luồng cron, không được để 1 lỗi Zalo làm
// hỏng cả việc gửi thông báo chuông nội bộ đi kèm).
class MinihouseZaloService
{
    public function __construct(private readonly MinihouseZaloTokenService $tokenService)
    {
    }

    public function isConfigured(): bool
    {
        return ZaloSetting::current()->isConfigured();
    }

    // Xác định NGƯỜI NHẬN đúng nghiệp vụ cho từng loại nhắc việc, rồi gửi. Luôn trả về mảng có
    // 'success' => bool — khi bị BỎ QUA (chưa cấu hình/chưa có mẫu/không có khách để gửi) thêm
    // 'skipped' => true kèm 'reason' tiếng Việt để hiển thị được cho nhân viên khi bấm nút "Gửi
    // Zalo" thủ công (xem ReminderTable/EditReminder) — luồng cron tự động (notify()) chỉ quan tâm
    // có throw hay không, không đọc field này.
    public function sendReminderNotification(Reminder $reminder): array
    {
        $settings = ZaloSetting::current();

        if (! $settings->isConfigured()) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Chưa cấu hình tài khoản Zalo OA (App ID/App Secret/Refresh Token) — vào mục "Cấu hình Zalo".'];
        }

        $templateId = $settings->templateFor($reminder->type);

        if (! $templateId) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Chưa có mẫu ZNS cho loại nhắc việc này — vào mục "Cấu hình Zalo" điền Template ID.'];
        }

        $tenant = ReminderRecipientResolver::resolve($reminder);

        if (! $tenant) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Không xác định được khách thuê để gửi — nhắc việc này chưa gắn hợp đồng, hoặc phòng đang trống.'];
        }

        if (blank($tenant->phone)) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Khách thuê "' . $tenant->fullname . '" chưa có số điện thoại trong hồ sơ.'];
        }

        $templateData = $this->buildTemplateData($reminder, $tenant);

        return $this->sendZns($reminder->id, $tenant->phone, $tenant->fullname, $templateId, $templateData);
    }

    // Gửi mã OTP đăng nhập Portal khách thuê qua ZNS — dùng RIÊNG 1 mẫu "template_otp" (khác 3 mẫu
    // nhắc việc), vì Zalo phân loại mẫu OTP thành 1 NHÓM DUYỆT RIÊNG (chỉ được chứa đúng mã xác thực
    // + thời hạn, không được kèm nội dung quảng cáo/thông tin khác — nếu ghép chung mẫu nhắc việc sẽ
    // bị Zalo từ chối duyệt). Xem TenantOtpService — không gắn Reminder nào (reminderId=null).
    public function sendOtp(string $phone, string $code, ?string $recipientName = null): array
    {
        $settings = ZaloSetting::current();

        if (! $settings->isConfigured()) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Chưa cấu hình tài khoản Zalo OA (App ID/App Secret/Refresh Token) — vào mục "Cấu hình Zalo".'];
        }

        if (! $settings->template_otp) {
            return ['success' => false, 'skipped' => true, 'reason' => 'Chưa có mẫu ZNS cho OTP — vào mục "Cấu hình Zalo" điền Template ID (mẫu loại OTP đã được Zalo duyệt).'];
        }

        // Quy ước tham số của Zalo cho mẫu loại OTP: tên biến CỐ ĐỊNH là "otp" (Zalo yêu cầu đúng
        // tên này khi tạo mẫu OTP trên ZBS, khác các mẫu thường được tự đặt tên biến tuỳ ý).
        return $this->sendZns(null, $phone, $recipientName, $settings->template_otp, ['otp' => $code]);
    }

    // Nhắc đóng tiền có gắn sẵn 1 Hoá đơn cụ thể (Reminder::invoice_id, chọn ở ReminderForm) thì gửi
    // ĐẦY ĐỦ chi tiết tiền phòng/điện/nước/nợ cũ giống hệt phiếu in (xem InvoiceContentRenderer) —
    // TÊN CÁC KHOÁ BÊN DƯỚI phải khớp CHÍNH XÁC tên biến (param) đã khai báo khi tạo mẫu trên ZBS,
    // đổi tên ở 1 trong 2 nơi (mẫu ZBS hoặc đây) mà quên đổi nơi còn lại thì Zalo sẽ từ chối gửi.
    // Các loại khác (hết hạn hợp đồng/bảo trì) hoặc "Nhắc đóng tiền" không gắn hoá đơn nào thì chỉ
    // gửi bộ biến chung (customer_name/title/content/remind_date/room_code).
    private function buildTemplateData(Reminder $reminder, Tenant $tenant): array
    {
        $common = [
            'customer_name' => $tenant->fullname,
            'title'         => $reminder->title,
            'content'       => $reminder->content ?: '',
            'remind_date'   => $reminder->remind_date?->format('d/m/Y') ?? '',
            // sanitizeCode() — tham số khai kiểu "Mã số" trên ZBS bị Zalo TỪ CHỐI GỬI THẬT nếu chứa
            // dấu gạch ngang (lỗi "invoice_code has invalid format", xem buildInvoiceTemplateData())
            // — mã phòng "A-01" cũng cùng kiểu "Mã số" nên phải làm sạch y hệt, dù trước đó gửi thử
            // với invoice_code mới lộ lỗi. Chỉ đổi giá trị GỬI ĐI cho Zalo, không đổi Room.code hiển
            // thị trong hệ thống.
            'room_code'     => $this->sanitizeCode($reminder->room?->code ?? ReminderRecipientResolver::resolveContract($reminder)?->room?->code ?? ''),
        ];

        if ($reminder->type !== Reminder::TYPE_PAYMENT || ! $reminder->invoice_id) {
            return $common;
        }

        $invoice = $reminder->invoice;

        if (! $invoice) {
            return $common;
        }

        return array_merge($common, $this->buildInvoiceTemplateData($invoice));
    }

    // LƯU Ý CẤU TRÚC "Bảng" của ZBS: mỗi hàng = 1 TIÊU ĐỀ CỐ ĐỊNH (đặt sẵn lúc tạo mẫu, không đổi
    // được khi gửi) + 1 BIẾN RIÊNG cho phần "Nội dung" — KHÔNG PHẢI 1 mảng dữ liệu lặp lại nhiều
    // dòng như 1 bảng động thông thường. Vì vậy ở đây phải gửi từng khoản là 1 biến PHẲNG riêng biệt
    // (room_fee/electric_fee/water_fee/other_fee), khớp đúng 4 hàng đã khai báo cố định trên mẫu ZBS
    // (Tiêu đề: "Tiền phòng"/"Tiền điện"/"Tiền nước"/"Phụ thu khác") — KHÔNG gửi mảng 'table'.
    //
    // KHÔNG gửi thông tin ngân hàng nào trong tin này nữa (yêu cầu 2026-09-08, sau khi mẫu tuỳ chỉnh
    // đầu tiên bị Zalo từ chối lỗi CT_27 "Thông báo yêu cầu thanh toán đến 1 STK nhất định vui lòng
    // dùng ZBS Yêu cầu thanh toán" — loại mẫu chính thức đó bắt STK phải khai CỐ ĐỊNH riêng cho TỪNG
    // toà nhà lúc tạo mẫu, không khả thi khi nhiều toà có STK khác nhau). Quyết định cuối: tin Zalo
    // CHỈ nhắc số tiền cần trả (giống hệt "mẫu đầu" ban đầu) — khách tự xem số tài khoản trên phiếu
    // in/app, không nêu STK ở kênh Zalo.
    //
    // invoice_code: Zalo từ chối mẫu lần đầu vì lỗi [CT_14] — yêu cầu PHẢI có 1 tham số định danh
    // ĐÚNG giao dịch/khách hàng này (kiểu "mã đơn hàng"), không chỉ nội dung chung chung. Ghép từ mã
    // phòng + tháng hoá đơn cho DUY NHẤT với từng lần gửi (VD "A02-092026") — không cần thêm cột DB
    // mới vì Invoice hiện chưa có mã hoá đơn riêng.
    /**
     * @return array{invoice_code: string, month: string, room_fee: string, electric_fee: string, water_fee: string, other_fee: string, total_amount: string, previous_debt: string, grand_total: string}
     */
    private function buildInvoiceTemplateData(Invoice $invoice): array
    {
        // Vẫn "invoice_code has invalid format" dù đã bỏ dấu gạch ngang (2026-09-08, gửi thử thật
        // 2 lần) — kiểu "Mã số" trên ZBS nhiều khả năng chỉ chấp nhận THUẦN CHỮ SỐ, không cho lẫn cả
        // chữ cái ("A01092026" vẫn có chữ A). Đổi sang thuần số: id hoá đơn (luôn có, duy nhất) +
        // tháng dạng số, để chắc chắn khớp mọi ràng buộc định dạng loại "Mã số".
        // "room_fee has invalid format" (2026-09-08, gửi thử thật) — kiểu "Số lượng / Số tiền" trên
        // ZBS chỉ chấp nhận SỐ THUẦN (không dấu chấm phân cách nghìn, không chữ "đ" đằng sau) — Zalo
        // tự format hiển thị đẹp phía họ. Áp dụng cho toàn bộ 7 trường tiền cùng lúc (money() cũ chỉ
        // dùng ở đây, không còn nơi nào khác cần dạng "3.300.000đ" nữa).
        return [
            'invoice_code'  => (string) $invoice->id . ($invoice->month?->format('mY') ?? ''),
            'month'         => $invoice->month?->format('m/Y') ?? '',
            'room_fee'      => $this->rawAmount($invoice->room_price),
            'electric_fee'  => $this->rawAmount($invoice->electric_amount),
            'water_fee'     => $this->rawAmount($invoice->water_amount),
            'other_fee'     => $this->rawAmount($invoice->service_amount),
            'total_amount'  => $this->rawAmount($invoice->total_amount),
            'previous_debt' => $this->rawAmount(InvoiceContentRenderer::previousDebt($invoice)),
            'grand_total'   => $this->rawAmount(InvoiceContentRenderer::totalOwed($invoice)),
        ];
    }

    private function rawAmount(float|int|null $amount): string
    {
        return (string) (int) round((float) $amount);
    }

    // Tham số khai kiểu "Mã số" trên ZBS chỉ chấp nhận chữ+số, KHÔNG được có dấu gạch ngang hay ký
    // tự khác — gửi "A-01" bị Zalo từ chối thật (lỗi "invoice_code has invalid format", xác nhận
    // 2026-09-08). Chỉ giữ lại chữ cái/số, bỏ hết dấu gạch ngang/khoảng trắng/ký tự đặc biệt khác.
    private function sanitizeCode(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';
    }

    private function sendZns(?int $reminderId, string $phone, ?string $recipientName, string $templateId, array $templateData): array
    {
        $formattedPhone = $this->formatPhoneNumber($phone);

        try {
            $accessToken = $this->tokenService->getAccessToken();
            $appSecret   = ZaloSetting::current()->app_secret;

            $response = Http::timeout(30)
                ->withHeaders([
                    'access_token' => $accessToken,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://business.openapi.zalo.me/message/template', [
                    'phone'           => $formattedPhone,
                    'template_id'     => $templateId,
                    'template_data'   => $templateData,
                    'tracking_id'     => $reminderId !== null ? (string) $reminderId : ('otp-' . now()->timestamp . '-' . random_int(1000, 9999)),
                    'appsecret_proof' => hash_hmac('sha256', $accessToken, $appSecret),
                ]);

            $result = $response->json();

            if ($response->successful() && (int) ($result['error'] ?? -1) === 0) {
                ZaloNotification::create([
                    'reminder_id'     => $reminderId,
                    'phone_number'    => $formattedPhone,
                    'recipient_name'  => $recipientName,
                    'template_id'     => $templateId,
                    'template_data'   => $templateData,
                    'status'          => ZaloNotification::STATUS_SENT,
                    'zalo_message_id' => $result['data']['msg_id'] ?? null,
                    'sent_at'         => now(),
                ]);

                return ['success' => true, 'message_id' => $result['data']['msg_id'] ?? null];
            }

            $errorMessage = $result['message'] ?? 'Unknown error';

            ZaloNotification::create([
                'reminder_id'    => $reminderId,
                'phone_number'   => $formattedPhone,
                'recipient_name' => $recipientName,
                'template_id'    => $templateId,
                'template_data'  => $templateData,
                'status'         => ZaloNotification::STATUS_FAILED,
                'error_message'  => $errorMessage,
            ]);

            return ['success' => false, 'error' => $errorMessage];
        } catch (\Throwable $e) {
            Log::warning('MinihouseZaloService: gửi ZNS thất bại', [
                'reminder_id' => $reminderId,
                'error'       => $e->getMessage(),
            ]);

            ZaloNotification::create([
                'reminder_id'    => $reminderId,
                'phone_number'   => $formattedPhone,
                'recipient_name' => $recipientName,
                'template_id'    => $templateId,
                'template_data'  => $templateData,
                'status'         => ZaloNotification::STATUS_FAILED,
                'error_message'  => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '84' . substr($phone, 1);
        }

        return $phone;
    }
}
