{{--
 * VIDEO PHÒNG — component
 * Chỉnh URL, tỉ lệ, tiêu đề SEO → vào admin (Filament) > Sản phẩm > Video phòng
 * Chỉnh giao diện nút / modal → file này:
 *   Modules/BladeV1Theme/Resources/views/components/product-detail/video-room.blade.php
 *
 * Biến nhận vào (từ @include):
 *   $product  — Eloquent Product model (bắt buộc)
--}}
@php
    $videoSetting   = is_array($product->setting_video_room) ? $product->setting_video_room : [];
    $rawVideoUrl    = trim($videoSetting['url'] ?? '');
    $videoRatio     = $videoSetting['ratio'] ?? '16:9';
    $videoSeoTitle  = $videoSetting['title'] ?? ($product->name . ' - Video phòng');
@endphp

@if(!empty($rawVideoUrl))
@php
    // ─── Tỉ lệ → kích thước modal ───────────────────────────────────────────
    // Muốn thêm tỉ lệ mới: thêm 1 dòng vào $ratioMap bên dưới
    // và thêm option tương ứng trong Filament ProductForm (createVideoSection)
    $ratioMap = [
        '16:9' => ['width' => 'min(92vw,960px)', 'padding' => '56.25%'],
        '9:16' => ['width' => 'min(55vh,380px)', 'padding' => '177.78%'],
        '4:3'  => ['width' => 'min(85vw,800px)', 'padding' => '75%'],
    ];
    $videoModalMaxWidth  = $ratioMap[$videoRatio]['width']   ?? $ratioMap['16:9']['width'];
    $videoAspectRatioPct = $ratioMap[$videoRatio]['padding'] ?? $ratioMap['16:9']['padding'];

    // ─── URL → embed URL ─────────────────────────────────────────────────────
    if (preg_match('/youtube\.com\/shorts\/([\w\-]{11})/', $rawVideoUrl, $m)) {
        $embedVideoUrl = 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1&rel=0';
        $isEmbed = true;
    } elseif (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w\-]{11})/', $rawVideoUrl, $m)) {
        $embedVideoUrl = 'https://www.youtube.com/embed/' . $m[1] . '?autoplay=1&rel=0';
        $isEmbed = true;
    } elseif (str_contains($rawVideoUrl, 'youtube.com/embed/')) {
        $embedVideoUrl = $rawVideoUrl;
        $isEmbed = true;
    } elseif (preg_match('/tiktok\.com.*\/video\/(\d+)/', $rawVideoUrl, $m)) {
        $embedVideoUrl = 'https://www.tiktok.com/embed/v2/' . $m[1];
        $isEmbed = true;
    } elseif (preg_match('/facebook\.com/', $rawVideoUrl)) {
        $embedVideoUrl = 'https://www.facebook.com/plugins/video.php?href=' . urlencode($rawVideoUrl) . '&show_text=false&autoplay=true&width=1280';
        $isEmbed = true;
    } else {
        // Video file trực tiếp (.mp4, .webm...)
        $embedVideoUrl = $rawVideoUrl;
        $isEmbed = false;
    }

    // ─── SEO VideoObject JSON-LD ──────────────────────────────────────────────
    $videoSchemaJson = json_encode([
        '@context'     => 'https://schema.org',
        '@type'        => 'VideoObject',
        'name'         => $videoSeoTitle,
        'description'  => mb_substr(strip_tags($product->short_description ?? $product->description ?? $product->name ?? ''), 0, 200),
        'contentUrl'   => $rawVideoUrl,
        'embedUrl'     => $embedVideoUrl,
        'thumbnailUrl' => $product->getFirstMediaUrl('Ảnh bìa'),
        'uploadDate'   => optional($product->created_at)->toIso8601String(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

<div x-data="{ videoOpen: false }"
     @pd-open-video.window="videoOpen = true"
     @if(!empty($videoButtonInGallery)) class="mb-0" @else class="mb-4" @endif>

    {{-- Nút mở riêng (chỉ hiển thị khi không có nút trong gallery) --}}
    @if(empty($videoButtonInGallery))
    <button
        type="button"
        @click="videoOpen = true"
        class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 hover:scale-105 hover:shadow-lg"
        style="background: var(--color-primary); box-shadow: 0 3px 12px rgba(var(--color-primary-rgb),.35);">
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
            <circle cx="12" cy="12" r="10" fill="rgba(255,255,255,.2)"/>
            <polygon points="10,8 10,16 17,12" fill="white"/>
        </svg>
        Xem video phòng
    </button>
    @endif

    {{-- ── Modal video (teleport ra body để tránh z-index bị cắt) ─────────── --}}
    <template x-teleport="body">
    <div
        x-show="videoOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @keydown.escape.window="videoOpen = false"
        style="position:fixed;inset:0;z-index:10001;">

        {{-- Backdrop --}}
        <div
            style="position:absolute;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(6px);"
            @click="videoOpen = false">
        </div>

        {{-- Nút đóng --}}
        <button
            @click="videoOpen = false"
            type="button"
            style="position:fixed;top:4px;right:13px;z-index:10010;background:rgba(0,0,0,.6);border:2px solid rgba(255,255,255,.5);color:#fff;font-size:28px;line-height:1;cursor:pointer;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:background .2s;"
            onmouseover="this.style.background='rgba(0,0,0,.85)'"
            onmouseout="this.style.background='rgba(0,0,0,.6)'">
            &times;
        </button>

        {{-- Tiêu đề --}}
        <div style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10009;color:rgba(255,255,255,.9);font-size:15px;font-weight:700;white-space:nowrap;text-shadow:0 1px 6px rgba(0,0,0,.5);pointer-events:none;">
            {{ $product->name }}
        </div>

        {{-- Khung video — căn giữa bằng transform, không dùng flex để tránh Alpine ghi đè display --}}
        <div @click.stop
             style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;width:{{ $videoModalMaxWidth }};max-width:calc(100vw - 32px);">
            <div style="position:relative;padding-bottom:{{ $videoAspectRatioPct }};height:0;border-radius:16px;overflow:hidden;box-shadow:0 12px 60px rgba(0,0,0,.7);">
                @if($isEmbed)
                    <iframe
                        x-show="videoOpen"
                        :src="videoOpen ? '{{ $embedVideoUrl }}' : ''"
                        title="{{ e($videoSeoTitle) }}"
                        style="position:absolute;inset:0;width:100%;height:100%;border:none;"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen>
                    </iframe>
                @else
                    <video
                        x-show="videoOpen"
                        :src="videoOpen ? '{{ $embedVideoUrl }}' : ''"
                        controls autoplay
                        style="position:absolute;inset:0;width:100%;height:100%;border-radius:16px;">
                    </video>
                @endif
            </div>
        </div>
    </div>
    </template>
</div>

{{-- SEO: VideoObject structured data --}}
<script type="application/ld+json">{!! $videoSchemaJson !!}</script>

@endif
