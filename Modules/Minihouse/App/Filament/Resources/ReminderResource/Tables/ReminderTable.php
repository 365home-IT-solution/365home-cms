<?php

namespace Modules\Minihouse\App\Filament\Resources\ReminderResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Services\MinihouseSmsService;
use Modules\Minihouse\App\Services\MinihouseZaloService;

class ReminderTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Tiêu đề')->searchable()->sortable(),
                TextColumn::make('type')->label('Loại')->formatStateUsing(fn (string $state) => match ($state) {
                    Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                    Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                    Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                    default => 'Khác',
                }),
                TextColumn::make('remind_date')->label('Ngày nhắc')->date('d/m/Y')->sortable(),
                TextColumn::make('room.code')->label('Phòng')->searchable(),
                TextColumn::make('assignee.fullname')->label('Giao cho')->placeholder('Chưa giao'),
                IconColumn::make('is_done')->label('Đã xử lý')->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([
                        Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                        Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                        Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                        Reminder::TYPE_OTHER       => 'Khác',
                    ]),
                TernaryFilter::make('is_done')
                    ->label('Trạng thái xử lý')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã xử lý')
                    ->falseLabel('Chưa xử lý'),
                SelectFilter::make('assigned_to')
                    ->label('Giao cho')
                    // whereNotNull('fullname') — cùng lý do đã sửa ở ReminderForm::form() (Select
                    // "Giao cho nhân viên"): nhãn option null (tài khoản chưa điền Họ tên) làm vỡ cả
                    // trang do Filament ép nhãn phải là string.
                    ->relationship(
                        'assignee',
                        'fullname',
                        fn ($query) => \Modules\Minihouse\App\Support\MinihousePermissions::scopeToMinihouseUsers($query)->whereNotNull('fullname'),
                    )
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                // Gửi NGAY qua Zalo cho khách liên quan — KHÔNG chờ tới ngày nhắc, KHÔNG bị chặn
                // bởi cờ "đã gửi" (notified_at) như luồng cron tự động — bấm được nhiều lần để nhắc
                // lại khách nếu cần (VD khách vẫn chưa đóng tiền sau lần nhắc đầu).
                Action::make('sendZaloNow')
                    ->label('Gửi Zalo')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('gray')
                    ->visible(fn (Reminder $record) => $record->type !== Reminder::TYPE_OTHER)
                    ->requiresConfirmation()
                    ->modalDescription('Gửi ngay tin nhắc việc này qua Zalo cho khách thuê liên quan — không cần chờ tới ngày nhắc, có thể gửi lại nhiều lần.')
                    ->action(function (Reminder $record) {
                        $result = app(MinihouseZaloService::class)->sendReminderNotification($record);

                        if ($result['success']) {
                            Notification::make()
                                ->title('Đã gửi Zalo thành công')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(($result['skipped'] ?? false) ? 'Chưa gửi được' : 'Gửi Zalo thất bại')
                            ->body($result['reason'] ?? $result['error'] ?? 'Lỗi không xác định.')
                            ->danger()
                            ->send();
                    }),

                // Cùng nguyên tắc "Gửi Zalo" ở trên, kênh SMS độc lập — xem MinihouseSmsService.
                Action::make('sendSmsNow')
                    ->label('Gửi SMS')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->color('gray')
                    ->visible(fn (Reminder $record) => $record->type !== Reminder::TYPE_OTHER)
                    ->requiresConfirmation()
                    ->modalDescription('Gửi ngay tin nhắc việc này qua SMS cho khách thuê liên quan — không cần chờ tới ngày nhắc, có thể gửi lại nhiều lần.')
                    ->action(function (Reminder $record) {
                        $result = app(MinihouseSmsService::class)->sendReminderNotification($record);

                        if ($result['success']) {
                            Notification::make()
                                ->title('Đã gửi SMS thành công')
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(($result['skipped'] ?? false) ? 'Chưa gửi được' : 'Gửi SMS thất bại')
                            ->body($result['reason'] ?? $result['error'] ?? 'Lỗi không xác định.')
                            ->danger()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('remind_date')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
