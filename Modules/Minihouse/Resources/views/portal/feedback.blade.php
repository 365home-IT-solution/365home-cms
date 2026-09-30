@extends('minihouse::portal.layout')

@section('title', 'Gửi phản hồi - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Gửi phản hồi</h1>
    <p class="mt-1 text-sm text-gray-500">
        @if ($activeContract?->room)
            Phòng {{ $activeContract->room->code }} — báo sự cố hoặc góp ý, chủ nhà sẽ xem và phản hồi lại ngay trong mục "Thông báo".
        @else
            Báo sự cố hoặc góp ý, chủ nhà sẽ xem và phản hồi lại ngay trong mục "Thông báo".
        @endif
    </p>

    <div class="mt-4 mh-card mh-card-pad">
        <form method="POST" action="{{ route('minihouse.portal.feedback.store') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf

            <div>
                <label class="mh-field-label">Mức độ hài lòng</label>
                <div id="star-rating" class="flex gap-1">
                    @for ($i = 1; $i <= 5; $i++)
                        <label class="cursor-pointer">
                            <input type="radio" name="rating" value="{{ $i }}" class="hidden star-input" {{ (int) old('rating', 5) === $i ? 'checked' : '' }}>
                            <span class="star text-4xl select-none" style="color: #FBBF24;">{{ $i <= (int) old('rating', 5) ? '★' : '☆' }}</span>
                        </label>
                    @endfor
                </div>
            </div>

            <div>
                <label class="mh-field-label">Nội dung</label>
                <textarea name="content" rows="5" class="mh-input" placeholder="Mô tả sự cố hoặc góp ý của bạn...">{{ old('content') }}</textarea>
            </div>


            <div>
                <label class="mh-field-label">Ảnh đính kèm (không bắt buộc, tối đa 5 ảnh)</label>
                <div id="fb-previews" class="flex flex-wrap gap-2 mb-2"></div>
                <label id="fb-add" class="inline-flex items-center px-3 py-2 rounded-lg border border-dashed border-gray-300 text-sm text-gray-600 cursor-pointer">
                    + Thêm ảnh
                    <input id="fb-images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="hidden">
                </label>
                <p id="fb-image-error" class="text-xs mt-1" style="color:#dc2626;">@error('images'){{ $message }}@enderror @error('images.*'){{ $message }}@enderror</p>
            </div>

            <button type="submit" class="mh-btn-primary">Gửi phản hồi</button>
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
@endsection
