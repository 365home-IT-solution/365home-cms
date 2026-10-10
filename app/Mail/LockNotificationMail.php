<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Email thông báo dùng chung (OTP, hợp đồng, hồ sơ đối tác, thông báo khoá...): nơi gọi chỉ truyền
// tiêu đề + đoạn HTML nội dung. Bọc đoạn đó trong khung thư đầy đủ (emails/notification.blade.php)
// và gửi kèm bản văn bản thuần (multipart/alternative) — thư chỉ có vài thẻ <p> trần, không có phần
// text, là kiểu thư bộ lọc spam của Gmail/Outlook hay chặn.
class LockNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $htmlBody
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        $siteUrl = rtrim((string) config('app.url'), '/');

        return new Content(
            view: 'emails.notification',
            text: 'emails.notification-text',
            with: [
                'textBody'  => self::htmlToText($this->htmlBody),
                'brandName' => config('mail.from.name') ?: config('app.name', '365 Home'),
                'siteUrl'   => $siteUrl,
                'siteHost'  => parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl,
            ],
        );
    }

    // Bản văn bản thuần của đoạn HTML nội dung: giữ lại URL của link, xuống dòng theo khối.
    private static function htmlToText(string $html): string
    {
        // <a href="URL">nhãn</a> -> "nhãn: URL" (hoặc chỉ URL nếu nhãn chính là URL đó).
        $text = preg_replace_callback(
            '/<a\b[^>]*\bhref=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/isu',
            function (array $m): string {
                $label = trim(strip_tags($m[3]));

                return $label === '' || $label === $m[2] ? $m[2] : $label . ': ' . $m[2];
            },
            $html
        );
        $text = preg_replace('/<br\s*\/?>/iu', "\n", (string) $text);
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/iu', "\n\n", (string) $text);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ ?\n ?/u', "\n", (string) $text);

        return trim((string) preg_replace('/\n{3,}/u', "\n\n", (string) $text));
    }
}
