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

        <form method="POST" action="{{ route('minihouse.feedback.store') }}" enctype="multipart/form-data" class="mt-4 space-y-4">
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


            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ảnh đính kèm (không bắt buộc, tối đa 5 ảnh)</label>
                <div id="fb-previews" class="flex flex-wrap gap-2 mb-2"></div>
                <label id="fb-add" class="inline-flex items-center px-3 py-2 rounded-lg border border-dashed border-gray-300 text-sm text-gray-600 cursor-pointer hover:border-gray-900">
                    + Thêm ảnh
                    <input id="fb-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="hidden">
                </label>
                <p id="fb-image-error" class="text-xs text-red-600 mt-1">@error('images'){{ $message }}@enderror @error('images.*'){{ $message }}@enderror</p>
            </div>

            <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                Gửi phản hồi
            </button>
        </form>
    </div>
    <script>
        // Ảnh đính kèm: gom file vào DataTransfer để vừa xem trước vừa gỡ được từng ảnh trước khi gửi.
        (function () {
            const MAX = 5, MAX_BYTES = 5 * 1024 * 1024, TYPES = ['image/jpeg', 'image/png', 'image/webp'];
            const input = document.getElementById('fb-images');
            const addBtn = document.getElementById('fb-add');
            const previews = document.getElementById('fb-previews');
            const errorEl = document.getElementById('fb-image-error');
            const dt = new DataTransfer();

            function render() {
                previews.innerHTML = '';
                Array.from(dt.files).forEach((file, idx) => {
                    const wrap = document.createElement('div');
                    wrap.style.cssText = 'position:relative;width:64px;height:64px;';
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = '';
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;border-radius:8px;';
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = '×';
                    btn.setAttribute('aria-label', 'Xoá ảnh');
                    btn.style.cssText = 'position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:9999px;background:#111827;color:#fff;font-size:14px;line-height:20px;text-align:center;';
                    btn.addEventListener('click', () => {
                        const next = new DataTransfer();
                        Array.from(dt.files).forEach((f, i) => { if (i !== idx) next.items.add(f); });
                        dt.items.clear();
                        Array.from(next.files).forEach((f) => dt.items.add(f));
                        input.files = dt.files;
                        errorEl.textContent = '';
                        render();
                    });
                    wrap.append(img, btn);
                    previews.appendChild(wrap);
                });
                addBtn.style.display = dt.files.length >= MAX ? 'none' : '';
                input.files = dt.files;
            }

            input.addEventListener('change', () => {
                errorEl.textContent = '';
                Array.from(input.files).forEach((file) => {
                    if (dt.files.length >= MAX) { errorEl.textContent = 'Tối đa ' + MAX + ' ảnh.'; return; }
                    if (!TYPES.includes(file.type)) { errorEl.textContent = 'Chỉ nhận ảnh JPG, PNG hoặc WEBP.'; return; }
                    if (file.size > MAX_BYTES) { errorEl.textContent = 'Mỗi ảnh tối đa 5MB.'; return; }
                    dt.items.add(file);
                });
                render();
            });
        })();
    </script>
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
