<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LockNotificationMail;
use App\Services\TelegramService;
use App\Settings\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Carbon\Carbon;

/**
 * TTLock Callback Controller
 *
 * Nhận thông báo sự kiện khóa từ TTLock Cloud khi:
 *  - Khách mở khóa (check-in)
 *  - Khách đóng khóa (check-out)
 *
 * Endpoint: POST /api/lock/callback
 *
 * Docs: https://cnapi.ttlock.com  →  Lock Records Notify
 */
class LockRecordCallbackController extends Controller
{
    // =====================================================
    // Record Type của TTLock Cloud API
    // =====================================================

    /**
     * recordType → nhãn phương thức mở khóa bằng passcode.
     * Chỉ giữ các loại có keyboardPwd (7 = OTP, 11 = mã cố định).
     */
    private const UNLOCK_TYPES = [
        7  => 'Mở khóa bằng mã OTP',
        11 => 'Mở khóa bằng mã cố định',
    ];

    private TelegramService $telegram;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
    }

    // =====================================================
    // MAIN CALLBACK HANDLER
    // =====================================================

public function handle(Request $request)
{
    Log::info('TTLock callback received', [
        'ip'         => $request->ip(),
        'lockId'     => $request->input('lockId'),
        'lockMac'    => $request->input('lockMac'),
        'notifyType' => $request->input('notifyType'),
        // 'records' bị omit vì chứa keyboardPwd (passcode của khách)
    ]);

    $notifyType = (int) $request->input('notifyType');
    if ($notifyType !== 1) {
        Log::warning('TTLock callback: unsupported notifyType', ['notifyType' => $notifyType]);
        return response('success');
    }

    $lockId  = (int) $request->input('lockId');
    $lockMac = $request->input('lockMac', '');
    $records = json_decode($request->input('records', '[]'), true);

    if (empty($records) || !is_array($records)) {
        Log::warning('TTLock callback: no records or invalid JSON', ['raw' => $request->input('records')]);
        return response('success');
    }

    // ✅ Chống TTLock retry: hash lockId + records tạo fingerprint duy nhất cho mỗi sự kiện
    // Nếu cùng payload đến lần 2 (do TTLock retry vì timeout) → block ngay, không xử lý lại
    $dedupeKey = 'ttlock_callback_' . md5($lockId . $request->input('records', ''));
    if (Cache::has($dedupeKey)) {
        Log::info('TTLock callback: duplicate request blocked', ['lockId' => $lockId]);
        return response('success');
    }
    // Đặt cache TRƯỚC khi xử lý để chặn request đến trong lúc đang xử lý
    Cache::put($dedupeKey, true, now()->addMinutes(5));

    $isKnownLock = Product::where('lock_id', $lockId)
        ->orWhere('lock_id_checkout', $lockId)
        ->exists();

    if (!$isKnownLock) {
        Log::warning('TTLock callback: lockId không thuộc phòng nào', ['lockId' => $lockId, 'lockMac' => $lockMac]);
        return response('success');
    }

    foreach ($records as $record) {
        try {
            $this->processRecord($lockId, $lockMac, $record);
        } catch (\Throwable $e) {
            Log::error('TTLock callback: processRecord failed', [
                'lockId' => $lockId,
                'record' => $record,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);
        }
    }

    return response('success');
}

    // =====================================================
    // XỬ LÝ MỘT RECORD
    // =====================================================

    private function processRecord(int $lockId, string $lockMac, array $record): void
    {
        $recordType       = (int) ($record['recordType'] ?? 0);
        $success          = (int) ($record['success'] ?? 1);
        $lockDateMs       = (int) ($record['lockDate'] ?? 0);
        $electricQuantity = (int) ($record['electricQuantity'] ?? 0);
        $username         = $record['username'] ?? '';
        $keyboardPwd      = $record['keyboardPwd'] ?? '';

        // Bỏ qua các thao tác thất bại
        if ($success !== 1) {
            Log::info('TTLock callback: ignored failed record', ['recordType' => $recordType, 'lockId' => $lockId]);
            return;
        }

        // Chỉ xử lý event nhập qua passcode
        if ($keyboardPwd === '') {
            Log::info('TTLock callback: skipped (no passcode, likely staff or auto-lock)', [
                'recordType' => $recordType,
                'lockId'     => $lockId,
            ]);
            return;
        }

        $lockTime = $lockDateMs > 0
            ? Carbon::createFromTimestampMs($lockDateMs)->setTimezone(config('app.timezone', 'Asia/Ho_Chi_Minh'))
            : now();

        // Tìm đơn hàng từ username trong record (TTLock gửi tên passcode vào field username)
        // lockId chỉ dùng để phân biệt check-in / check-out
        $result      = $this->findActiveOrderAndCode($lockId, $lockTime, $keyboardPwd, $username);
        $product     = $result['product'];
        $activeOrder = $result['order'];
        $activeCode  = $result['accessCode'];
        $activeItem  = $result['orderItem'];
        $isCheckin   = $result['isCheckin'];

        if (!$product) {
            Log::warning('TTLock callback: không xác định được phòng từ passcode', [
                'lockId'      => $lockId,
                'keyboardPwd' => $keyboardPwd,
            ]);
            return;
        }

        // Khóa ngoài (lock_id) → CHECK-IN, khóa trong (lock_id_checkout) → CHECK-OUT
        if ($isCheckin) {
            $this->handleUnlockEvent($product, $lockId, $lockMac, $record, $lockTime, $activeOrder, $activeCode, $activeItem, $electricQuantity, $username);
        } else {
            $this->handleLockEvent($product, $lockId, $lockMac, $record, $lockTime, $activeOrder, $activeCode, $activeItem, $electricQuantity, $username);
        }
    }

    // =====================================================
    // SỰ KIỆN MỞ KHÓA → CHECK-IN
    // =====================================================

    private function handleUnlockEvent(
        Product     $product,
        int         $lockId,
        string      $lockMac,
        array       $record,
        Carbon      $lockTime,
        ?Order      $order,
        ?object     $accessCode,
        ?OrderItem  $orderItem,
        int         $electricQuantity,
        string      $username
    ): void {
        $recordType = (int) ($record['recordType'] ?? 0);
        $method     = self::UNLOCK_TYPES[$recordType] ?? 'Mở khóa bằng mã';

        Log::info('TTLock: UNLOCK event', [
            'product'     => $product->name,
            'lockId'      => $lockId,
            'time'        => $lockTime->toDateTimeString(),
            'order'       => $order?->id,
            'accessCode'  => $accessCode?->code,
        ]);

        $message = $this->buildUnlockMessage($product, $lockId, $lockMac, $lockTime, $order, $accessCode, $orderItem, $method, $electricQuantity, $username);

        try {
            $this->telegram->sendLockMessage($message);
        } catch (\Exception $e) {
            Log::error('TTLock: Telegram unlock notify failed', ['error' => $e->getMessage()]);
        }

        $this->sendLockEmail('CHECK-IN: ' . $product->name, $message);
    }

    // =====================================================
    // SỰ KIỆN ĐÓNG KHÓA → CHECK-OUT
    // =====================================================

    private function handleLockEvent(
        Product    $product,
        int        $lockId,
        string     $lockMac,
        array      $record,
        Carbon     $lockTime,
        ?Order     $order,
        ?object    $accessCode,
        ?OrderItem $orderItem,
        int        $electricQuantity,
        string     $username
    ): void {
        Log::info('TTLock: LOCK event (checkout lock)', [
            'product'    => $product->name,
            'lockId'     => $lockId,
            'time'       => $lockTime->toDateTimeString(),
            'order'      => $order?->id,
            'accessCode' => $accessCode?->code,
        ]);

        $battery = $electricQuantity > 0 ? "\nPin: {$electricQuantity}%" : '';

        $msg = "CHECK-OUT - <b>{$product->name}</b>\n"
             . "{$lockTime->format('H:i d/m/Y')}\n";

        if ($order) {
            $msg .= "\n"
                 .  "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
                 .  "Mã đơn: <code>{$order->order_code}</code>\n";

            if ($accessCode) {
                $msg .= "Mã mở: <code>{$accessCode->code}</code>\n";
            }
        } else {
            $msg .= "Ngoài giờ đặt phòng\n";
        }

        $msg .= $battery;

        $msg = trim($msg);

        try {
            $this->telegram->sendLockMessage($msg);
        } catch (\Exception $e) {
            Log::error('TTLock: Telegram lock notify failed', ['error' => $e->getMessage()]);
        }

        $this->sendLockEmail('CHECK-OUT: ' . $product->name, $msg);
    }

    // =====================================================
    // TÌM ACCESS CODE + ĐƠN HÀNG ĐANG HOẠT ĐỘNG
    // =====================================================

    /**
     * Tìm AccessCode + Order tương ứng với sự kiện khóa.
     *
     * CHỈ tra cứu khi khách mở bằng passcode (keyboardPwd không rỗng).
     * Vân tay / thẻ / app → trả về null (vẫn gửi Telegram nhưng không có thông tin đơn).
     *
     * Luồng:
     *   keyboardPwd → AccessCode.code (category_id của phòng)
     *                   → Order (pivot, category_id, status=paid)
     *                   → OrderItem (hiển thị slot, checkout)
     *
     * @return array{product: Product|null, isCheckin: bool, order: Order|null, accessCode: \Modules\AccessCode\Entities\AccessCode|null, orderItem: OrderItem|null}
     */
    private function findActiveOrderAndCode(int $lockId, Carbon $atTime, string $keyboardPwd = '', string $username = ''): array
    {
        $empty = ['product' => null, 'isCheckin' => true, 'order' => null, 'accessCode' => null, 'orderItem' => null];

        if ($keyboardPwd === '') {
            return $empty;
        }

        // Bước 1: TTLock gửi tên passcode trong field "username" của record callback
        // Ví dụ: username = "Order #2013" → lấy trực tiếp, không cần gọi API list
        $pwdName = $username;

        if (!preg_match('/Order #(\d+)/i', $pwdName, $m)) {
            Log::info('TTLock: username không chứa mã đơn hàng', [
                'lockId'      => $lockId,
                'keyboardPwd' => $keyboardPwd,
                'username'    => $username,
            ]);
            return $empty;
        }

        $orderId = (int) $m[1];

        // Bước 3: Load đơn hàng kèm items và product
        $order = Order::with(['items.product', 'accessCodes'])->find($orderId);

        if (!$order) {
            Log::warning('TTLock: không tìm thấy đơn hàng', [
                'lockId'  => $lockId,
                'orderId' => $orderId,
                'pwdName' => $pwdName,
            ]);
            return $empty;
        }

        // Bước 4: Tìm OrderItem có product gắn với lockId này
        $orderItem = $order->items
            ->where('extra_fee', 0)
            ->first(fn ($item) => $item->product && (
                (int) $item->product->lock_id          === $lockId ||
                (int) $item->product->lock_id_checkout === $lockId
            ));

        // Fallback: lấy item đầu tiên không phải phí phụ
        if (!$orderItem) {
            $orderItem = $order->items->where('extra_fee', 0)->first();
        }

        $product    = $orderItem?->product;
        $accessCode = $order->accessCodes->first();

        if (!$product) {
            Log::warning('TTLock: đơn hàng không có phòng', [
                'lockId'  => $lockId,
                'orderId' => $orderId,
            ]);
            return $empty;
        }

        // Bước 5: Xác định check-in hay check-out dựa vào lockId
        $isCheckin = ((int) $product->lock_id === $lockId);

        Log::info('TTLock: xác định đơn từ username callback', [
            'lockId'    => $lockId,
            'username'  => $username,
            'orderId'   => $orderId,
            'product'   => $product->name,
            'isCheckin' => $isCheckin,
        ]);

        return [
            'product'    => $product,
            'isCheckin'  => $isCheckin,
            'order'      => $order,
            'accessCode' => $accessCode,
            'orderItem'  => $orderItem,
        ];
    }

    // =====================================================
    // BUILD TELEGRAM MESSAGE: MỞ KHÓA (CHECK-IN)
    // =====================================================

    private function buildUnlockMessage(
        Product    $product,
        int        $lockId,
        string     $lockMac,
        Carbon     $lockTime,
        ?Order     $order,
        ?object    $accessCode,
        ?OrderItem $orderItem,
        string     $method,
        int        $electricQuantity,
        string     $username
    ): string {
        $msg = "CHECK-IN - <b>{$product->name}</b>\n"
             . "{$lockTime->format('H:i d/m/Y')}\n";

        if ($order) {
            $msg .= "\n"
                 .  "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
                 .  "Mã đơn: <code>{$order->order_code}</code>\n";

            if ($orderItem) {
                if ($orderItem->checkout_date) {
                    $msg .= "Checkout: {$orderItem->checkout_date->format('H:i d/m/Y')}\n";
                }
                if ($orderItem->slot_label) {
                    $msg .= "Khung giờ: {$orderItem->slot_label}\n";
                }
            }

            if ($accessCode) {
                $msg .= "Mã mở: <code>{$accessCode->code}</code>";
                if ($accessCode->valid_until) {
                    $msg .= " (HHL: {$accessCode->valid_until->format('H:i d/m')})";
                }
                $msg .= "\n";
            }
        } else {
            $msg .= "Không tìm thấy đặt phòng tương ứng\n";
        }

        if ($electricQuantity > 0) {
            $msg .= "Pin: {$electricQuantity}%";
        }

        return trim($msg);
    }

    // =====================================================
    // BUILD TELEGRAM MESSAGE: ĐÓNG KHÓA (CHECK-OUT)
    // =====================================================

    private function buildLockMessage(
        Product    $product,
        int        $lockId,
        string     $lockMac,
        Carbon     $lockTime,
        ?Order     $order,
        ?object    $accessCode,
        int        $electricQuantity,
        string     $username
    ): string {
        $msg = "CHECK-OUT - <b>{$product->name}</b>\n"
             . "{$lockTime->format('H:i d/m/Y')}\n";

        if ($order) {
            $msg .= "\n"
                 .  "Khách: {$order->buyer_name} | {$order->buyer_phone}\n"
                 .  "Mã đơn: <code>{$order->order_code}</code>\n";

            if ($accessCode) {
                $msg .= "Mã mở: <code>{$accessCode->code}</code>\n";
            }
        } else {
            $msg .= "Ngoài giờ đặt phòng\n";
        }

        if ($electricQuantity > 0) {
            $msg .= "Pin: {$electricQuantity}%";
        }

        return trim($msg);
    }

    // =====================================================
    // GỬI EMAIL THÔNG BÁO CHECK-IN / CHECK-OUT
    // =====================================================

    private function sendLockEmail(string $subject, string $telegramText): void
    {
        try {
            /** @var MailSettings $mailSettings */
            $mailSettings = app(MailSettings::class);

            if (!$mailSettings->isMailSettingsConfigured()) {
                return;
            }

            $recipients = $mailSettings->lock_notify_emails ?? [];
            if (empty($recipients)) {
                return;
            }

            // Chuyển định dạng Telegram HTML → email HTML
            $html = nl2br(e($telegramText));
            $html = preg_replace('/<b>(.*?)<\/b>/s', '<strong>$1</strong>', $html);
            $html = preg_replace('/&lt;b&gt;(.*?)&lt;\/b&gt;/s', '<strong>$1</strong>', $html);
            $html = preg_replace('/&lt;code&gt;(.*?)&lt;\/code&gt;/s', '<code style="background:#f3f4f6;padding:2px 6px;border-radius:4px;">$1</code>', $html);

            $body = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#333;max-width:500px;margin:0 auto;padding:20px;">'
                  . '<div style="background:#f8f9fa;border-radius:8px;padding:16px 20px;">'
                  . $html
                  . '</div>'
                  . '<p style="margin-top:16px;font-size:12px;color:#999;">365Home – Hệ thống thông báo tự động</p>'
                  . '</div>';

            $mailSettings->loadMailSettingsToConfig();

            Mail::to($recipients)->send(new LockNotificationMail($subject, $body));
        } catch (\Exception $e) {
            Log::error('TTLock: Email notify failed', ['error' => $e->getMessage()]);
        }
    }
}

