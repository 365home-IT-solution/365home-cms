<?php

namespace Tests\Unit;

use App\Services\RoomInventory;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class RoomInventoryTest extends TestCase
{
    private function item(string $in, string $out, int $qty = 1): object
    {
        return (object) ['checkin_date' => $in, 'checkout_date' => $out, 'quantity' => $qty];
    }

    private function peak(array $items, string $from, string $to): int
    {
        return (new RoomInventory())->peak(collect($items), Carbon::parse($from), Carbon::parse($to));
    }

    public function test_overlapping_bookings_add_up(): void
    {
        $items = [
            $this->item('2026-11-01 14:00', '2026-11-03 12:00', 2),
            $this->item('2026-11-02 14:00', '2026-11-04 12:00', 1),
        ];

        $this->assertSame(3, $this->peak($items, '2026-11-01 00:00', '2026-11-05 00:00'));
    }

    public function test_back_to_back_bookings_do_not_stack(): void
    {
        $items = [
            $this->item('2026-11-01 14:00', '2026-11-02 12:00', 2),
            $this->item('2026-11-02 12:00', '2026-11-03 12:00', 2),
        ];

        $this->assertSame(2, $this->peak($items, '2026-11-01 00:00', '2026-11-04 00:00'));
    }

    public function test_bookings_outside_window_are_ignored(): void
    {
        $items = [
            $this->item('2026-11-01 14:00', '2026-11-02 12:00', 3),
            $this->item('2026-11-05 14:00', '2026-11-06 12:00', 1),
        ];

        $this->assertSame(1, $this->peak($items, '2026-11-05 00:00', '2026-11-07 00:00'));
        $this->assertSame(0, $this->peak($items, '2026-11-03 00:00', '2026-11-04 00:00'));
    }

    public function test_full_intervals_only_cover_sold_out_time(): void
    {
        $items = collect([
            $this->item('2026-11-01 14:00', '2026-11-03 12:00', 1),
            $this->item('2026-11-02 14:00', '2026-11-04 12:00', 1),
        ]);

        $full = (new RoomInventory())->fullIntervals($items, 2);

        $this->assertCount(1, $full);
        $this->assertSame('2026-11-02 14:00', $full[0][0]->format('Y-m-d H:i'));
        $this->assertSame('2026-11-03 12:00', $full[0][1]->format('Y-m-d H:i'));
        $this->assertSame([], (new RoomInventory())->fullIntervals($items, 3));
    }
}
