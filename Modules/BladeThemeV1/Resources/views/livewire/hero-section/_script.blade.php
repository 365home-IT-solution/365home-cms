    {{-- @include('...hero-section._script') is called 3x within hero-section.blade.php itself
         (banner-form/compact-form/header-row variants), and hero-section can also be mounted more
         than once on the same page (e.g. home page's overlay instance) — without @once this exact
         same <script> block (window.parseLocationSlugFromUrl, heroDatePicker, etc.) was being
         printed/parsed/executed redundantly on every include, up to 4x on some pages (flagged by
         PageSpeed's "Minify JavaScript" audit as 4 near-identical inline chunks). @once's dedupe
         key is tied to this compiled file, so it collapses correctly across all call sites and all
         component instances in the same request — the functions are idempotent overwrites anyway,
         so only the LAST assignment mattered; now there's only ever one. --}}
    @once('hero-section-inline-script')
    {{-- defer: file chỉ định nghĩa hàm window.* dùng trong x-data của Alpine — Alpine khởi động
         từ livewire.min.js (cũng defer, nằm SAU trong document) nên thứ tự chạy vẫn đảm bảo, mà
         không còn chặn parse HTML giữa <body> (Lighthouse "Avoid chaining critical requests"). --}}
    <script src="{{ asset('js/hero-section.min.js') }}?v={{ filemtime(public_path('js/hero-section.min.js')) }}" defer></script>
    @endonce
