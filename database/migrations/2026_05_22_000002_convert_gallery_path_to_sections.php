<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Convert gallery flat image array → sections format
        // Before: ["img1.jpg","img2.jpg"]
        // After:  [{"title":"","description":"","images":["img1.jpg","img2.jpg"]}]
        DB::table('room_images')
            ->where('type', 'gallery')
            ->get()
            ->each(function ($record) {
                $data = json_decode($record->path, true);
                if (! is_array($data)) return;
                // Already sections format?
                if (isset($data[0]['images'])) return;

                DB::table('room_images')
                    ->where('id', $record->id)
                    ->update([
                        'path' => json_encode([
                            ['title' => '', 'description' => '', 'images' => $data],
                        ]),
                    ]);
            });
    }

    public function down(): void
    {
        // Revert sections format → flat image array (first section only)
        DB::table('room_images')
            ->where('type', 'gallery')
            ->get()
            ->each(function ($record) {
                $data = json_decode($record->path, true);
                if (! is_array($data) || ! isset($data[0]['images'])) return;

                $images = array_merge(...array_column($data, 'images'));
                DB::table('room_images')
                    ->where('id', $record->id)
                    ->update(['path' => json_encode($images)]);
            });
    }
};
