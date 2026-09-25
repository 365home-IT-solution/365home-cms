@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')
    @livewire('bladethemev1::breadcrumb', [
        'slug' => $room->slug,
        'name' => 'Phòng ' . $room->code,
        'parents' => [
            ['title' => 'Cho thuê theo tháng', 'url' => url('/minihouse')],
            ['title' => $room->building?->name, 'url' => url('/minihouse') . '?building_id=' . $room->building_id],
        ],
    ])

    {{-- max-w-7xl — ĐÚNG container của trang chi tiết Homestay thật (product-detail.blade.php dòng
         26). Tiêu đề + gallery nằm NGOÀI/TRƯỚC lưới 2 cột, chiếm ĐỦ 100% chiều rộng (xem file đó
         dòng 31-402: khối "p-3 header+gallery wrapper" đóng lại ở dòng 402, grid-cols-[1fr_380px]
         (dòng 405) chỉ bắt đầu SAU đó, bao phần "thông tin phòng"+"panel liên hệ" — KHÔNG bao luôn
         gallery như bản trước, đó là lý do panel bị đẩy lên ngang hàng với gallery thay vì nằm
         dưới nó. --}}
    <div class="max-w-7xl md:px-8 px-4 mx-auto py-6">
        {{-- Tiêu đề + "Chia sẻ" NẰM TRÊN gallery — đúng thứ tự trang chi tiết Homestay thật (xem
             product-detail.blade.php dòng ~31-44). Bỏ nút "Lưu" (yêu thích) — MiniHouse không có hệ
             thống wishlist, thêm nút không hoạt động sẽ gây hiểu nhầm. --}}
        <div class="hidden md:flex items-start justify-between gap-4 mb-4">
            <h1 class="text-2xl font-semibold text-[#222222] leading-snug">Phòng {{ $room->code }}</h1>
            <button type="button" onclick="mhShareRoom()" class="inline-flex h-7 items-center gap-1.5 rounded-lg px-2.5 text-sm font-semibold text-[#222222] underline underline-offset-2 hover:bg-[#F7F7F7] transition-colors shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                    <polyline points="16 6 12 2 8 6"></polyline>
                    <line x1="12" x2="12" y1="2" y2="15"></line>
                </svg>
                Chia sẻ
            </button>
        </div>

        {{-- Ảnh bìa + thư viện — reuse đúng MediaLibrary collections đã gắn ở panel quản trị, bố cục
             lưới 1 ảnh lớn + 4 ảnh nhỏ giống trang chi tiết phòng Homestay. Có nút "Xem tất cả ảnh"
             mở lightbox đơn giản (mh-lightbox, JS thuần ở cuối file) — KHÔNG dùng Alpine x-data phức
             tạp của bản gốc vì trang này không cần các tính năng khác đi kèm (video, đặt phòng...). --}}
<?php $photos = $room->photos ?? []; ?>
        <div class="relative grid grid-cols-1 md:grid-cols-4 md:grid-rows-2 gap-2 rounded-xl overflow-hidden mb-6" style="aspect-ratio:16/9;">
            <div class="md:col-span-2 md:row-span-2 bg-gray-100">
                @if ($photos[0] ?? null)
                    <img src="{{ $photos[0] }}" alt="Phòng {{ $room->code }}" class="w-full h-full object-cover cursor-pointer" onclick="mhOpenLightbox(0)">
                @else
                    <div class="w-full h-full flex items-center justify-center text-gray-300">Chưa có ảnh</div>
                @endif
            </div>
            @for ($i = 1; $i <= 4; $i++)
                <div class="hidden md:block bg-gray-100">
                    @if ($photos[$i] ?? null)
                        <img src="{{ $photos[$i] }}" alt="" class="w-full h-full object-cover cursor-pointer" onclick="mhOpenLightbox({{ $i }})">
                    @endif
                </div>
            @endfor

            @if (count($photos) > 1)
                <div class="absolute bottom-3 right-3">
                    <button type="button" onclick="mhOpenLightbox(0)" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-900 bg-white px-3 py-1.5 text-xs font-semibold text-gray-900 shadow-sm hover:bg-gray-50 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v18"></path>
                            <path d="M3 12h18"></path>
                            <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                        </svg>
                        Xem tất cả ảnh
                    </button>
                </div>
            @endif
        </div>

        {{-- Từ đây trở xuống mới là lưới 2 cột (nội dung + panel liên hệ), ĐÚNG khớp
             grid-cols-[1fr_380px] thật — panel bên phải cố định 380px, sticky theo cột trái, không
             còn ngang hàng với gallery ở trên nữa. --}}
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-0 lg:gap-8 items-start">
            <div>
                @if ($tourUrl)
                    <a href="{{ $tourUrl }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 mb-6 px-4 py-2 rounded-xl border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        Xem tour 360°
                    </a>
                @endif

                <h1 class="text-2xl font-bold text-gray-900 mb-1">Phòng {{ $room->code }}</h1>
                <p class="text-gray-500 mb-4">{{ $room->building?->name }} — {{ $room->building?->address }}</p>

                <div class="flex flex-wrap gap-4 mb-6 text-sm text-gray-600">
                    @if ($room->area)
                        <div class="flex items-center gap-1.5"><strong>{{ $room->area }}m²</strong> diện tích</div>
                    @endif
                    @if ($room->floor)
                        <div class="flex items-center gap-1.5">Tầng <strong>{{ $room->floor }}</strong></div>
                    @endif
                </div>

                @if ($room->note)
                    <div class="prose max-w-none mb-6 text-gray-700">{{ $room->note }}</div>
                @endif

                @if ($room->amenities->isNotEmpty())
                    <div class="mb-6">
                        <h2 class="font-semibold text-gray-900 mb-3">Tiện ích</h2>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($room->amenities as $amenity)
                                <span class="px-3 py-1.5 rounded-full bg-gray-100 text-sm text-gray-700">{{ $amenity->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Cột phải: giá + form "Liên hệ tư vấn" — KHÔNG có lịch/giá theo đêm hay nút "Đặt
                 phòng" như Home (MiniHouse cho thuê theo tháng, hợp đồng luôn do nhân viên tạo tay
                 sau khi chốt với khách qua đúng lead này, xem RentalInquiryController). --}}
            <div>
                <div class="sticky top-24 rounded-2xl border border-gray-200 p-5">
                    <div class="mb-4">
                        <span class="text-2xl font-bold" style="color:var(--color-primary);">{{ number_format((float) $room->price, 0, ',', '.') }}đ</span>
                        <span class="text-gray-400 text-sm"> /tháng</span>
                    </div>

                    <div id="mh-inquiry-success" class="hidden mb-4 rounded-xl bg-green-50 text-green-700 text-sm p-3"></div>
                    <div id="mh-inquiry-error" class="hidden mb-4 rounded-xl bg-red-50 text-red-700 text-sm p-3"></div>

                    <form id="mh-inquiry-form" class="space-y-3">
                        <input type="hidden" name="room_id" value="{{ $room->id }}">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Họ tên *</label>
                            <input type="text" name="full_name" required maxlength="255" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Số điện thoại *</label>
                            <input type="tel" name="phone" required maxlength="20" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Ngày muốn dọn vào</label>
                            <input type="date" name="preferred_move_in_date" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Ghi chú</label>
                            <textarea name="note" rows="2" maxlength="2000" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm"></textarea>
                        </div>
                        <button type="submit" class="w-full py-3 rounded-xl text-white font-semibold text-sm" style="background:var(--color-primary);">
                            Liên hệ tư vấn
                        </button>
                        <p class="text-xs text-gray-400 text-center">Nhân viên sẽ gọi lại tư vấn trong thời gian sớm nhất.</p>
                    </form>
                </div>
            </div>
        </div>

        {{-- Địa chỉ + bản đồ nhúng — CÙNG công thức Google Maps embed (không cần API key) đang dùng
             ở trang chi tiết phòng Homestay thật (xem product-detail.blade.php dòng ~1582-1596),
             chỉ đổi nguồn toạ độ sang của Room. --}}
        {{-- Dùng thẳng <?php ?> (không phải @php(...)) — @php(...) dạng 1 dòng bị bộ biên dịch
             Blade của theme này hiểu sai khi biểu thức có dấu ngoặc vuông mảng ("?? []"), nuốt mất
             toàn bộ HTML phía sau cho tới @endphp lạc đầu tiên tìm thấy trong file, gây lỗi
             "unexpected token class". Thẻ PHP thường luôn an toàn, không qua directive nào cả. --}}
        <?php
            $mhAddress = $room->address ?: $room->building?->address;
            $mhLat = $room->latitude ?? null;
            $mhLng = $room->longitude ?? null;
            $mhHasCoords = ! empty($mhLat) && ! empty($mhLng);
            $mhMapQuery = $mhHasCoords ? $mhLat . ',' . $mhLng : $mhAddress;
            $mhMapUrl = $room->map_url ?: ($mhMapQuery ? 'https://www.google.com/maps/search/?q=' . urlencode($mhMapQuery) : null);
            $mhMapEmbedSrc = $mhMapQuery ? ('https://www.google.com/maps?q=' . urlencode($mhMapQuery) . ($mhHasCoords ? '&ll=' . $mhLat . ',' . $mhLng . '&z=16' : '') . '&output=embed') : null;
        ?>
        @if ($mhAddress)
            <div class="mt-10 pt-8 pb-6 border-t border-gray-200">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Địa chỉ</h2>
                        <p class="text-base text-gray-700 mt-1">{{ $mhAddress }}</p>
                    </div>
                    @if ($mhMapUrl)
                        <a href="{{ $mhMapUrl }}" target="_blank" rel="noopener" class="shrink-0 inline-flex items-center gap-1.5 text-sm font-semibold underline underline-offset-2 whitespace-nowrap" style="color:var(--color-primary);">
                            Xem trên Google Maps
                        </a>
                    @endif
                </div>
                @if ($mhMapEmbedSrc)
                    <div class="w-full rounded-xl overflow-hidden border border-gray-200" style="height:360px;">
                        <iframe src="{{ $mhMapEmbedSrc }}" class="w-full h-full border-0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
                    </div>
                @endif
            </div>
        @endif

        {{-- Chính sách/FAQ tĩnh — dùng lại NGUYÊN 2 component có sẵn của Homestay
             (components/seo-content/{feature-grid,faq-accordion}.blade.php), chỉ đổi nội dung cho
             đúng nghiệp vụ thuê theo tháng (không có khung giờ/hủy đổi lịch như đặt ngắn hạn). --}}
        <div class="mt-10 pt-8 border-t border-gray-200">
            <x-bladethemev1::seo-content.feature-grid title="Vì sao thuê phòng qua 365 Home" :items="[
                ['icon' => 'tag', 'title' => 'Giá rõ ràng theo tháng', 'text' => 'Giá hiển thị là giá thuê trọn tháng, không phát sinh phí ẩn.'],
                ['icon' => 'shield', 'title' => 'Thông tin xác thực', 'text' => 'Phòng và toà nhà được quản lý, cập nhật tình trạng trống/đã thuê theo thời gian thực.'],
                ['icon' => 'clock', 'title' => 'Tư vấn nhanh chóng', 'text' => 'Để lại thông tin, nhân viên liên hệ tư vấn và hẹn xem phòng trong thời gian sớm nhất.'],
                ['icon' => 'card', 'title' => 'Hỗ trợ tận tình', 'text' => 'Được hỗ trợ trong suốt quá trình thuê, từ ký hợp đồng đến thanh toán hàng tháng.'],
            ]" />
        </div>

<?php
            $mhFaqs = [
                ['q' => 'Làm sao để thuê phòng ' . $room->code . '?', 'a' => 'Điền thông tin liên hệ ở form "Liên hệ tư vấn" phía trên, nhân viên sẽ gọi lại xác nhận và hẹn lịch xem phòng.'],
                ['q' => 'Giá hiển thị đã bao gồm những gì?', 'a' => 'Giá hiển thị là tiền thuê phòng theo tháng, chưa gồm điện nước và phí dịch vụ khác (nếu có) — nhân viên tư vấn sẽ thông báo cụ thể khi liên hệ.'],
                ['q' => 'Có cần đặt cọc trước không?', 'a' => 'Điều kiện đặt cọc/hợp đồng sẽ được nhân viên tư vấn trao đổi cụ thể khi liên hệ, tuỳ theo từng toà nhà.'],
                ['q' => 'Tôi có thể xem phòng trực tiếp trước khi thuê không?', 'a' => 'Có. Để lại thông tin ở form liên hệ, nhân viên sẽ hẹn lịch cho bạn xem phòng trực tiếp.'],
            ];
        ?>
        <div class="mt-10 pt-8 border-t border-gray-200">
            <x-bladethemev1::seo-content.faq-accordion :items="$mhFaqs" />
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script>
        (function () {
            var form = document.getElementById('mh-inquiry-form');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                var successBox = document.getElementById('mh-inquiry-success');
                var errorBox = document.getElementById('mh-inquiry-error');
                successBox.classList.add('hidden');
                errorBox.classList.add('hidden');

                var submitBtn = form.querySelector('button[type="submit"]');
                submitBtn.disabled = true;

                var payload = {
                    room_id: form.room_id.value,
                    full_name: form.full_name.value,
                    phone: form.phone.value,
                    preferred_move_in_date: form.preferred_move_in_date.value || null,
                    note: form.note.value || null,
                };

                fetch('{{ route('api.minihouse.public.rental-inquiries.store') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                })
                    .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
                    .then(function (result) {
                        if (result.ok) {
                            successBox.textContent = result.data.message || 'Đã ghi nhận yêu cầu, cảm ơn bạn!';
                            successBox.classList.remove('hidden');
                            form.reset();
                            form.room_id.value = '{{ $room->id }}';
                        } else {
                            var firstError = result.data.errors ? Object.values(result.data.errors)[0][0] : (result.data.message || 'Có lỗi xảy ra, vui lòng thử lại.');
                            errorBox.textContent = firstError;
                            errorBox.classList.remove('hidden');
                        }
                    })
                    .catch(function () {
                        errorBox.textContent = 'Không gửi được yêu cầu, vui lòng thử lại.';
                        errorBox.classList.remove('hidden');
                    })
                    .finally(function () {
                        submitBtn.disabled = false;
                    });
            });
        })();
    </script>

    {{-- Lightbox đơn giản (JS thuần) cho "Xem tất cả ảnh" — mở đúng danh sách ảnh của phòng này,
         không có tính năng nào khác (video/đặt phòng) như lightbox gốc của trang chi tiết Homestay
         vì trang này không cần. --}}
<?php $mhPhotos = $room->photos ?? []; ?>
    <div id="mh-lightbox" class="hidden fixed inset-0 z-[999] bg-black/90 flex items-center justify-center">
        <button type="button" onclick="mhCloseLightbox()" class="absolute top-4 right-4 text-white/80 hover:text-white" aria-label="Đóng">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        @if (count($mhPhotos) > 1)
            <button type="button" onclick="mhLightboxNav(-1)" class="absolute left-4 text-white/80 hover:text-white" aria-label="Ảnh trước">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button type="button" onclick="mhLightboxNav(1)" class="absolute right-4 text-white/80 hover:text-white" aria-label="Ảnh sau">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        @endif
        <img id="mh-lightbox-img" src="" alt="" class="max-h-[85vh] max-w-[85vw] object-contain rounded-lg">
        <div id="mh-lightbox-count" class="absolute bottom-4 text-white/80 text-sm"></div>
    </div>

    <script>
        var mhPhotos = @json($mhPhotos);
        var mhLightboxIdx = 0;

        function mhRenderLightbox() {
            if (!mhPhotos.length) return;
            document.getElementById('mh-lightbox-img').src = mhPhotos[mhLightboxIdx];
            document.getElementById('mh-lightbox-count').textContent = (mhLightboxIdx + 1) + ' / ' + mhPhotos.length;
        }

        function mhOpenLightbox(index) {
            if (!mhPhotos.length) return;
            mhLightboxIdx = index || 0;
            mhRenderLightbox();
            document.getElementById('mh-lightbox').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function mhCloseLightbox() {
            document.getElementById('mh-lightbox').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function mhLightboxNav(delta) {
            mhLightboxIdx = (mhLightboxIdx + delta + mhPhotos.length) % mhPhotos.length;
            mhRenderLightbox();
        }

        function mhShareRoom() {
            var url = window.location.href;
            var title = document.title;

            if (navigator.share) {
                navigator.share({ title: title, url: url }).catch(function () {});
                return;
            }

            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () {
                    alert('Đã sao chép đường dẫn phòng vào bộ nhớ tạm.');
                });
            }
        }

        document.addEventListener('keydown', function (e) {
            var box = document.getElementById('mh-lightbox');
            if (box.classList.contains('hidden')) return;
            if (e.key === 'Escape') mhCloseLightbox();
            if (e.key === 'ArrowLeft') mhLightboxNav(-1);
            if (e.key === 'ArrowRight') mhLightboxNav(1);
        });
    </script>
@endsection
