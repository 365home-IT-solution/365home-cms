<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Merge multiple records of same (room_id, type) into one with JSON array path
        $groups = DB::table('room_images')
            ->select('room_id', 'type')
            ->groupBy('room_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $records = DB::table('room_images')
                ->where('room_id', $group->room_id)
                ->where('type', $group->type)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $paths = $records->pluck('path')->toArray();

            DB::table('room_images')
                ->where('id', $records->first()->id)
                ->update(['path' => json_encode($paths)]);

            DB::table('room_images')
                ->where('room_id', $group->room_id)
                ->where('type', $group->type)
                ->where('id', '!=', $records->first()->id)
                ->delete();
        }

        // Step 2: Convert remaining single string paths to JSON arrays
        DB::table('room_images')
            ->whereRaw("path NOT LIKE '[%'")
            ->get()
            ->each(function ($record) {
                DB::table('room_images')
                    ->where('id', $record->id)
                    ->update(['path' => json_encode([$record->path])]);
            });

        // Step 3: Alter column to text to support JSON
        Schema::table('room_images', function (Blueprint $table) {
            $table->text('path')->change();
        });

        // Step 4: Unique constraint — one record per room per type
        Schema::table('room_images', function (Blueprint $table) {
            $table->unique(['room_id', 'type'], 'room_images_room_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->dropUnique('room_images_room_type_unique');
            $table->string('path', 500)->change();
        });
    }
};
