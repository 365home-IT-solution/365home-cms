<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerSettlementResource\RelationManagers;

use App\Models\PartnerSettlementDispute as Dispute;
use App\Services\SettlementService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

// Khiếu nại từng đơn của đối tác — Super Admin chấp nhận (kèm hoa hồng mới của đơn) hoặc giữ nguyên.
class SettlementDisputesRelationManager extends RelationManager
{
    protected static string $relationship = 'disputes';

    protected static ?string $title = 'Khiếu nại';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_code')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Gửi lúc')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('order_code')->label('Mã đơn'),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => [Dispute::STATUS_OPEN => 'Chờ xử lý', Dispute::STATUS_ACCEPTED => 'Chấp nhận', Dispute::STATUS_REJECTED => 'Giữ nguyên'][$state] ?? $state)
                    ->color(fn (string $state) => $state === Dispute::STATUS_OPEN ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('reason')->label('Lý do')->wrap(),
                Tables\Columns\TextColumn::make('adjusted_commission')->label('Hoa hồng mới')->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ')->placeholder('—'),
                Tables\Columns\TextColumn::make('resolution_note')->label('Kết luận')->wrap()->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('resolve')
                    ->label('Xử lý')->icon('heroicon-o-scale')
                    ->visible(fn (Dispute $record) => (auth()->user()?->isSuperAdmin() ?? false) && $record->status === Dispute::STATUS_OPEN)
                    ->form([
                        Forms\Components\Select::make('resolution')->label('Kết luận')->required()->native(false)->live()
                            ->options(['accept' => 'Chấp nhận — sửa hoa hồng của đơn', 'reject' => 'Giữ nguyên']),
                        Forms\Components\TextInput::make('adjusted_commission')->label('Hoa hồng mới của đơn (đ)')->numeric()->minValue(0)
                            ->visible(fn (Forms\Get $get) => $get('resolution') === 'accept')->required(fn (Forms\Get $get) => $get('resolution') === 'accept'),
                        Forms\Components\Textarea::make('note')->label('Ghi kết luận xử lý')->required()->maxLength(2000),
                    ])
                    ->action(function (Dispute $record, array $data) {
                        try {
                            app(SettlementService::class)->resolveDispute($record, $data['resolution'] === 'accept', isset($data['adjusted_commission']) ? (int) $data['adjusted_commission'] : null, $data['note'], auth()->user());
                            Notification::make()->success()->title('Đã xử lý khiếu nại.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
