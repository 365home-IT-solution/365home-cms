{{-- Khung chung cho mọi email thông báo gửi qua App\Mail\LockNotificationMail (OTP, hợp đồng, hồ sơ
     đối tác, thông báo khoá...). $htmlBody là HTML do nơi gọi tự dựng (đã escape sẵn dữ liệu động)
     nên in thẳng bằng {!! !!}. Email client không hỗ trợ <style>/class ổn định — chỉ dùng table +
     inline style. --}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $mailSubject }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="padding:20px 28px; border-bottom:1px solid #e5e7eb; font-size:20px; font-weight:bold; color:#111827;">
                            {{ $brandName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px; font-size:15px; line-height:1.6; color:#111827;">
                            {!! $htmlBody !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px; background:#f9fafb; border-top:1px solid #e5e7eb; font-size:12px; line-height:1.6; color:#6b7280;">
                            {{ $brandName }} — 254 Đường Xuân Thủy, An Bình, Ninh Kiều, Cần Thơ<br>
                            Hotline: 0939 174 365 · Website: <a href="{{ $siteUrl }}" style="color:#6b7280;">{{ $siteHost }}</a><br>
                            Bạn nhận được email này vì có giao dịch hoặc hồ sơ liên quan tới địa chỉ email của bạn trên {{ $brandName }}.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
