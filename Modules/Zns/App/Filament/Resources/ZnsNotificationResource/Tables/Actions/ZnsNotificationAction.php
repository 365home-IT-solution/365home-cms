<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\Actions;

use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Modules\BladeThemeV1\Services\Zns\ZaloZnsService;

class ZnsNotificationAction
{
    public static function action()
    {
        return [
            Action::make('retry')
                ->label('Thử lại')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                // ->visible(fn ($record) => $record->canRetry())
                ->requiresConfirmation()
                ->action(function ($record) {
                    $znsService = app(ZaloZnsService::class);

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
                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Gửi lại thành công')
                            ->body('ZNS đã được gửi lại.')
                            ->send();
                    } else {
                        \Filament\Notifications\Notification::make()
                            ->danger()
                            ->title('Gửi lại thất bại')
                            ->body($result['error'])
                            ->send();
                    }
                }),

            Action::make('view_response')
                ->label('Xem response')
                ->icon('heroicon-m-newspaper')
                ->color('gray')
                // ->visible(fn ($record) => !empty($record->response_data))
                ->modalContent(fn($record) => view('zns::modals.zns-response', [
                    'data' => $record->response_data
                ]))
                ->modalSubmitAction(false),
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết'),
                EditAction::make()->label('Cập nhật'),
                DeleteAction::make('Xóa')
            ]),
        ];
    }
}
