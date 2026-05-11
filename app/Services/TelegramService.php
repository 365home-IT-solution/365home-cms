<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramService
{
    private string $token;
    private string $chatId;

    public function __construct()
    {
        $this->token  = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
    }

public function sendMessage(string $message, ?string $chatId = null): void
{
    $response = Http::withoutVerifying()
        ->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
        'chat_id'    => $chatId ?? $this->chatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ]);

    \Log::info('Telegram API response', [
        'status' => $response->status(),
        'body'   => $response->json(),
    ]);
}

public function sendLockMessage(string $message): void
{
    $lockChatId = config('services.telegram.lock_chat_id') ?: $this->chatId;
    $this->sendMessage($message, $lockChatId);
}

/**
 * Gửi thông báo liên quan đến cọc phòng (style=2) sang nhóm Telegram riêng.
 * Nếu chưa cấu hình TELEGRAM_DEPOSIT_CHAT_ID thì fallback về chat_id chính.
 */
public function sendDepositMessage(string $message): void
{
    $depositChatId = config('services.telegram.deposit_chat_id') ?: $this->chatId;
    $this->sendMessage($message, $depositChatId);
}
}