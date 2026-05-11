<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TimeSlotSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('time_slots')->insertOrIgnore([
            ['id' => 1,  'start_time' => '23:30:00', 'end_time' => '09:30:00', 'label' => '23:30 - 09:30 (Qua Ä‘Ãªm)', 'over_night' => 1, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2,  'start_time' => '13:00:00', 'end_time' => '16:00:00', 'label' => '13:00 - 16:00',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3,  'start_time' => '16:30:00', 'end_time' => '19:30:00', 'label' => '16:30 - 19:30',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4,  'start_time' => '09:30:00', 'end_time' => '12:20:00', 'label' => '09:30 - 12:20',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5,  'start_time' => '20:00:00', 'end_time' => '09:00:00', 'label' => '20:00 - 9:00 (Qua Ä‘Ãªm)', 'over_night' => 1, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6,  'start_time' => '10:00:00', 'end_time' => '13:00:00', 'label' => '10:00 - 13:00',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7,  'start_time' => '13:30:00', 'end_time' => '16:30:00', 'label' => '13:30 - 16:30',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8,  'start_time' => '17:00:00', 'end_time' => '20:00:00', 'label' => '17:00 - 20:00',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9,  'start_time' => '20:30:00', 'end_time' => '09:00:00', 'label' => '20:30 - 9:00 (Qua Ä‘Ãªm)', 'over_night' => 1, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 10, 'start_time' => '21:30:00', 'end_time' => '07:30:00', 'label' => '21:30 - 7:30 (Qua Ä‘Ãªm)', 'over_night' => 1, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'start_time' => '11:30:00', 'end_time' => '14:20:00', 'label' => '11:30 - 14:20',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'start_time' => '14:50:00', 'end_time' => '17:40:00', 'label' => '14:50 - 17:40',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 13, 'start_time' => '18:10:00', 'end_time' => '21:00:00', 'label' => '18:10 - 21:00',           'over_night' => 0, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 14, 'start_time' => '21:30:00', 'end_time' => '10:00:00', 'label' => '21:30 - 10:00 (Qua Ä‘Ãªm)','over_night' => 1, 'type' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}

