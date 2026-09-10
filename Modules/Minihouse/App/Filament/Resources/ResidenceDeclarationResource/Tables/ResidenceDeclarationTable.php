<?php

namespace Modules\Minihouse\App\Filament\Resources\ResidenceDeclarationResource\Tables;

use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\Minihouse\App\Models\ResidenceDeclaration;

class ResidenceDeclarationTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract.room.code')
                    ->label('Phòng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('subject')
                    ->label('Vai trò')
                    ->state(fn (ResidenceDeclaration $record) => $record->roleInContract() === 'occupant' ? 'Người ở cùng' : 'Người đứng tên')
                    ->badge()
                    ->color(fn (ResidenceDeclaration $record) => $record->roleInContract() === 'occupant' ? 'warning' : 'gray'),

                TextColumn::make('full_name')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cccd_number')
                    ->label('Số CCCD')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->toggleable(),

                TextColumn::make('checked_in_at')
                    ->label('Ngày đến')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('checked_out_at')
                    ->label('Ngày đi dự kiến')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('declaration_deadline')
                    ->label('Hạn khai báo')
                    ->state(fn (ResidenceDeclaration $record) => $record->declarationDeadline()?->format('H:i d/m/Y'))
                    ->sortable(false)
                    ->color(fn (ResidenceDeclaration $record) => $record->isOverdue() ? 'danger' : ($record->isDueSoon() ? 'warning' : null)),

                TextColumn::make('declared_at')
                    ->label('Trạng thái khai báo')
                    ->badge()
                    ->state(function (ResidenceDeclaration $record): string {
                        if ($record->isDeclared()) {
                            return 'Đã khai báo';
                        }

                        if (! $record->isDataComplete()) {
                            return 'Thiếu thông tin';
                        }

                        if ($record->isOverdue()) {
                            return 'Quá hạn — CHƯA khai báo';
                        }

                        if ($record->isDueSoon()) {
                            return 'Sắp tới hạn';
                        }

                        return 'Chưa khai báo';
                    })
                    ->color(function (ResidenceDeclaration $record): string {
                        if ($record->isDeclared()) {
                            return 'success';
                        }

                        if (! $record->isDataComplete()) {
                            return 'danger';
                        }

                        if ($record->isOverdue()) {
                            return 'danger';
                        }

                        if ($record->isDueSoon()) {
                            return 'warning';
                        }

                        return 'gray';
                    })
                    ->tooltip(function (ResidenceDeclaration $record): ?string {
                        if (! $record->isDataComplete()) {
                            return 'Còn thiếu: ' . implode(', ', $record->missingRequiredFieldLabels());
                        }

                        return $record->declarationDeadline()
                            ? 'Hạn: ' . $record->declarationDeadline()->format('H:i d/m/Y')
                            : null;
                    }),

                TextColumn::make('gender')->label('Giới tính')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('nationality')->label('Quốc tịch')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason_for_stay')->label('Lý do lưu trú')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('current_residence')->label('Nơi thường trú')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('province')->label('Tỉnh/Thành phố')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ward')->label('Phường/Xã')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date')
                    ->form([
                        DatePicker::make('from')->label('Từ ngày (ngày đến)')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Đến ngày (ngày đến)')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('checked_in_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('checked_in_at', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'Từ ' . \Carbon\Carbon::parse($data['from'])->format('d/m/Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Đến ' . \Carbon\Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),

                TernaryFilter::make('declared_at')
                    ->label('Trạng thái khai báo')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đã khai báo')
                    ->falseLabel('Chưa khai báo')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('declared_at'),
                        false: fn (Builder $query) => $query->whereNull('declared_at'),
                    ),
            ])
            ->actions([
                Action::make('markDeclared')
                    ->label('Đánh dấu đã khai báo')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ResidenceDeclaration $record) => ! $record->isDeclared())
                    ->requiresConfirmation(fn (ResidenceDeclaration $record) => $record->isDataComplete())
                    ->modalDescription('Xác nhận bạn ĐÃ nộp khai báo lưu trú cho cơ quan công an (qua ASM/dịch vụ công) cho người này?')
                    ->action(function (ResidenceDeclaration $record) {
                        if (! $record->isDataComplete()) {
                            Notification::make()
                                ->title('Chưa thể đánh dấu đã khai báo')
                                ->body('Còn thiếu: ' . implode(', ', $record->missingRequiredFieldLabels()) . '. Vui lòng bổ sung đầy đủ (bấm "Sửa") trước khi đánh dấu.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['declared_at' => now(), 'declared_by' => auth()->id()]);

                        Notification::make()->title('Đã đánh dấu khai báo thành công')->success()->send();
                    }),

                Action::make('unmarkDeclared')
                    ->label('Bỏ đánh dấu đã khai báo')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (ResidenceDeclaration $record) => $record->isDeclared())
                    ->requiresConfirmation()
                    ->modalDescription('Xác nhận BỎ đánh dấu "đã khai báo" cho người này?')
                    ->action(function (ResidenceDeclaration $record) {
                        $record->update(['declared_at' => null, 'declared_by' => null]);

                        Notification::make()->title('Đã bỏ đánh dấu khai báo')->success()->send();
                    }),

                EditAction::make()->label('Sửa'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('markDeclaredBulk')
                        ->label('Đánh dấu đã khai báo')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Xác nhận bạn ĐÃ nộp khai báo lưu trú cho TẤT CẢ lượt đã chọn? Dòng nào còn thiếu dữ liệu bắt buộc sẽ tự động bị bỏ qua.')
                        ->action(function (Collection $records) {
                            $declaredCount = 0;
                            $skipped       = [];

                            foreach ($records as $record) {
                                if ($record->isDeclared()) {
                                    continue;
                                }

                                if (! $record->isDataComplete()) {
                                    $skipped[] = "{$record->full_name} (thiếu: " . implode(', ', $record->missingRequiredFieldLabels()) . ')';

                                    continue;
                                }

                                $record->update(['declared_at' => now(), 'declared_by' => auth()->id()]);
                                $declaredCount++;
                            }

                            if ($declaredCount > 0) {
                                Notification::make()
                                    ->title("Đã đánh dấu khai báo thành công {$declaredCount} lượt")
                                    ->success()
                                    ->send();
                            }

                            if (! empty($skipped)) {
                                Notification::make()
                                    ->title('Bỏ qua ' . count($skipped) . ' lượt do thiếu dữ liệu bắt buộc')
                                    ->body(implode("\n", $skipped))
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unmarkDeclaredBulk')
                        ->label('Bỏ đánh dấu đã khai báo')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Xác nhận BỎ đánh dấu "đã khai báo" cho tất cả lượt đã chọn?')
                        ->action(function (Collection $records) {
                            $count = 0;

                            foreach ($records as $record) {
                                if (! $record->isDeclared()) {
                                    continue;
                                }

                                $record->update(['declared_at' => null, 'declared_by' => null]);
                                $count++;
                            }

                            Notification::make()->title("Đã bỏ đánh dấu khai báo cho {$count} lượt")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('checked_in_at', 'asc')
            ->searchable()
            ->striped();
    }
}
