<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\AccessCode\Entities\AccessCode;

class AutoDeleteExpiredAccessCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // protected $signature = 'app:auto-delete-expired-access-codes';
    protected $signature = 'access-codes:auto-delete-expired {--force : Force delete instead of soft delete}';
    protected $description = 'Automatically soft delete or force delete expired access codes';

    /**
     * The console command description.
     *
     * @var string
     */

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $forceDelete = $this->option('force');

        // Tìm các mã đã hết hạn
        $expiredCodes = AccessCode::where('status', 'active')
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now())
            ->get();

        if ($expiredCodes->isEmpty()) {
            $this->info('✅ Không có mã hết hạn nào cần xóa.');
            return 0;
        }

        $this->info("📊 Tìm thấy {$expiredCodes->count()} mã đã hết hạn.");

        $this->table(
            ['ID', 'Code', 'Category', 'Valid Until', 'Usage Count'],
            $expiredCodes->map(fn($code) => [
                $code->id,
                $code->code,
                $code->category?->name ?? 'N/A',
                $code->valid_until?->format('d/m/Y H:i'),
                $code->usage_count,
            ])
        );

        if (!$this->confirm('Bạn có chắc muốn xóa các mã này?', true)) {
            $this->warn('❌ Đã hủy thao tác.');
            return 0;
        }

        $deleted = 0;

        foreach ($expiredCodes as $code) {
            try {
                if ($forceDelete) {
                    // XÓA CỨNG 
                    $code->forceDelete();
                    $this->warn("🗑️  Force deleted: {$code->code}");
                } else {
                    // SOFT DELETE (khuyến nghị)
                    $code->delete();
                    $this->info("♻️  Soft deleted: {$code->code}");
                }

                $deleted++;
            } catch (\Exception $e) {
                $this->error("❌ Lỗi khi xóa {$code->code}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("✅ Đã xóa {$deleted}/{$expiredCodes->count()} mã hết hạn.");

        return 0;
    }
}
