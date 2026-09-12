# MiniHouse — Báo cáo dự án

Module quản lý cho thuê phòng theo tháng (nhà trọ/chung cư mini), tách biệt hoàn toàn với hệ thống
đặt phòng theo giờ/ngày của "Home". Có 2 nhóm người dùng: nhân viên/chủ nhà (panel quản trị riêng) và
khách thuê (Cổng thông tin Portal riêng, không dùng chung tài khoản với nhân viên).

## Luồng hoạt động từ đầu đến cuối

1. **Chuẩn bị** — nhân viên tạo Toà nhà, khai Phòng theo đúng mặt bằng thật.
2. **Có khách mới** — tạo hồ sơ Khách thuê, lập Hợp đồng gắn với 1 phòng đang trống. Phòng tự
   chuyển sang "Đang thuê".
3. **Hàng tháng, hệ thống lập Hoá đơn** (tự động hoặc nhân viên bấm) cho từng hợp đồng — lúc này
   hoá đơn thường còn thiếu số điện/nước.
4. **Nhân viên bổ sung số điện/nước** — khi đủ, hoá đơn tự hiện lên Cổng thông tin và tự báo cho
   khách.
5. **Khách xem hoá đơn và thanh toán** trên Cổng thông tin (quét QR/chuyển khoản).
6. **Thanh toán được xác nhận** — tự động qua cổng thanh toán, hoặc nhân viên ghi nhận rồi chủ nhà
   duyệt. Hoá đơn chuyển "Đã thanh toán", tiền tự ghi vào sổ Thu Chi.
7. **Xuyên suốt quá trình**: hệ thống tự nhắc nhân viên việc quá hạn (thu tiền, khai báo tạm trú,
   hợp đồng sắp hết hạn) và tự lưu lại mọi thay đổi để tra cứu sau này.
8. **Kết thúc hợp đồng** — tuỳ tình huống: gia hạn (thuê tiếp), chuyển phòng (đổi phòng khác), hoặc
   thanh lý/huỷ (trả phòng, hoàn cọc nếu còn). Phòng tự trở về "Trống", sẵn sàng cho khách kế tiếp.

Bảng dưới liệt kê từng module: dùng để làm gì, có những dữ liệu chính nào, và liên quan tới module
nào khác trong hệ thống.

| Module | Dùng để làm gì | Dữ liệu chính | Liên quan module nào |
|---|---|---|---|
| **1. Khu vực & Toà nhà** | Đơn vị quản lý cao nhất; nhóm nhiều toà theo khu vực địa lý (tuỳ chọn), giữ cấu hình vận hành riêng của từng toà (giá điện/nước, thanh toán, lịch nhắc). | Khu vực: tên, ghi chú. Toà nhà: tên, địa chỉ, đơn giá điện/nước, thông tin chủ nhà + ngân hàng, cấu hình PayOS/MoMo/VNPay, phương thức thanh toán đang bật, kiểu chu kỳ tính tiền, các mốc ngày nhắc. | Phòng, Hợp đồng, Hoá đơn (đều thuộc 1 toà) · Phân quyền (giới hạn theo toà được gán). |
| **2. Phân quyền & Tài khoản nhân viên** | Kiểm soát nhân viên nào được xem/sửa dữ liệu của toà nào. | Tài khoản (dùng chung với Home), quyền theo 11 nhóm resource × 4 quyền (xem/tạo/sửa/xoá) + 4 quyền đặc biệt, bảng gán Tài khoản–Toà nhà/Khu vực. | Giới hạn phạm vi truy cập của mọi module còn lại. |
| **3. Phòng** | Đơn vị cho thuê nhỏ nhất; vẽ sơ đồ mặt bằng thật (hàng/cột/tầng) trên Dashboard. | Mã phòng, tầng, vị trí hàng/cột, diện tích, giá, trạng thái (trống/đã đặt cọc/đang thuê/đã khoá), ảnh, tiện ích. | Hợp đồng (gắn 1 phòng, trạng thái phòng tự đổi theo hợp đồng). |
| **4. Khách thuê** | Hồ sơ cá nhân người thuê — tách riêng khỏi Hợp đồng vì 1 khách có thể có nhiều hợp đồng theo thời gian. | Họ tên, SĐT, CCCD + ảnh, ngày sinh, giới tính, quê quán, liên hệ khẩn cấp, mật khẩu đăng nhập Portal (tuỳ chọn). | Hợp đồng (người đứng tên chính/người ở cùng) · Portal (đăng nhập bằng hồ sơ này). |
| **5. Hợp đồng** | Trung tâm nghiệp vụ — gắn 1 khách với 1 phòng, là gốc để lập hoá đơn hàng tháng. | Phòng, khách thuê chính, ngày bắt đầu/kết thúc, giá thuê, tiền cọc, trạng thái (đang hiệu lực/hết hạn/đã huỷ), đơn giá điện/nước riêng, lịch sử gia hạn. | Phòng, Khách thuê, Hoá đơn, Phụ thu, Nhắc việc. |
| **6. Phụ thu** | Phí định kỳ ngoài tiền phòng/điện/nước (gửi xe, internet...), khai theo từng toà rồi gắn vào hợp đồng cần thu. | Toà nhà, tên phí, số tiền, đang áp dụng hay không. | Hợp đồng (gắn nhiều-nhiều) · Hoá đơn (chụp lại thành dòng chi tiết mỗi lần lập). |
| **7. Hoá đơn** | Chứng từ thu tiền hàng tháng cho 1 hợp đồng. | Tháng, kỳ, tiền phòng, chỉ số + đơn giá điện/nước, tổng phụ thu, tổng tiền, trạng thái/đã thu (tự đồng bộ từ thanh toán). | Hợp đồng, Thanh toán, Thu Chi, Portal, Nhắc việc. |
| **8. Thanh toán & Cổng thanh toán** | Ghi nhận thu tiền cho 1 hoá đơn — thủ công (tiền mặt/chuyển khoản) hoặc tự động qua 4 cổng điện tử (VietQR/PayOS/MoMo/VNPay). | Số tiền, ngày thanh toán, phương thức, trạng thái (chờ duyệt/đã duyệt), người duyệt. | Hoá đơn, Thu Chi · Portal (khách tự thanh toán). |
| **9. Thu Chi** | Sổ quỹ tổng hợp mọi khoản tiền vào/ra thực tế theo từng toà. | Toà nhà, loại (thu/chi), hạng mục, số tiền, ngày giao dịch, ghi chú, ảnh biên lai. | Thanh toán (tự sinh dòng Thu) · Hợp đồng (hoàn cọc tự sinh dòng Chi) · Dashboard/Báo cáo tài chính. |
| **10. Nhắc việc & Thông báo tự động** | Nhắc nhân viên việc quá hạn (thu tiền, hết hạn hợp đồng, bảo trì); báo khách qua nhiều kênh (chuông, Zalo, SMS, Portal). | Tiêu đề, nội dung, ngày nhắc, loại, gắn phòng/hợp đồng/hoá đơn (tuỳ chọn), người phụ trách, đã xong hay chưa. | Hoá đơn, Hợp đồng, Portal. |
| **11. Khai báo lưu trú** | Hỗ trợ khai báo tạm trú theo Luật Cư trú — chỉ lưu tham chiếu nội bộ, không tự nộp cơ quan chức năng. | Thông tin cá nhân khách, ngày đến/đi, địa chỉ lưu trú, nơi thường trú, trạng thái đã khai báo. | Hợp đồng, Khách thuê. |
| **12. Thông báo nội bộ (Announcement)** | Gửi 1 tin nhắn hàng loạt tới khách thuê của 1 toà hoặc toàn hệ thống. | Toà nhà (để trống = gửi tất cả), tiêu đề, nội dung, người tạo. | Hợp đồng (xác định người nhận) · Portal (nơi khách nhận thông báo). |
| **13. Cổng thông tin khách thuê (Portal)** | Khách tự tra cứu hợp đồng/hoá đơn, thanh toán trực tuyến, gửi phản hồi — có bản Web (session) và API (app di động), dùng chung 1 nguồn logic. | Đăng nhập bằng SĐT (OTP hoặc mật khẩu), dữ liệu hiển thị đọc từ Hợp đồng/Hoá đơn/Thanh toán, thông báo riêng của khách. | Khách thuê, Hợp đồng, Hoá đơn, Thanh toán, Nhắc việc, Announcement. |
| **14. Nhật ký hoạt động** | Ghi lại tự động mọi thao tác tạo/sửa/xoá để tra cứu sau này (ai, lúc nào, giá trị cũ/mới). | Loại đối tượng, hành động, người thực hiện, giá trị cũ/mới (đã dịch tiếng Việt, khoá ngoại hiện tên thay vì ID). | Bao phủ 12 module có dữ liệu nghiệp vụ. |
| **15. Dashboard & Báo cáo tài chính** | Trang tổng quan khi đăng nhập — sơ đồ phòng, việc cần làm, số liệu vận hành theo khoảng thời gian. | Không có dữ liệu riêng — tổng hợp trực tiếp từ Hợp đồng/Hoá đơn/Thu Chi/Phòng. | Hợp đồng, Hoá đơn, Thu Chi, Phòng. |
| **16. Tác vụ chạy nền theo lịch (Cron)** | Tự động hoá vận hành hàng ngày (đồng bộ trạng thái phòng, lập hoá đơn, nhắc việc, nhắc khai báo lưu trú) mà không cần thao tác tay. | Không có dữ liệu riêng — chạy trên dữ liệu Hợp đồng/Hoá đơn/Nhắc việc/Khai báo lưu trú. | Hợp đồng, Hoá đơn, Nhắc việc, Khai báo lưu trú. |

Tài liệu API chi tiết (từng endpoint, request/response mẫu) xem ở file riêng "MiniHouse — Tài liệu API".
