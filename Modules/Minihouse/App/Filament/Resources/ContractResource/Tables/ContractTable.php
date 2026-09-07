<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Support\Money;

class ContractTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('room.code')->label('Phòng')->searchable()->sortable(),
                TextColumn::make('tenant.fullname')->label('Khách thuê')->searchable()->sortable(),
                TextColumn::make('start_date')->label('Bắt đầu')->date('d/m/Y')->sortable(),
                TextColumn::make('end_date')->label('Kết thúc')->date('d/m/Y')->sortable(),
                TextColumn::make('monthly_price')->label('Giá thuê')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('deposit_amount')->label('Tiền cọc')->formatStateUsing(fn ($state) => Money::format($state))->toggleable(isToggledHiddenByDefault: true),
                // tenants() gồm CẢ người đứng tên lẫn người ở cùng (đều là Tenant thật) — không
                // cần +1 nữa như hồi còn tách ContractOccupant riêng.
                TextColumn::make('tenants_count')->label('Số người ở')->counts('tenants')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Contract::STATUS_ACTIVE    => 'Đang hiệu lực',
                    Contract::STATUS_EXPIRED   => 'Hết hạn',
                    Contract::STATUS_CANCELLED => 'Đã huỷ',
                    default => $state,
                })->color(fn (string $state) => match ($state) {
                    Contract::STATUS_ACTIVE    => 'success',
                    Contract::STATUS_EXPIRED   => 'gray',
                    Contract::STATUS_CANCELLED => 'danger',
                    default => 'gray',
                }),
                // Chỉ báo nhanh đã có/thiếu giấy tờ lưu trữ (mục "5. Hợp đồng & giấy tờ" trong docs)
                // — mở file thật thì vào Sửa, FileUpload tự hiện link tải xuống.
                IconColumn::make('contract_file')->label('Hợp đồng')->boolean()
                    ->getStateUsing(fn (Contract $record) => filled($record->contract_file))
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('handover_file')->label('Biên bản bàn giao')->boolean()
                    ->getStateUsing(fn (Contract $record) => filled($record->handover_file))
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('deposit_receipt_file')->label('Biên bản đặt cọc')->boolean()
                    ->getStateUsing(fn (Contract $record) => filled($record->deposit_receipt_file))
                    ->toggleable(isToggledHiddenByDefault: true),
                // Cho biết hợp đồng này có nằm trong 1 lần "Chuyển phòng" hay không — xem
                // EditContract::getHeaderActions() ("Chuyển phòng").
                TextColumn::make('transfer_info')->label('Chuyển phòng')
                    ->getStateUsing(function (Contract $record) {
                        if ($record->transferred_to_contract_id) {
                            return 'Đã chuyển sang phòng ' . ($record->transferredTo?->room?->code ?? '#' . $record->transferred_to_contract_id);
                        }

                        if ($record->transferred_from_contract_id) {
                            return 'Chuyển từ phòng ' . ($record->transferredFrom?->room?->code ?? '#' . $record->transferred_from_contract_id);
                        }

                        return null;
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('room_id')
                    ->label('Phòng')
                    ->options(fn () => Room::query()->pluck('code', 'id')),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        Contract::STATUS_ACTIVE    => 'Đang hiệu lực',
                        Contract::STATUS_EXPIRED   => 'Hết hạn',
                        Contract::STATUS_CANCELLED => 'Đã huỷ',
                    ]),
                Filter::make('expiring_soon')
                    ->label('Sắp hết hạn (30 ngày)')
                    ->query(fn ($query) => $query
                        ->where('status', Contract::STATUS_ACTIVE)
                        ->whereNotNull('end_date')
                        ->whereBetween('end_date', [now(), now()->addDays(30)])),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
