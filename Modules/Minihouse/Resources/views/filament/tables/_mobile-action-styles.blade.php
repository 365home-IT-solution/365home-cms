{{-- Dùng chung cho MỌI bảng MiniHouse — gắn qua ->header() (Table::header() chấp nhận View/Htmlable,
render thẳng ra <style>, xem Table\Concerns\HasHeader). Nút hành động theo dòng (Sửa/Xoá/...) mặc
định của Filament luôn hiện ĐỦ icon + CHỮ ("Chỉnh sửa"/"Xóa"...) — cố định khoảng 174px/dòng dù màn
hình to nhỏ, không nằm trong ->columns() nên KHÔNG được thu gọn bởi visibleFrom('md') như các cột đã
sửa. Đây là nguyên nhân THẬT khiến bảng vẫn cuộn ngang trên mobile dù mọi cột dữ liệu đã gọn — đã xác
nhận bằng Playwright đo trực tiếp: ô hành động rộng ~174px, ô chọn nhiều rộng ~44px, cộng với dòng thẻ
mobile mới làm tổng bề rộng vượt màn hình.

CSS dưới đây CHỈ ẩn phần CHỮ nhãn (giữ icon) của action mang class `mh-row-action`
(`->extraAttributes(['class' => 'mh-row-action'])`, gắn ở từng bảng) — và CHỈ áp dụng dưới 768px, nên
desktop không đổi 1 pixel nào. Dùng `>` (chỉ con trực tiếp) để không lỡ ẩn nhầm span khác (VD span
loading spinner nằm trong khối <svg>, không phải <span>). --}}
<style>
    @media (max-width: 767px) {
        .mh-row-action > span {
            display: none;
        }

        .mh-row-action {
            gap: 0 !important;
            padding-inline: 0.5rem !important;
        }
    }
</style>
