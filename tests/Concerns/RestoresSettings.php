<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Cài đặt (spatie/laravel-settings, bảng settings) KHÔNG được hoàn tác cùng giao dịch của DatabaseTransactions: test lưu cài đặt thì giá trị ở lại DB
 * thật (từng làm tắt lại công tắc giai đoạn 2 của bảng giá). Test nào ghi cài đặt phải dùng trait này: chụp lại các nhóm trước, khôi phục sau.
 */
trait RestoresSettings
{
    /** @var list<array<string,mixed>> */
    private array $settingsSnapshot = [];

    /** @var list<string> */
    private array $settingsSnapshotGroups = [];

    protected function snapshotSettings(string ...$groups): void
    {
        $this->settingsSnapshotGroups = $groups;
        $this->settingsSnapshot = DB::table('settings')->whereIn('group', $groups)->get()->map(fn ($row) => (array) $row)->all();
    }

    protected function restoreSettings(): void
    {
        if ($this->settingsSnapshotGroups === []) {
            return;
        }

        foreach ($this->settingsSnapshot as $row) {
            DB::table('settings')->where('group', $row['group'])->where('name', $row['name'])->update(['payload' => $row['payload']]);
        }

        app()->forgetInstance(\App\Settings\SePaySettings::class);
        app()->forgetInstance(\App\Settings\PriceBoardSettings::class);
    }
}
