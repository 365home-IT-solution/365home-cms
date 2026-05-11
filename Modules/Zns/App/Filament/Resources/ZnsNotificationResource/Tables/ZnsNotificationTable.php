<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\Actions\ZnsNotificationAction;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\BulkActions\ZnsNotificationBulkAction;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Tables\Filters\ZnsNotificationFilter;
use Modules\Payment\App\Filament\Resources\OrderResource;

class ZnsNotificationTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order.order_code')
                    ->label('Mã đơn')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->order_id
                        ? OrderResource::getUrl('edit', ['record' => $record->order_id])
                        : null)
                    ->color('primary'),
                
                TextColumn::make('recipient_name')
                    ->label('Khách hàng')
                    ->searchable()
                    ->sortable()
                    ->placeholder('----------'),
                
                TextColumn::make('phone_number')
                    ->label('SĐT')
                    ->searchable()
                    ->formatStateUsing(fn ($state) => self::formatPhone($state))
                    ->copyable()
                    ->icon('heroicon-m-phone'),
                
                BadgeColumn::make('notification_type')
                    ->label('Loại')
                    ->colors([
                        'success' => 'booking_success',
                        'info' => 'booking_reminder',
                        'danger' => 'booking_cancelled',
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'booking_success' => 'Đặt phòng',
                        'booking_reminder' => 'Nhắc nhở',
                        'booking_cancelled' => 'Hủy đơn',
                        default => $state,
                    }),
                
                BadgeColumn::make('status')
                    ->label('Trạng thái')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'sent',
                        'success' => 'delivered',
                        'primary' => 'read',
                        'danger' => 'failed',
                    ])
                    ->formatStateUsing(fn ($record) => $record->status_label)
                    ->icon(fn ($state) => match($state) {
                        'pending' => 'heroicon-o-clock',
                        'sent' => 'heroicon-o-paper-airplane',
                        'delivered' => 'heroicon-o-check',
                        'read' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        default => null,
                    }),
                
                TextColumn::make('retry_count')
                    ->label('Thử lại')
                    ->sortable()
                    ->formatStateUsing(fn ($record) => 
                        $record->retry_count . '/' . $record->max_retries
                    )
                    ->toggleable(isToggledHiddenByDefault: true),
                
                TextColumn::make('sent_at')
                    ->label('Gửi lúc')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->placeholder('-- Chưa gửi --'),
                
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters(ZnsNotificationFilter::filter())
            ->actions(ZnsNotificationAction::action())
            ->bulkActions(ZnsNotificationBulkAction::bulkActions());
    }
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Thông tin ZNS')
                    ->schema([
                        TextEntry::make('order.order_code')
                            ->label('Mã đơn hàng')
                            ->url(fn ($record) => OrderResource::getUrl('edit', ['record' => $record->order_id]))
                            ->color('primary'),
                        
                        TextEntry::make('accessCode.code')
                            ->label('Mã cổng')
                            ->placeholder('----------'),
                        
                        TextEntry::make('recipient_name')
                            ->label('Người nhận'),
                        
                        TextEntry::make('phone_number')
                            ->label('Số điện thoại')
                            ->formatStateUsing(fn ($state) => self::formatPhone($state)),
                        
                        TextEntry::make('notification_type')
                            ->label('Loại thông báo')
                            ->badge(),
                        
                        TextEntry::make('status_label')
                            ->label('Trạng thái')
                            ->badge()
                            ->color(fn ($record) => $record->status_color),
                    ])
                    ->columns(3),
                
                Section::make('Template')
                    ->schema([
                        TextEntry::make('template_id')
                            ->label('Template ID'),
                        
                        TextEntry::make('template_name')
                            ->label('Tên template'),
                        
                        KeyValueEntry::make('template_data')
                            ->label('Dữ liệu template')
                            ->columnSpanFull(),
                    ])->columns(2),
                
                Section::make('Kết quả')
                    ->schema([
                        TextEntry::make('zns_message_id')
                            ->label('Message ID')
                            ->default('N/A'),
                        
                        TextEntry::make('error_message')
                            ->label('Lỗi')
                            ->default('Không có lỗi')
                            ->color('danger')
                            ->visible(fn ($record) => $record->status === 'failed'),
                        
                        KeyValueEntry::make('response_data')
                            ->label('Response từ Zalo')
                            ->visible(fn ($record) => !empty($record->response_data)),
                    ])
                    ->columns(2)
                    ->collapsible(),
                
                Section::make('Thời gian')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Tạo lúc')
                            ->dateTime('d/m/Y H:i:s'),
                        
                        TextEntry::make('sent_at')
                            ->label('Gửi lúc')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-- Chưa gửi --'),
                        
                        TextEntry::make('delivered_at')
                            ->label('Nhận lúc')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('-- Chưa nhận --'),
                        
                        TextEntry::make('failed_at')
                            ->label('Gửi thất bại lúc')
                            ->dateTime('d/m/Y H:i:s')
                            ->visible(fn ($record) => $record->status === 'failed'),
                        
                        TextEntry::make('retry_count')
                            ->label('Số lần thử')
                            ->formatStateUsing(fn ($record) => $record->retry_count . '/' . $record->max_retries),
                        
                        TextEntry::make('next_retry_at')
                            ->label('Thử lại lúc')
                            ->dateTime('d/m/Y H:i:s')
                            ->visible(fn ($record) => $record->next_retry_at),
                    ])
                    ->columns(3),
                
                Section::make('Ghi chú')
                    ->schema([
                        TextEntry::make('admin_notes')
                            ->label('Ghi chú admin')
                            ->default('Không có ghi chú'),
                    ])
                    ->collapsible(),
            ]);
    }

    protected static function formatPhone($phone)
    {
        if (substr($phone, 0, 2) === '84') {
            $phone = '0' . substr($phone, 2);
        }
        
        if (strlen($phone) === 10) {
            return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
        }
        
        return $phone;
    }
}