<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Services\PortalBroadcastDispatchService;

class PortalBroadcastTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Tiêu đề')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('body')->label('Nội dung')->limit(60)->tooltip(fn (PortalBroadcast $r) => $r->body)->color('gray'),

                TextColumn::make('delivery_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->getStateUsing(fn (PortalBroadcast $r) => $r->isPending() ? 'scheduled' : ($r->sent_at ? 'sent' : 'draft'))
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'scheduled' => 'Đã lên lịch',
                        'sent'      => 'Đã gửi',
                        default     => 'Nháp',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'scheduled' => 'warning',
                        'sent'      => 'success',
                        default     => 'gray',
                    }),

                TextColumn::make('recipient_count')->label('Người nhận')->suffix(' khách')->alignCenter(),

                TextColumn::make('scheduled_at')
                    ->label('Lịch gửi')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Gửi ngay')
                    ->color('warning')
                    ->sortable(),

                TextColumn::make('creator.fullname')->label('Người gửi')->placeholder('—'),
                TextColumn::make('created_at')->label('Thời gian tạo')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                ViewAction::make()->label('Chi tiết'),
                EditAction::make()->label('Sửa')->visible(fn (PortalBroadcast $r) => $r->sent_at === null),
                // Mirror App\Filament\Resources\NotificationFcmResource action "Gửi lại" (Home) — tạo
                // BẢN GHI MỚI giữ nguyên lịch sử, dùng chung PortalBroadcastDispatchService với
                // Api\Admin\Minihouse\PushNotificationController::resend() để không lệch hành vi giữa
                // panel và app di động.
                Action::make('resend')
                    ->label('Gửi lại')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (PortalBroadcast $r) => $r->sent_at !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Gửi lại thông báo này cho đúng nhóm người nhận ban đầu (trong phạm vi toà nhà bạn quản lý)?')
                    ->action(function (PortalBroadcast $record): void {
                        $tenantIds = PortalBroadcastDispatchService::sourceTenantIds($record);

                        if (empty($tenantIds)) {
                            Notification::make()->warning()->title('Không tìm được danh sách người nhận của lần gửi trước')->send();

                            return;
                        }

                        $permitted   = auth()->user()->isSuperAdmin() ? [] : auth()->user()->rootBuildingIds();
                        $resolvedIds = PortalBroadcastDispatchService::resolveTenantIds($permitted, $record->sent_for, $tenantIds);

                        $new = PortalBroadcast::create([
                            'title' => $record->title, 'body' => $record->body, 'link' => $record->link,
                            'sent_for' => $record->sent_for, 'created_by' => auth()->id(),
                        ]);

                        PortalBroadcastDispatchService::dispatchNow($new, $resolvedIds);

                        Notification::make()->success()->title("Đã gửi lại — {$new->recipient_count} thành công")->send();
                    }),
                DeleteAction::make()->label('Xoá')->visible(fn (PortalBroadcast $r) => $r->sent_at === null),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->paginated([10, 25, 50]);
    }
}
