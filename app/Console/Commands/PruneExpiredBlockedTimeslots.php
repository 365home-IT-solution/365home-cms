<?php

namespace App\Console\Commands;

use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Product\App\Models\RoomTimeSlot;

// Tự động gỡ khoá dài hạn (BlockTimeslotModal/Dashboard "Lịch" — RoomTimeSlot.settings
// ['blocked_dates']) cho các ngày đã QUA GIỜ KẾT THÚC của đúng khung giờ đó — vd khoá khung giờ
// 10:30-13:20 ngày 08/08/2026 thì sau 13:20 cùng ngày, mục khoá đó tự biến mất, không cần admin
// tự tay mở khoá cho ngày đã qua (không còn ý nghĩa chặn đặt phòng nữa). Cùng cách tổ chức với
// ReleaseExpiredTimeslotHolds — chạy định kỳ qua Kernel::schedule().
class PruneExpiredBlockedTimeslots extends Command
{
    protected $signature = 'timeslot-blocks:prune-expired';

    protected $description = 'Tự động gỡ khoá các khung giờ đã khoá (blocked_dates) nhưng đã qua giờ kết thúc';

    public function handle(SlotRealtimeService $realtime): int
    {
        $slots = RoomTimeSlot::whereNull('date')
            ->whereNotNull('settings')
            ->with('timeSlot')
            ->get()
            ->filter(fn (RoomTimeSlot $slot) => $slot->timeSlot !== null && ! empty($slot->settings['blocked_dates'] ?? []));

        $totalRemoved = 0;

        foreach ($slots as $slot) {
            $blockedDates = $slot->settings['blocked_dates'] ?? [];
            $stillBlocked = [];
            $expiredDates = [];

            foreach ($blockedDates as $dateStr) {
                // Cùng công thức tính giờ kết thúc THẬT (qua đêm cộng thêm 1 ngày) với
                // OrderForm::computeSlotDatetimes()/RoomCardsService::buildTimeslotGrid().
                $start = Carbon::parse($dateStr . ' ' . $slot->timeSlot->start_time);
                $end   = Carbon::parse($dateStr . ' ' . $slot->timeSlot->end_time);
                if ($slot->timeSlot->over_night || $end->lessThanOrEqualTo($start)) {
                    $end->addDay();
                }

                if ($end->isPast()) {
                    $expiredDates[] = $dateStr;
                } else {
                    $stillBlocked[] = $dateStr;
                }
            }

            if (empty($expiredDates)) {
                continue;
            }

            $settings = $slot->settings;
            $settings['blocked_dates'] = $stillBlocked;
            $slot->update(['settings' => $settings]);

            $realtime->broadcastBlockedRange((string) $slot->room_id, $expiredDates, [$slot->id], 'available');

            $totalRemoved += count($expiredDates);
        }

        if ($totalRemoved > 0) {
            $this->info("Đã tự động gỡ khoá {$totalRemoved} khung giờ đã qua giờ kết thúc.");
        }

        return self::SUCCESS;
    }
}
