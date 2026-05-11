<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

class TestLockCallback extends Command
{
    protected $signature = 'lock:test-callback
                            {--lock_id= : Lock ID (lấy từ product.lock_id)}
                            {--code=     : Passcode (lấy từ access_codes.code)}
                            {--telegram  : Gửi Telegram }';

    protected $description = 'Thử nghiệm luồng TTLock callback → Telegram (bypass IP check)';

    public function handle(TelegramService $telegram): int
    {
        // ──────────────────────────────────────────────
        // Bước 1: Lấy danh sách để user chọn nếu không truyền param
        // ──────────────────────────────────────────────
        $products = Product::whereNotNull('lock_id')->get(['id', 'name', 'lock_id', 'lock_id_checkout']);

        if ($products->isEmpty()) {
            $this->error('Không có phòng nào được gán lock_id.');
            return 1;
        }

        $this->info('=== Danh sách phòng có lock ===');
        $this->table(['ID', 'Tên phòng', 'lock_id (check-in)', 'lock_id_checkout'], $products->map(fn($p) => [
            $p->id, $p->name, $p->lock_id, $p->lock_id_checkout ?? '(không có)',
        ])->toArray());

        // ──────────────────────────────────────────────
        // Bước 2: Lấy active TTLock access codes
        // ──────────────────────────────────────────────
        $codes = AccessCode::where('status', 'active')
            ->whereNotNull('ttlock_keyboard_pwd_id')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'code', 'category_id', 'ttlock_keyboard_pwd_id', 'valid_from', 'valid_until']);

        $this->info('=== Active TTLock Access Codes (10 gần nhất) ===');
        $this->table(['ID', 'Code', 'Category ID', 'TTLock PWD ID', 'valid_from', 'valid_until'], $codes->map(fn($c) => [
            $c->id, $c->code, $c->category_id, $c->ttlock_keyboard_pwd_id,
            $c->valid_from?->format('d/m/Y H:i') ?? '-',
            $c->valid_until?->format('d/m/Y H:i') ?? '-',
        ])->toArray());

        // ──────────────────────────────────────────────
        // Bước 3: Prompt chọn lock_id và code
        // ──────────────────────────────────────────────
        $lockId = (int) ($this->option('lock_id') ?: $this->ask('Nhập lock_id muốn test (từ bảng trên)'));
        $code   = $this->option('code') ?: $this->ask('Nhập passcode muốn test (từ bảng trên)');

        // ──────────────────────────────────────────────
        // Bước 4: Chạy logic tìm phòng + đơn hàng
        // ──────────────────────────────────────────────
        $this->line('');
        $this->info("=== Bắt đầu mô phỏng callback: lockId={$lockId}, code={$code} ===");

        $now = now();

        // Tìm AccessCode
        $accessCode = AccessCode::where('code', $code)->where('status', 'active')->first();
        if (!$accessCode) {
            $this->error("Không tìm thấy AccessCode với code='{$code}' và status='active'.");
            return 1;
        }
        $this->info("✅ AccessCode tìm thấy: ID={$accessCode->id}, category_id={$accessCode->category_id}");

        // Tìm Product
        $product = Product::where(function ($q) use ($lockId) {
            $q->where('lock_id', $lockId)->orWhere('lock_id_checkout', $lockId);
        })->whereHas('categories', fn($q) => $q->where('categories.id', $accessCode->category_id))->first();

        if (!$product) {
            $this->error("Không tìm thấy Product khớp lockId={$lockId} + category_id={$accessCode->category_id}.");
            $this->warn('→ Kiểm tra: Product có đúng category không? (category_id của AccessCode phải trùng với category của Product)');
            return 1;
        }
        $this->info("✅ Product tìm thấy: [{$product->id}] {$product->name}");

        // Xác định check-in hay check-out
        $isCheckin = ((int) $product->lock_id === $lockId);
        $this->info('✅ Loại sự kiện: ' . ($isCheckin ? 'CHECK-IN (khóa ngoài)' : 'CHECK-OUT (khóa trong)'));

        // Tìm OrderItem
        $orderItem = OrderItem::where('product_id', $product->id)
            ->where('extra_fee', 0)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<=', $now->copy()->addMinutes(15))
            ->where('checkout_date', '>=', $now->copy()->subMinutes(15))
            ->whereHas('order', fn($q) => $q
                ->where('status', 'paid')
                ->whereHas('accessCodes', fn($aq) => $aq->where('code', $code))
            )
            ->with(['order', 'order.accessCodes' => fn($aq) => $aq->where('code', $code)])
            ->orderByDesc('checkin_date')
            ->first();

        $order = $orderItem?->order;

        if ($order) {
            $this->info("✅ Đơn hàng: {$order->order_code} | {$order->buyer_name} | {$order->buyer_phone}");
            $this->info("   Checkin: {$orderItem->checkin_date} | Checkout: {$orderItem->checkout_date}");
        } else {
            $this->warn('⚠️ Không tìm thấy đơn hàng đang hoạt động (ngoài giờ đặt phòng hoặc chưa gán mã).');
        }

        // ──────────────────────────────────────────────
        // Bước 5: Build message
        // ──────────────────────────────────────────────
        $lockTime = Carbon::now();

        if ($isCheckin) {
            $msg = "CHECK-IN - <b>{$product->name}</b>\n"
                 . "{$lockTime->format('H:i d/m/Y')}\n";
            if ($order) {
                $msg .= "\nKhách: {$order->buyer_name} | {$order->buyer_phone}\n"
                      . "Mã đơn: <code>{$order->order_code}</code>\n";
                if ($orderItem?->checkout_date) {
                    $msg .= "Checkout: {$orderItem->checkout_date->format('H:i d/m/Y')}\n";
                }
                if ($orderItem?->slot_label) {
                    $msg .= "Khung giờ: {$orderItem->slot_label}\n";
                }
                $msg .= "Mã mở: <code>{$accessCode->code}</code>\n";
            } else {
                $msg .= "Không tìm thấy đặt phòng tương ứng\n";
            }
        } else {
            $msg = "CHECK-OUT - <b>{$product->name}</b>\n"
                 . "{$lockTime->format('H:i d/m/Y')}\n";
            if ($order) {
                $msg .= "\nKhách: {$order->buyer_name} | {$order->buyer_phone}\n"
                      . "Mã đơn: <code>{$order->order_code}</code>\n"
                      . "Mã mở: <code>{$accessCode->code}</code>\n";
            } else {
                $msg .= "Ngoài giờ đặt phòng\n";
            }
        }
        $msg = "[TEST] " . trim($msg);

        $this->line('');
        $this->info('=== Nội dung Telegram message ===');
        $this->line($msg);

        // ──────────────────────────────────────────────
        // Bước 6: Gửi Telegram nếu có --telegram
        // ──────────────────────────────────────────────
        if ($this->option('telegram') || $this->confirm('Gửi Telegram thật không?', false)) {
            try {
                $telegram->sendLockMessage($msg);
                $this->info('✅ Telegram đã gửi!');
            } catch (\Exception $e) {
                $this->error('❌ Telegram lỗi: ' . $e->getMessage());
            }
        }

        $this->line('');
        $this->info('=== Test hoàn tất ===');
        return 0;
    }
}
