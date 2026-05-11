<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\BulkActions;

use Filament\Tables;
use Modules\BladeThemeV1\Services\Zns\ZaloZnsService;

class ZnsNotificationBulkAction
{
    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\BulkAction::make('retry_selected')
                    ->label('Thử lại các mục đã chọn')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $znsService = app(ZaloZnsService::class);
                        $success = 0;
                        $failed = 0;

                        foreach ($records as $record) {
                            if ($record->canRetry()) {
                                $record->resetForRetry();
                                $result = $znsService->sendZns(
                                    $record->order_id,
                                    $record->access_code_id,
                                    $record->phone_number,
                                    $record->recipient_name,
                                    $record->template_id,
                                    $record->notification_type,
                                    $record->template_data
                                );

                                if ($result['success']) {
                                    $success++;
                                } else {
                                    $failed++;
                                }
                            }
                        }

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Hoàn thành')
                            ->body("Thành công: {$success}, Thất bại: {$failed}")
                            ->send();
                    }),
            ]),
        ];
    }
}
