{{-- Ẩn Ô LƯỚI bọc ngoài section đang bị lọc (không chỉ section) để các nhóm còn lại dồn gọn, không để khoảng trống.
     Section chỉ ẩn bằng CSS (không dùng ->hidden()) để quyền đã tick ở nhóm đang ẩn vẫn được lưu. --}}
<style>
    div:has(> [data-perm-hidden]) { display: none !important; }
</style>
