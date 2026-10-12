{{-- Kỳ thống kê dùng chung với Tổng quan (nút lọc kỳ ở đầu trang) nên ở đây không có ô chọn kỳ riêng. $extra (HtmlString, tuỳ chọn): ô tìm kiếm / chọn đối tác. --}}
@if (! empty((string) ($extra ?? '')))
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
        {{ $extra }}
    </div>
@endif
