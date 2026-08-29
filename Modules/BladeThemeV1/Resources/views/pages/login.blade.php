@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    {{-- Trang đăng nhập/đăng ký: header luôn ở dạng thu gọn (bars menu, không hàng search đầy đủ)
         — xem cờ __headerAlwaysCompact trong header-main.blade.php. Đồng thời tắt hẳn banner
         quảng cáo app ("Có trải nghiệm tốt nhất", components/header/app-banner.blade.php) —
         không hợp ngữ cảnh trang đăng nhập/đăng ký gọn nhẹ. --}}
    <script>
        window.__headerAlwaysCompact = true;
        window.__appBannerDisabled = true;
    </script>
    {{-- hidden lg:block: trên mobile, trang đăng nhập không cần thanh header lẫn phần search (nằm
         trong chính header, hàng "mobile" của header-main.blade.php) — ẩn hẳn cả khối header (và
         drawer-menu đi kèm, vì nút mở nó cũng nằm trong header) dưới lg, chỉ hiện lại ở
         desktop/tablet lớn (>=lg), đúng breakpoint mà bản thân header dùng để tách mobile/desktop. --}}
    <div class="hidden lg:block">
        @livewire('bladethemev1::header')
        @livewire('bladethemev1::drawer-menu')
    </div>

    <h1 class="sr-only">Đăng nhập - 365 HOME</h1>

    {{-- 2 cột theo bố cục tham khảo Go2Joy: trái là ảnh (cột RỘNG HƠN, 3fr so với 2fr), phải là
         form không có padding ngang (chỉ padding dọc). max-w-7xl mx-auto: bọc cả khối trong khung
         giới hạn chiều rộng, canh giữa trang — trước đó 2 cột kéo full-bleed hết chiều rộng màn
         hình nên ở màn hình rộng, ảnh và form bị đẩy quá xa nhau (mỗi cột co giãn theo % của TOÀN
         BỘ viewport); giới hạn max-w-7xl giúp 2 cột nằm gần nhau hơn, giống đúng bố cục ảnh tham
         khảo (có lề trắng 2 bên, nội dung dồn vào giữa).
         lg:h-[calc(100vh-96px)] lg:min-h-0: ở desktop, header (đã bỏ hàng search) chỉ còn 1 hàng
         cao ~76-90px (tuỳ logo) — trừ đúng khoảng đó để tổng chiều cao trang khớp 100vh, tránh
         scroll dọc thừa (min-h-screen mặc định cộng thêm 100vh NGOÀI header, luôn dư ra). 96px lấy
         theo cùng mốc đã dùng ở booking-board.blade.php cho phần bù chiều cao header tương tự. --}}
    {{-- content-center md:content-stretch: dưới lg, canh GIỮA cả khối (ảnh + form) theo chiều dọc
         trong khoảng trống khả dụng — thay vì dồn lên đầu (content-start, để lại khoảng trắng lớn
         dưới form, ngay trên bottom-sidebar, nhìn mất cân đối). Ở tablet/desktop (md:content-stretch)
         vẫn cần hành vi giãn cũ để cột ảnh + form lấp đầy đúng lg:h-[calc(100vh-96px)].
         min-h-[calc(100dvh-76px)]: dưới lg, trừ đúng chiều cao bottom-sidebar cố định (~76px,
         position:fixed — xem bottom-sidebar.blade.php) để phần canh giữa không bị tính lố xuống
         dưới thanh đó (dvh thay vì vh: ổn định hơn trên mobile khi thanh địa chỉ trình duyệt
         ẩn/hiện lúc cuộn). KHÔNG thêm lg:min-h-screen ở đây (đã thử và gây lỗi scroll dọc ở
         desktop) — lg:min-h-screen và lg:min-h-0 cùng set thuộc tính min-height ở CÙNG breakpoint,
         thứ tự CSS do Tailwind sinh ra (không phải thứ tự viết trong class) khiến min-h-screen
         thắng, ép chiều cao tối thiểu thành 100vh dù đã có height:calc(100vh-96px) nhỏ hơn — thừa
         đúng 96px, sinh scrollbar. Không cần lg:min-h-screen: class min-h-[...] không prefix vẫn
         bị lg:min-h-0 ghi đè sạch ở lg+ (rule có prefix lg luôn đứng sau rule không prefix trong
         CSS Tailwind sinh ra), nên desktop vẫn đúng chiều cao khớp viewport trừ header như cũ. --}}
    <div class="min-h-[calc(100dvh-76px)] grid content-center md:content-stretch md:grid-cols-[3fr_2fr] max-w-7xl mx-auto lg:h-[calc(100vh-96px)] lg:min-h-0">
        {{-- Khối ảnh: 1 hình "wave" trang trí (SVG, absolute, canh giữa) nằm PHÍA SAU minh hoạ —
             hình dạng là 1 vòng tròn với biên dạng sóng/lượn (8 múi, tạo bằng bán kính dao động
             theo cos(8θ)) thay vì hình tròn phẳng trước đó. voucher.png trong suốt nên hình sóng
             sẽ "lộ" ra qua các khoảng trống quanh nhân vật/tag, tạo chiều sâu thay vì nền trắng
             phẳng. Thứ tự DOM quyết định lớp: SVG khai báo TRƯỚC (nằm dưới), lớp ảnh (absolute
             inset-0) khai báo SAU (nằm trên).
             background-size:contain (không phải cover) — cover phóng to ảnh để lấp đầy khung cao,
             cắt mất phần lớn minh hoạ (đầu/tay nhân vật, tag FLASH SALE bị cắt cụt) vì ảnh vốn có
             tỉ lệ khác khung. contain hiện trọn vẹn minh hoạ, không bị cắt, trông gọn hơn hẳn.
             ?v=filemtime: cache-busting theo thời điểm sửa file — nếu không, trình duyệt có thể
             tiếp tục hiện bản ảnh cũ đã cache (cùng URL) dù file trên server đã đổi. --}}
        {{-- h-72 md:h-auto: trên mobile, div này KHÔNG còn "hidden" (hiện đồng bộ với desktop,
             xếp phía TRÊN form vì đứng trước trong DOM và grid tự chuyển 1 cột dưới md). Nhưng vì
             cả SVG lẫn ảnh minh hoạ đều "position:absolute" (không góp chiều cao vào flow bình
             thường), div cha sẽ co về 0px nếu không có chiều cao cố định — h-72 cấp chiều cao rõ
             ràng cho mobile; md:h-auto trả lại hành vi cũ (tự giãn theo hàng grid, khớp form) ở
             tablet/desktop. --}}
        @php $voucherImgPath = public_path('images/voucher.png'); @endphp
        <div class="relative overflow-hidden h-72 md:h-auto">
            <svg class="absolute" viewBox="0 0 200 200" style="top:50%; left:50%; width:90%; aspect-ratio:1/1; transform:translate(-50%,-50%);" xmlns="http://www.w3.org/2000/svg">
                <path fill="#e0e7ff" d="M187.2,100.0L186.1,105.6L182.9,110.9L178.5,115.6L173.8,119.8L169.8,123.7L167.3,127.9L166.2,132.6L166.2,138.2L166.5,144.4L166.3,150.9L164.8,156.9L161.7,161.7L156.9,164.8L150.9,166.3L144.4,166.5L138.2,166.2L132.6,166.2L127.9,167.3L123.7,169.8L119.8,173.8L115.6,178.5L110.9,182.9L105.6,186.1L100.0,187.2L94.4,186.1L89.1,182.9L84.4,178.5L80.2,173.8L76.3,169.8L72.1,167.3L67.4,166.2L61.8,166.2L55.6,166.5L49.1,166.3L43.1,164.8L38.3,161.7L35.2,156.9L33.7,150.9L33.5,144.4L33.8,138.2L33.8,132.6L32.7,127.9L30.2,123.7L26.2,119.8L21.5,115.6L17.1,110.9L13.9,105.6L12.8,100.0L13.9,94.4L17.1,89.1L21.5,84.4L26.2,80.2L30.2,76.3L32.7,72.1L33.8,67.4L33.8,61.8L33.5,55.6L33.7,49.1L35.2,43.1L38.3,38.3L43.1,35.2L49.1,33.7L55.6,33.5L61.8,33.8L67.4,33.8L72.1,32.7L76.3,30.2L80.2,26.2L84.4,21.5L89.1,17.1L94.4,13.9L100.0,12.8L105.6,13.9L110.9,17.1L115.6,21.5L119.8,26.2L123.7,30.2L127.9,32.7L132.6,33.8L138.2,33.8L144.4,33.5L150.9,33.7L156.9,35.2L161.7,38.3L164.8,43.1L166.3,49.1L166.5,55.6L166.2,61.8L166.2,67.4L167.3,72.1L169.8,76.3L173.8,80.2L178.5,84.4L182.9,89.1L186.1,94.4Z" />
            </svg>
            <div class="absolute inset-0" style="background-image:url('{{ asset('images/voucher.png') }}?v={{ file_exists($voucherImgPath) ? filemtime($voucherImgPath) : 1 }}'); background-size:contain; background-repeat:no-repeat; background-position:center;"></div>
        </div>
        <div class="flex items-center justify-center py-12 px-8">
            <div class="w-full max-w-lg">
                <x-bladethemev1::auth.form mode="login" :primaryHex="$primaryColor" :textOnPrimary="$textOnPrimary" />
            </div>
        </div>
    </div>

    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
