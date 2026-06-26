<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Product\App\Models\ManualLockPassword;

class ExpireManualLockPasswords extends Command
{
    protected $signature   = 'manual-lock-passwords:check-expired';
    protected $description = 'Đánh dấu các bộ mật khẩu khóa thủ công đã hết hạn là ngừng hoạt động';

    public function handle(): int
    {
        $expired = ManualLockPassword::expired()->with('products')->get();

        if ($expired->isEmpty()) {
            $this->info('✅ Không có bộ mật khẩu nào hết hạn.');
            return 0;
        }

        $this->info("📊 Tìm thấy {$expired->count()} bộ mật khẩu đã hết hạn.");

        $deactivated = 0;

        foreach ($expired as $record) {
            try {
                $record->deactivate();
                $deactivated++;

                $productNames = $record->products->pluck('name')->join(', ') ?: '(không có)';

                $this->line("  ⏰ [{$record->id}] {$record->name} — hết hạn {$record->valid_until?->format('d/m/Y H:i')} — phòng: {$productNames}");

                Log::info('ManualLockPassword expired', [
                    'id'          => $record->id,
                    'name'        => $record->name,
                    'valid_until' => $record->valid_until?->toDateTimeString(),
                    'products'    => $record->products->pluck('name')->toArray(),
                ]);

                // Tìm và log bộ mật khẩu đang hoạt động mới nhất cho mỗi phòng liên quan
                foreach ($record->products as $product) {
                    $latest = ManualLockPassword::active()
                        ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
                        ->where('id', '!=', $record->id)
                        ->latest('valid_until')
                        ->first();

                    if ($latest) {
                        $this->line("    ✅ Phòng [{$product->name}] → mật khẩu mới nhất: Pass Cổng={$latest->gate_password}" . ($latest->room_password ? ", Pass Phòng={$latest->room_password}" : ''));
                    } else {
                        $this->warn("    ⚠️  Phòng [{$product->name}] không còn bộ mật khẩu nào đang hoạt động.");
                    }
                }
            } catch (\Exception $e) {
                $this->error("❌ Lỗi khi xử lý [{$record->id}] {$record->name}: {$e->getMessage()}");
                Log::error('Failed to expire ManualLockPassword', ['id' => $record->id, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->info("✅ Đã ngừng hoạt động {$deactivated}/{$expired->count()} bộ mật khẩu.");

        return 0;
    }
}
