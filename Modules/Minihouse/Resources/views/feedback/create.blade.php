<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Góp ý / Đánh giá phòng thuê</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
        <h1 class="text-lg font-semibold text-gray-900">Góp ý / Đánh giá phòng thuê</h1>
        <p class="mt-1 text-sm text-gray-500">
            @if ($room)
                Phòng <span class="font-medium text-gray-700">{{ $room->code }}</span> — ý kiến của quý khách giúp chúng tôi phục vụ tốt hơn.
            @else
                Ý kiến của quý khách giúp chúng tôi phục vụ tốt hơn.
            @endif
        </p>

        @if (session('feedback_sent'))
            <div class="mt-4 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm px-3 py-2">
                Cảm ơn quý khách đã gửi phản hồi!
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-3 py-2">
                Vui lòng kiểm tra lại thông tin đã nhập.
            </div>
        @endif

        <form method="POST" action="{{ route('minihouse.feedback.store') }}" class="mt-4 space-y-4">
            @csrf
            <input type="hidden" name="room_id" value="{{ $room?->id }}">

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mức độ hài lòng</label>
                <div id="star-rating" class="flex gap-1">
                    @for ($i = 1; $i <= 5; $i++)
                        <label class="cursor-pointer">
                            <input type="radio" name="rating" value="{{ $i }}" class="hidden star-input" {{ (int) old('rating', 5) === $i ? 'checked' : '' }}>
                            <span class="star text-3xl text-yellow-400 select-none">{{ $i <= (int) old('rating', 5) ? '★' : '☆' }}</span>
                        </label>
                    @endfor
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Họ tên (không bắt buộc)</label>
                <input type="text" name="tenant_name" value="{{ old('tenant_name') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại (không bắt buộc)</label>
                <input type="text" name="tenant_phone" value="{{ old('tenant_phone') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Góp ý</label>
                <textarea name="content" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10" placeholder="Chia sẻ trải nghiệm của quý khách...">{{ old('content') }}</textarea>
            </div>

            <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                Gửi phản hồi
            </button>
        </form>
    </div>
    <script>
        const starInputs = document.querySelectorAll('#star-rating .star-input');
        const starLabels = document.querySelectorAll('#star-rating .star');

        starInputs.forEach((input, i) => {
            input.addEventListener('change', () => {
                starLabels.forEach((label, idx) => {
                    label.textContent = idx <= i ? '★' : '☆';
                });
            });
        });
    </script>
</body>
</html>
