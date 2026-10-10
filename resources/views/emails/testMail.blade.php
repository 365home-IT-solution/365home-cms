<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <title>{{ config('mail.from.name') ?: config('app.name') }} — Mail thử nghiệm</title>
</head>

<body>
    <h1>{{ $mailData['title'] }}</h1>
    <p>{{ $mailData['body'] }}</p>

    <p>Bạn đã cấu hình gửi email thành công trên hệ thống. Email này là một thử nghiệm
        để xác nhận rằng quá trình cấu hình đã được thực hiện đúng cách. Nếu bạn nhận
        được email này, điều đó có nghĩa là chức năng gửi email của bạn đang hoạt động bình thường.</p>

    {{-- Thời điểm gửi: mỗi thư thử có nội dung khác nhau — nhiều thư giống hệt từng chữ gửi liên
         tiếp tới cùng 1 hộp thư là dấu hiệu spam điển hình. --}}
    @isset($mailData['sent_at'])
        <p>Thời điểm gửi: {{ $mailData['sent_at'] }}</p>
    @endisset

    <p>Cám ơn</p>
</body>

</html>
