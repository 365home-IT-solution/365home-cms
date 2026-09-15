<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Exports\ContractExporter;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Support\Money;

class ContractTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade), theo đúng cơ chế
                // ViewColumn đã áp dụng cho TenantTable (KHÔNG dùng Tables\Columns\Layout\* — xem
                // ghi chú chi tiết trong TenantTable::table()).
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.contract-mobile-row')
                    ->hiddenFrom('md')
                    // max-w-0 w-full — BẮT BUỘC để truncate() bên trong Blade thật sự có tác dụng.
                    // <table> của Filament dùng table-auto (tự tính độ rộng cột theo nội dung CHƯA
                    // cắt), nên dù div con có class truncate, cột vẫn bị đo theo độ dài chữ đầy đủ,
                    // đẩy cả bảng rộng hơn màn hình → vẫn cuộn ngang (đã đo bằng Playwright, xác nhận
                    // đúng nguyên nhân trước khi thêm dòng này).
                    ->extraCellAttributes(['style' => 'max-width: 190px; width: 100%; overflow: hidden;']),

                TextColumn::make('room.code')->label('Phòng')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('tenant.fullname')->label('Khách thuê')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('start_date')->label('Bắt đầu')->date('d/m/Y')->sortable()->visibleFrom('md'),
                TextColumn::make('end_date')->label('Kết thúc')->date('d/m/Y')->sortable()->visibleFrom('md'),
                TextColumn::make('monthly_price')->label('Giá thuê')->formatStateUsing(fn ($state) => Money::format($state))->sortable()->visibleFrom('md'),
                TextColumn::make('deposit_amount')->label('Tiền cọc')->formatStateUsing(fn ($state) => Money::format($state))->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                // tenants() gồm CẢ người đứng tên lẫn người ở cùng (đều là Tenant thật) — không
                // cần +1 nữa như hồi còn tách ContractOccupant riêng.
                TextColumn::make('tenants_count')->label('Số người ở')->counts('tenants')
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
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
                })->visibleFrom('md'),
                // Chỉ báo nhanh đã có/thiếu giấy tờ lưu trữ (mục "5. Hợp đồng & giấy tờ" trong docs)
                // — mở file thật thì vào Sửa, FileUpload tự hiện link tải xuống.
                IconColumn::make('contract_file')->label('Hợp đồng')->boolean()
                    ->getStateUsing(fn (Contract $record) => filled($record->contract_file))
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                IconColumn::make('handover_file')->label('Biên bản bàn giao')->boolean()
                    ->getStateUsing(fn (Contract $record) => filled($record->handover_file))
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
                IconColumn::make('deposit_receipt_file')->label('Biên bản đặt cọc')->boolean()
                    ->getStateUsing(fn (Contract $record) => filled($record->deposit_receipt_file))
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
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
                    ->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
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
                TrashedFilter::make(),
            ])
            ->actions([
                // ->extraAttributes(class: mh-row-action) — xem _mobile-action-styles.blade.php: ẩn
                // chữ nhãn ("Chỉnh sửa"/"Xóa"), chỉ giữ icon, RIÊNG dưới 768px — nút hành động không
                // nằm trong ->columns() nên không tự thu gọn theo visibleFrom('md') như các cột khác.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
                RestoreAction::make()->extraAttributes(['class' => 'mh-row-action']),
                ForceDeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
                RestoreBulkAction::make(),
                ForceDeleteBulkAction::make(),
                ExportBulkAction::make()->label('Xuất Excel (đã chọn)')->exporter(ContractExporter::class),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
