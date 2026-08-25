{{--
    Skeleton hiện khi Livewire đang tải dữ liệu lịch đặt phòng (đổi chi nhánh — loadBranch(), hoặc
    đổi tab danh mục — setActiveCategoryTab()) — thay cho việc chỉ làm mờ opacity nội dung cũ, tạo
    cảm giác mượt/có phản hồi ngay thay vì đứng hình chờ. Dùng wire:loading.flex/.remove ở
    book.blade.php để ẩn/hiện khối này thay chỗ nội dung thật trong lúc chờ.
--}}
<div class="book-skeleton">
    <div class="book-skeleton-legend">
        <span class="book-skel book-skeleton-pill"></span>
        <span class="book-skel book-skeleton-pill"></span>
        <span class="book-skel book-skeleton-pill"></span>
        <span class="book-skel book-skeleton-pill"></span>
    </div>

    {{-- Mobile (< lg): 1 card, cột ngày + cột khung giờ --}}
    <div class="lg:hidden book-skeleton-mobile">
        <div class="book-skel book-skeleton-mobile-header"></div>
        <div class="book-skeleton-mobile-body">
            <div class="book-skeleton-dates-col">
                @for ($i = 0; $i < 8; $i++)
                    <span class="book-skel book-skeleton-row"></span>
                @endfor
            </div>
            <div class="book-skeleton-slots-col">
                @for ($i = 0; $i < 8; $i++)
                    <span class="book-skel book-skeleton-row"></span>
                @endfor
            </div>
        </div>
    </div>

    {{-- Desktop (>= lg): cột ngày + 3 cột phòng, giống bố cục .book-dt-body thật --}}
    <div class="hidden lg:flex book-skeleton-desktop">
        <div class="book-skeleton-dates-col-lg">
            <span class="book-skel book-skeleton-col-header-lg"></span>
            @for ($i = 0; $i < 9; $i++)
                <span class="book-skel book-skeleton-row-lg"></span>
            @endfor
        </div>
        @for ($r = 0; $r < 3; $r++)
            <div class="book-skeleton-room-col">
                <span class="book-skel book-skeleton-room-title"></span>
                @for ($i = 0; $i < 9; $i++)
                    <span class="book-skel book-skeleton-row-lg"></span>
                @endfor
            </div>
        @endfor
    </div>
</div>
