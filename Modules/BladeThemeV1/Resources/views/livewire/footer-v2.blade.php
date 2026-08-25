{{--
    Footer "style 2" — giao diện tĩnh theo ảnh mẫu (4 cột: Hỗ trợ / Giới thiệu / Đối tác thanh
    toán / Tải ứng dụng + thanh dưới cùng + khối thông tin công ty). Thay thế footer cấu hình động
    (ThemeSection) làm mặc định — xem Modules/BladeThemeV1/Livewire/Footer.php::render().

    - Thông tin công ty lấy từ Business::first() (Settings > Thông tin công ty). Model không có
      field "người đại diện pháp luật" nên để trống/placeholder riêng dòng đó.
    - Cột "Đối tác thanh toán": logo VNPAY tại public/images/payment/vnpay.png
      (nguồn: file người dùng cung cấp).
    - QR tải app dùng API tạo QR công khai (không cần thêm package), trỏ thẳng Google Play (khớp
      link mặc định của app-banner.blade.php).
    - Huy hiệu "Đã thông báo Bộ Công Thương" tại public/images/bocongthuong.png (ảnh thật do
      người dùng cung cấp). Bỏ riêng "DMCA Protected" vì 365home chưa đăng ký.
--}}
@php
    /** @var \Modules\SettingCompany\Entities\Business|null $business */
    $playStoreUrl = 'https://play.google.com/store/apps/details?id=com.home365.app';
    $appStoreUrl = 'https://apps.apple.com/us/app/365-home/id6781598163';
    $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=0&data=' . urlencode($playStoreUrl);
@endphp

<footer class="border-t border-[#DDDDDD]" style="background-color:#F5F5F5;">
    <div class="max-w-11xl mx-auto px-4 md:px-8 py-10">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            {{-- Hỗ trợ --}}
            <div>
                <h3 class="text-base font-bold text-[#222222] mb-4">Hỗ trợ</h3>
                <ul class="space-y-3 text-sm text-[#4B5563]">
                    @if ($business?->phone)
                        <li class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 text-primary shrink-0">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                            </svg>
                            <a href="tel:{{ $business->phone }}" class="hover:text-primary transition-colors">Hotline: {{ $business->phone }}</a>
                        </li>
                    @endif
                    @if ($business?->email)
                        <li>
                            <a href="mailto:{{ $business->email }}" class="hover:text-primary transition-colors">Hỗ trợ khách hàng: {{ $business->email }}</a>
                        </li>
                        <li>
                            <a href="mailto:{{ $business->email }}" class="hover:text-primary transition-colors">Liên hệ hợp tác: {{ $business->email }}</a>
                        </li>
                    @endif
                    <li>
                        <a href="#" class="hover:text-primary transition-colors">Cơ chế giải quyết tranh chấp, khiếu nại</a>
                    </li>
                </ul>
            </div>

            {{-- Giới thiệu --}}
            <div>
                <h3 class="text-base font-bold text-[#222222] mb-4">Giới thiệu</h3>
                <ul class="space-y-3 text-sm text-[#4B5563]">
                    <li><a href="https://365home.vn/privacy" class="hover:text-primary transition-colors">Chính sách và bảo mật thông tin</a></li>
                    <li><a href="https://365home.vn/noi-quy-va-quy-dinh" class="hover:text-primary transition-colors">Nội quy và Quy định</a></li>
                    <li><a href="https://365home.vn/hinh-thuc-thanh-toan" class="hover:text-primary transition-colors">Hình thức thanh toán</a></li>
                    <li><a href="https://365home.vn/huong-dan-su-dung" class="hover:text-primary transition-colors">Hướng dẫn sử dụng</a></li>
                    <li><a href="https://365home.vn/huong-dan-tu-check-in" class="hover:text-primary transition-colors">Hướng dẫn tự Check in</a></li>
                </ul>
            </div>

            {{-- Đối tác thanh toán --}}
            <div>
                <h3 class="text-base font-bold text-[#222222] mb-4">Đối tác thanh toán</h3>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center justify-center h-11">
                        <img src="{{ asset('images/payment/vnpay.png') }}" alt="VNPAY" class="max-w-full max-h-full object-contain">
                    </span>
                </div>
            </div>

            {{-- Tải ứng dụng --}}
            <div>
                <h3 class="text-base font-bold text-[#222222] mb-4">Tải ứng dụng</h3>
                <div class="flex items-start gap-3">
                    <img src="https://365home.vn/storage/977/qr-footer.png" alt="QR tải ứng dụng 365Home" width="90" height="90" class="rounded-lg border border-[#DDDDDD] bg-white p-1" loading="lazy">
                    <div class="flex flex-col gap-2">
                        <a href="{{ $appStoreUrl }}" target="_blank" rel="noopener">
                            <img src="{{ asset('images/applestore.png') }}" alt="Tải trên App Store" class="h-9 w-auto" width="298" height="96">
                        </a>
                        <a href="{{ $playStoreUrl }}" target="_blank" rel="noopener">
                            <img src="{{ asset('images/googleplay.png') }}" alt="Tải trên Google Play" class="h-9 w-auto" width="298" height="96">
                        </a>
                    </div>
                </div>

                <a href="http://online.gov.vn/Home/WebDetails/140984" target="_blank" rel="noopener" class="inline-block mt-4">
                    <img src="{{ asset('images/bocongthuong.webp') }}" alt="Đã thông báo Bộ Công Thương" class="h-14 w-auto">
                </a>
            </div>
        </div>
    </div>

    {{-- Thanh dưới: copyright + link + mạng xã hội --}}
    <div class="border-t border-[#DDDDDD]">
        <div class="max-w-11xl mx-auto px-4 md:px-8 py-5 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="text-sm text-[#6B7280] text-center md:text-left">
                Copyright © {{ date('Y') }} {{ $business?->name ?? '365Home' }}
                <span class="mx-1.5">·</span>
                <a href="#" class="hover:text-primary transition-colors">Điều khoản</a>
                <span class="mx-1.5">·</span>
                <a href="#" class="hover:text-primary transition-colors">Bảo mật</a>
                <span class="mx-1.5">·</span>
                <a href="#" class="hover:text-primary transition-colors">Quy định đăng tin</a>
                <span class="mx-1.5">·</span>
                <a href="{{ route('sitemap') }}" class="hover:text-primary transition-colors">Sơ đồ trang web</a>
            </div>

            <div class="flex items-center gap-3 text-[#6B7280]">
                <a href="https://www.facebook.com/365home.254xuanthuy.cantho" target="_blank" rel="noopener" aria-label="Facebook" class="flex items-center justify-center hover:text-primary transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                        <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.89h2.78l-.44 2.91h-2.34V22c4.78-.76 8.44-4.92 8.44-9.94z"/>
                    </svg>
                </a>
                <a href="#" target="_blank" rel="noopener" aria-label="Instagram" class="flex items-center justify-center hover:text-primary transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-6 h-6">
                        <rect x="2" y="2" width="20" height="20" rx="5"/>
                        <circle cx="12" cy="12" r="4"/>
                        <circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>
                    </svg>
                </a>
                <a href="https://www.tiktok.com/@365.home" target="_blank" rel="noopener" aria-label="TikTok" class="flex items-center justify-center hover:text-primary transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                        <path d="M16.6 5.82c-.9-.98-1.4-2.26-1.4-3.63h-3.16v13.5c0 1.48-1.2 2.68-2.68 2.68a2.68 2.68 0 0 1 0-5.36c.27 0 .53.04.78.12V9.9a5.85 5.85 0 0 0-.78-.05A5.86 5.86 0 0 0 3.5 15.7a5.86 5.86 0 0 0 5.86 5.86 5.86 5.86 0 0 0 5.86-5.86V9.14a8.4 8.4 0 0 0 4.9 1.57V7.55a4.85 4.85 0 0 1-3.52-1.73z"/>
                    </svg>
                </a>
                <a href="#" target="_blank" rel="noopener" aria-label="YouTube" class="flex items-center justify-center hover:text-primary transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
                        <path d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.51 3.5 12 3.5 12 3.5s-7.51 0-9.38.55A3.02 3.02 0 0 0 .5 6.19 31.6 31.6 0 0 0 0 12a31.6 31.6 0 0 0 .5 5.81 3.02 3.02 0 0 0 2.12 2.14C4.49 20.5 12 20.5 12 20.5s7.51 0 9.38-.55a3.02 3.02 0 0 0 2.12-2.14A31.6 31.6 0 0 0 24 12a31.6 31.6 0 0 0-.5-5.81zM9.6 15.6V8.4l6.4 3.6-6.4 3.6z"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>

    {{-- Khối thông tin công ty --}}
    @if ($business)
        <div>
            <div class="max-w-11xl mx-auto px-4 md:px-8 pb-8 text-center text-sm text-[#6B7280] space-y-1.5">
                <p class="font-bold text-[#222222] uppercase tracking-wide">{{ $business->name }}</p>
                @if ($business->address)
                    <p>Địa chỉ trụ sở: {{ $business->address }}</p>
                @endif
                @if ($business->tax_code)
                    <p>Mã số thuế: {{ $business->tax_code }}</p>
                @endif
            </div>
        </div>
    @endif
</footer>
