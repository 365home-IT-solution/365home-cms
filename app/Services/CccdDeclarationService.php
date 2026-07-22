<?php

namespace App\Services;

use App\Models\CccdDeclaration;
use Illuminate\Support\Carbon;
use Modules\Payment\Entities\Order;

class CccdDeclarationService
{
    // Toàn bộ khách lưu trú đều khai báo tại 1 địa bàn cố định của cơ sở (TP. Cần Thơ, Phường An
    // Bình) — theo yêu cầu: KHÔNG tự suy ra tỉnh/phường từ "Nơi thường trú" ghi trên CCCD của
    // khách nữa (thường không chính xác/không cùng nơi khách đang lưu trú thực tế, và việc quy đổi
    // địa giới cũ→mới nhiều trường hợp không suy luận được chính xác — xem lịch sử TbltAddressResolver).
    // Chỉ "Địa chỉ chi tiết" vẫn lấy nguyên văn từ CCCD như trước.
    private const DEFAULT_PROVINCE = '815 - TP. Cần Thơ';
    private const DEFAULT_WARD     = '815931150 - Phường An Bình';

    public function upsertFromOrder(Order $order): void
    {
        // Khách chính (người đặt phòng) — luôn khai báo (guest_index=1).
        $this->upsertGuest($order, 1, is_array($order->cccd_data) ? $order->cccd_data : [], $order->buyer_phone);

        // Khách thứ 2 — chỉ khai báo khi đã có dữ liệu CCCD quét được (đơn qua đêm 2 khách, xem
        // OrderForm.php phần "CCCD khách thứ 2"). Luật Cư trú yêu cầu khai báo ĐỦ TỪNG NGƯỜI lưu
        // trú qua đêm, không chỉ người đứng tên đặt phòng.
        if (! blank($order->cccd_data_2)) {
            $this->upsertGuest($order, 2, is_array($order->cccd_data_2) ? $order->cccd_data_2 : [], $order->buyer_phone_2);
        }
    }

    private function upsertGuest(Order $order, int $guestIndex, array $cccd, ?string $phone): void
    {
        $items    = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $category = $order->relationLoaded('category') ? $order->category : $order->category()->first();

        $gender    = $cccd['gender'] ?? null;
        $dob       = $cccd['dob'] ?? null;
        $genderOption = $this->mapGenderOption($gender);
        $infoParts = array_filter([$gender, $dob, 'Cộng hòa xã hội chủ nghĩa Việt Nam']);
        $info      = $infoParts ? implode(' / ', $infoParts) : null;

        // Đơn có thể có NHIỀU khung giờ (vd đặt 8h30-11h20, sau đó cập nhật thêm 11h50-14h40) —
        // "Ngày đến"/"Ngày đi dự kiến" phải lấy khung SỚM NHẤT → MUỘN NHẤT trong TOÀN BỘ đơn, không
        // phải chỉ item ĐẦU TIÊN trong collection (trước đây dùng items->first(), nên thêm khung
        // giờ sau vào đơn đã có sẵn không hề cập nhật lại "Ngày đi dự kiến" — vẫn giữ giờ của khung
        // đầu tiên, sai lệch với thực tế đã đặt). Cả 2 khách cùng ở CHUNG 1 phòng/lượt lưu trú nên
        // dùng chung mốc giờ này, không tính riêng cho từng người.
        $toDateTime = fn ($i) => $i->checkin_datetime ?? ($i->checkin_date ? Carbon::parse($i->checkin_date) : null);
        $toEndTime  = fn ($i) => $i->checkout_datetime ?? ($i->checkout_date ? Carbon::parse($i->checkout_date) : null);

        $checkinAt  = $items->map($toDateTime)->filter()->min();
        $checkoutAt = $items->map($toEndTime)->filter()->max();
        $item       = $items->sortBy($toDateTime)->first();

        // CccdScannerService trả về "Nơi thường trú" (đọc từ CCCD) dưới khoá 'address' — 1 chuỗi
        // địa chỉ đầy đủ, KHÔNG tách sẵn tỉnh/phường riêng. Đây là "Nơi thường trú" (đăng ký trên
        // CCCD), KHÁC với "Nơi ở hiện tại" thực tế của khách — chỉ có giá trị tham khảo, nhân viên
        // cần hỏi và sửa lại nếu biết nơi ở hiện tại chính xác hơn (form "Khai báo lưu trú" vẫn sửa
        // tay được các trường này).
        $residence = $cccd['address'] ?? null;

        // "Địa chỉ lưu trú" theo đúng nội dung Luật Cư trú yêu cầu là địa chỉ CHI NHÁNH/cơ sở lưu
        // trú (nơi khách đang ở), KHÔNG PHẢI địa chỉ nhà của khách — category->name ở dữ liệu này
        // chính là địa chỉ đầy đủ của chi nhánh (vd "252 Xuân Thủy, An Bình, Cần Thơ").
        $stayAddress = $category?->name;

        // reason_for_stay là trường CHỈ nhân viên tự điền tay (OCR/hệ thống không biết được) — phải
        // GIỮ NGUYÊN giá trị cũ nếu đã có. province/ward thì ưu tiên giữ giá trị nhân viên đã SỬA
        // TAY (nếu có), chỉ dùng kết quả tách tự động cho lần đầu/khi còn trống — tránh lần cập
        // nhật tự động sau (vd sửa đơn vì lý do khác — đổi ngày, đổi phòng...) xoá mất dữ liệu nhân
        // viên đã nhập hoặc ghi đè lại 1 kết quả tách tự động đã bị sửa vì tách sai. Lần đầu tạo
        // (chưa có $existing) thì mặc định "Nghỉ ngơi" — trường này bắt buộc theo Luật Cư trú, nếu
        // để trống thì đơn nào tạo ra cũng thiếu thông tin, chặn không đánh dấu "đã khai báo" được.
        $existing = CccdDeclaration::where('order_id', $order->id)->where('guest_index', $guestIndex)->first();

        CccdDeclaration::updateOrCreate(
            ['order_id' => $order->id, 'guest_index' => $guestIndex],
            [
                'full_name'         => $cccd['full_name'] ?? null,
                'info'              => $info ?: null,
                'date_of_birth'     => $dob,
                // Giới tính đọc được từ CCCD ("Nam"/"Nữ") — quy đổi sang đúng mã của mẫu ("M -
                // Nam"/"F - Nữ"); nếu chưa đọc được (chip lỗi...) thì giữ nguyên giá trị nhân viên
                // đã tự chọn tay trước đó, không ghi đè về rỗng.
                'gender'            => $genderOption ?: $existing?->gender,
                'cccd_number'       => $cccd['cccd'] ?? null,
                // Quốc tịch/loại giấy tờ: hệ thống chỉ quét CCCD Việt Nam nên mặc định cố định,
                // nhưng vẫn giữ giá trị nhân viên đã sửa tay (vd khách nước ngoài dùng hộ chiếu).
                'nationality'       => $existing?->nationality ?: CccdDeclaration::NATIONALITY_DEFAULT,
                'document_type'     => $existing?->document_type ?: '1 - Thẻ CCCD',
                'phone_number'      => filled($phone) ? $phone : $existing?->phone_number,
                'checked_in_at'     => $checkinAt,
                'checked_out_at'    => $checkoutAt,
                'room_number'       => $item?->name,
                'stay_address'      => $stayAddress,
                'reason_for_stay'   => $existing?->reason_for_stay ?: '1 - Du lịch',
                'custom_reason'     => $existing?->custom_reason,
                'current_residence' => $residence,
                // "Nơi cư trú hiện nay" là PHÂN LOẠI của current_residence — địa chỉ đọc từ CCCD
                // là nơi ĐĂNG KÝ thường trú nên mặc định "1 - Thường trú".
                'residence_type'    => $existing?->residence_type ?: '1 - Thường trú',
                'province'          => $existing?->province ?: self::DEFAULT_PROVINCE,
                'ward'              => $existing?->ward ?: self::DEFAULT_WARD,
                'address_detail'    => $residence,
                'notes'             => $existing?->notes,
            ]
        );
    }

    // Chuẩn hoá giá trị "gender" thô đọc từ CCCD (vd "Nam", "Nữ", "Male", "Female") về đúng mã
    // "M - Nam"/"F - Nữ" của mẫu tblt_vn_import.xlsx.
    private function mapGenderOption(?string $rawGender): ?string
    {
        if (blank($rawGender)) {
            return null;
        }

        return preg_match('/Nam|Male/iu', $rawGender)
            ? 'M - Nam'
            : (preg_match('/Nữ|Female/iu', $rawGender) ? 'F - Nữ' : null);
    }
}
