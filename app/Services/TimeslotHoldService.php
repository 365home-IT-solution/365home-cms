<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TimeslotHeld;
use App\Events\TimeslotReleased;
use App\Models\TimeslotHold;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Giữ chỗ tạm thời (vài phút) cho 1 ô "khung giờ + ngày" khi admin đang thao tác chọn trên lưới
// đặt phòng — mục đích CHỈ để tránh 2 admin cùng chọn trùng 1 khung giờ trong lúc đang xử lý đơn,
// KHÔNG thay thế kiểm tra "đã đặt" thật sự (OrderItem::checkin_date/checkout_date, xem
// OrderForm::getTimeslotGridData()) — hold hết hạn tự động (TTL) nếu admin đóng tab / mất kết nối
// giữa chừng mà không có cách nào chắc chắn bắt được sự kiện "rời trang" từ trình duyệt.
class TimeslotHoldService
{
    private const TTL_MINUTES = 5;

    // Giữ chỗ 1 ô — trả về null nếu giữ thành công (hoặc chính user này đang giữ sẵn), trả về
    // TimeslotHold của người KHÁC nếu ô đang bị người đó giữ (chưa hết hạn).
    public function hold(int $roomTimeSlotId, string $date, User $user, string $productId): ?TimeslotHold
    {
        $this->purgeExpired();

        return DB::transaction(function () use ($roomTimeSlotId, $date, $user, $productId) {
            $existing = TimeslotHold::where('room_time_slot_id', $roomTimeSlotId)
                ->where('date', $date)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->user_id !== $user->id && ! $existing->isExpired()) {
                return $existing;
            }

            TimeslotHold::updateOrCreate(
                ['room_time_slot_id' => $roomTimeSlotId, 'date' => $date],
                ['user_id' => $user->id, 'expires_at' => now()->addMinutes(self::TTL_MINUTES)]
            );

            $timeslotId = \Modules\Product\App\Models\RoomTimeSlot::find($roomTimeSlotId)?->timeslot_id;

            if ($timeslotId) {
                $this->safeBroadcast(new TimeslotHeld(
                    $productId,
                    $roomTimeSlotId,
                    $timeslotId,
                    $date,
                    $user->id,
                    $user->fullname ?? $user->email
                ));
            }

            return null;
        });
    }

    // Bỏ giữ 1 ô — CHỈ khi đúng người đang giữ (bỏ chọn ô khác không được phép giải phóng hold của
    // người khác).
    public function release(int $roomTimeSlotId, string $date, User $user, string $productId): void
    {
        $deleted = TimeslotHold::where('room_time_slot_id', $roomTimeSlotId)
            ->where('date', $date)
            ->where('user_id', $user->id)
            ->delete();

        if (! $deleted) {
            return;
        }

        $timeslotId = \Modules\Product\App\Models\RoomTimeSlot::find($roomTimeSlotId)?->timeslot_id;

        if ($timeslotId) {
            $this->safeBroadcast(new TimeslotReleased($productId, $roomTimeSlotId, $timeslotId, $date));
        }
    }

    // Bỏ TOÀN BỘ hold của 1 admin — gọi khi đơn đã lưu xong (khung giờ giờ đã thành OrderItem thật,
    // không cần giữ tạm nữa) hoặc khi rời trang tạo/sửa đơn.
    public function releaseAllForUser(User $user): void
    {
        $holds = TimeslotHold::where('user_id', $user->id)->with('roomTimeSlot')->get();

        TimeslotHold::where('user_id', $user->id)->delete();

        foreach ($holds as $hold) {
            $productId = $hold->roomTimeSlot?->room_id;

            if ($productId) {
                $this->safeBroadcast(new TimeslotReleased(
                    $productId,
                    $hold->room_time_slot_id,
                    $hold->roomTimeSlot->timeslot_id,
                    $hold->date->format('Y-m-d')
                ));
            }
        }
    }

    // broadcast() gọi thẳng tới Reverb qua HTTP (ShouldBroadcastNow, không qua hàng đợi) — nếu
    // server Reverb chưa chạy/mất kết nối, KHÔNG được để lỗi này làm hỏng luồng đặt phòng thật sự
    // (giữ chỗ vẫn phải thành công trong DB dù real-time không tới được nơi khác kịp).
    //
    // CỐ Ý KHÔNG dùng ->toOthers() — nó cần header X-Socket-ID hợp lệ (Echo phải đã kết nối xong
    // TRƯỚC khi request này gửi đi) để biết loại trừ đúng tab đang gửi. Ngay cú click ĐẦU TIÊN sau
    // khi tải trang, Echo thường CHƯA kết nối xong kịp → header rỗng/"undefined" → Reverb ném lỗi
    // "Invalid socket ID undefined", broadcast() thất bại HOÀN TOÀN (không tới được bất kỳ tab nào),
    // đây chính là lý do tab khác chỉ thấy cập nhật khi TỰ họ thao tác/refresh (F5 render lại từ DB
    // vẫn đúng, chỉ có phần PUSH tức thời bị lỗi). Bỏ toOthers() thì gửi tới TẤT CẢ tab kể cả tab
    // vừa bấm — vô hại, vì tab đó tự re-render lại với cùng dữ liệu (không có gì đổi khác).
    private function safeBroadcast($event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('TimeslotHoldService: broadcast thất bại (Reverb server có thể chưa chạy)', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Map "room_time_slot_id|date" => tên admin đang giữ (loại trừ chính $user hiện tại) — dùng để
    // tô đỏ/khóa ô trên lưới ở OrderForm::getTimeslotGridData().
    public function getActiveHoldsForProduct(string $productId, User $excludingUser): Collection
    {
        $this->purgeExpired();

        return TimeslotHold::query()
            ->whereHas('roomTimeSlot', fn ($q) => $q->where('room_id', $productId))
            ->where('user_id', '!=', $excludingUser->id)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (TimeslotHold $hold) => [
                $hold->room_time_slot_id . '|' . $hold->date->format('Y-m-d') => $hold->user->fullname ?? $hold->user->email ?? 'Admin khác',
            ]);
    }

    // Kiểm tra 1 ô có đang bị ADMIN giữ chỗ real-time không — dùng ở luồng đặt phòng CỦA KHÁCH
    // HÀNG (client, xem ProductDetail::checkSlotConflicts()) để chặn khách đặt trùng đúng lúc admin
    // đang xử lý khung giờ này cho 1 đơn khác (kể cả đơn đó CHƯA lưu xong, chưa có OrderItem thật
    // nên check "đã đặt" bình thường không thấy được).
    public function isHeldByAdmin(int $roomTimeSlotId, string $date): ?TimeslotHold
    {
        $this->purgeExpired();

        return TimeslotHold::where('room_time_slot_id', $roomTimeSlotId)
            ->where('date', $date)
            ->with('user')
            ->first();
    }

    // CỐ Ý không broadcast — hàm này chỉ dùng nội bộ trước mỗi thao tác hold/check để đảm bảo
    // không đọc phải hold đã hết hạn, KHÔNG phải nơi duy nhất dọn hold hết hạn (xem
    // releaseExpiredHolds() bên dưới, chạy định kỳ qua scheduler — có broadcast đầy đủ).
    private function purgeExpired(): void
    {
        TimeslotHold::where('expires_at', '<', now())->delete();
    }

    // Dọn hold đã hết hạn TTL mà KHÔNG ai chủ động bỏ chọn/lưu đơn (vd admin đóng tab, mất mạng) —
    // gọi định kỳ qua scheduler (xem app/Console/Commands/ReleaseExpiredTimeslotHolds.php), KHÁC
    // với purgeExpired() ở trên: hàm đó chỉ âm thầm xoá (không broadcast) mỗi khi TÌNH CỜ có ai
    // truy vấn liên quan, nên phía khách hàng (cập nhật DOM trực tiếp qua real-time, xem
    // resources/js/echo-client.js) sẽ bị KẸT mãi ở trạng thái "đang khóa" cho tới khi F5 trang nếu
    // không có gì chủ động báo "released" — hàm này đảm bảo LUÔN bắn sự kiện released đúng lúc hold
    // hết hạn, dù không ai chủ động thao tác gì.
    public function releaseExpiredHolds(): int
    {
        $expired = TimeslotHold::where('expires_at', '<', now())->with('roomTimeSlot')->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        TimeslotHold::whereIn('id', $expired->pluck('id'))->delete();

        foreach ($expired as $hold) {
            $productId = $hold->roomTimeSlot?->room_id;
            $timeslotId = $hold->roomTimeSlot?->timeslot_id;

            if ($productId && $timeslotId) {
                $this->safeBroadcast(new TimeslotReleased(
                    $productId,
                    $hold->room_time_slot_id,
                    $timeslotId,
                    $hold->date->format('Y-m-d')
                ));
            }
        }

        return $expired->count();
    }
}
