<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerEscrowResource\RelationManagers;

use App\Models\PartnerEscrowEntry as Entry;
use App\Services\EscrowService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

// SỔ BÚT TOÁN ký quỹ: chỉ xem; bút toán không sửa/xoá — sai thì Super Admin ghi bút toán đảo. Hoàn ký quỹ khi chấm dứt hợp đồng cũng ghi ở đây.
class EscrowEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'escrowEntries';

    protected static ?string $title = 'Sổ bút toán';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => ($state > 0 ? '+' : '') . number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Thời điểm')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('type')->label('Loại')->badge()->formatStateUsing(fn (string $state) => Entry::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền')->formatStateUsing($money)->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('balance_after')->label('Số dư sau')->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ'),
                Tables\Columns\TextColumn::make('reason')->label('Lý do')->wrap()->limit(120),
                Tables\Columns\TextColumn::make('order_code')->label('Mã đơn')->placeholder('—'),
                Tables\Columns\TextColumn::make('creator.fullname')->label('Người tạo')->placeholder('Hệ thống'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('type')->label('Loại')->options(Entry::TYPES)])
            ->headerActions([
                Tables\Actions\Action::make('withdraw')
                    ->label('Hoàn ký quỹ')->icon('heroicon-o-arrow-uturn-left')->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                    ->modalDescription('Dùng khi chấm dứt hợp đồng, sau khi quyết toán công nợ cuối. Không vượt số dư khả dụng (đã trừ phần đang tạm giữ).')
                    ->form([
                        Forms\Components\TextInput::make('amount')->label('Số tiền hoàn (đ)')->numeric()->required()->minValue(1),
                        Forms\Components\Textarea::make('reason')->label('Lý do')->required()->maxLength(2000),
                        Forms\Components\TextInput::make('reference')->label('Số tham chiếu chuyển khoản')->maxLength(255),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(EscrowService::class)->withdraw($this->getOwnerRecord(), (int) $data['amount'], $data['reason'], $data['reference'] ?? null, auth()->user());
                            Notification::make()->success()->title('Đã ghi nhận hoàn ký quỹ.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('reverse')
                    ->label('Đảo bút toán')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (Entry $record) => (auth()->user()?->isSuperAdmin() ?? false) && $record->type !== Entry::TYPE_REVERSAL && ! $record->reversedBy()->exists())
                    ->form([Forms\Components\Textarea::make('reason')->label('Lý do đảo')->required()->maxLength(2000)])
                    ->action(function (Entry $record, array $data) {
                        try {
                            app(EscrowService::class)->reverse($record, $data['reason'], auth()->user());
                            Notification::make()->success()->title('Đã ghi bút toán đảo.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
