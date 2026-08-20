<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Forms\WarehouseStockCheckForm;
use Modules\Warehouse\App\Filament\Support\WarehouseCardStyle;
use Modules\Warehouse\App\Filament\Support\WarehousePrinter;
use Modules\Warehouse\App\Models\WarehouseStockCheck;

class EditWarehouseStockCheck extends EditRecord
{
    protected static string $resource = WarehouseStockCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmHandoverAction(),
            $this->handoverReportAction(),
            Action::make('print')
                ->label('In phiếu')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn (WarehouseStockCheck $record) => WarehousePrinter::stockCheck($record)),
            DeleteAction::make(),
        ];
    }

    // Trang SỬA cũng phải liệt kê đủ TOÀN BỘ vật tư như trang tạo mới (yêu cầu: "khi tạo hoặc sửa
    // thì hiển thị toàn bộ vật tư"). Repeater::default() KHÔNG chạy lại ở trang sửa (field 'items'
    // đã có dữ liệu thật từ relationship()), và mutateFormDataBeforeFill() KHÔNG dùng được ở đây vì
    // Repeater tải dữ liệu quan hệ qua 1 luồng riêng (loadStateFromRelationshipsUsing), không đọc từ
    // $data truyền vào hook đó. Phải ghi TRỰC TIẾP vào $this->data['items'] SAU KHI form đã fill
    // xong (afterFill()) — đúng kỹ thuật đã dùng ổn định ở
    // Payment\...\HasTimeslotGridSelection::selectTimeslot() (key dạng UUID giống Repeater tự sinh
    // cho dòng mới). Dòng bổ sung KHÔNG có "id" nên khi lưu, Repeater tự hiểu đây là dòng MỚI cần
    // tạo — không đụng gì tới các dòng đã có sẵn.
    protected function afterFill(): void
    {
        $existingItemIds = collect($this->data['items'] ?? [])
            ->pluck('warehouse_item_id')
            ->filter()
            ->all();

        foreach (WarehouseStockCheckForm::allActiveItemsAsRows() as $row) {
            if (! in_array($row['warehouse_item_id'], $existingItemIds, true)) {
                $this->data['items'][(string) Str::uuid()] = $row;
            }
        }
    }

    // "Xác nhận bàn giao ca" — công ty vận hành theo ca, cuối ca người trước tạo phiếu kiểm kê này,
    // đầu ca người sau cần đếm lại đúng các vật tư trong phiếu để xác nhận tồn kho bàn giao khớp.
    // CHỈ là bước đối chiếu/xác minh — KHÔNG tự động điều chỉnh lại tồn kho (nếu phát hiện lệch thật,
    // phải tạo 1 phiếu kiểm kê MỚI để điều chỉnh đúng quy trình đã có, tránh 2 nguồn cùng âm thầm sửa
    // tồn kho cùng lúc). Không bắt buộc — không xác nhận cũng không chặn tạo phiếu mới.
    protected function confirmHandoverAction(): Action
    {
        return Action::make('confirmHandover')
            ->label('Xác nhận bàn giao')
            ->icon('heroicon-o-clipboard-document-check')
            ->color(fn (WarehouseStockCheck $record) => match ($record->handover_status) {
                WarehouseStockCheck::HANDOVER_CONFIRMED   => 'success',
                WarehouseStockCheck::HANDOVER_DISCREPANCY => 'danger',
                default                                   => 'gray',
            })
            ->modalHeading('Xác nhận bàn giao ca — đếm lại tồn kho')
            ->modalDescription('Ca sau đếm lại đúng các vật tư trong phiếu này. Số mặc định = số đã đếm ở phiếu gốc — chỉ sửa thẻ nào đếm ra khác.')
            ->modalWidth('4xl')
            ->form(function (WarehouseStockCheck $record) {
                $record->loadMissing('items.item.unit');

                return [
                    // Cùng khối <style> tô viền thẻ + tên vật tư primary như "Chi tiết kiểm kê" —
                    // xem WarehouseCardStyle (lớp riêng .fi-warehouse-handover-repeater, không đụng
                    // repeater ở form khác).
                    Placeholder::make('warehouse_handover_repeater_style')
                        ->hiddenLabel()
                        ->content(WarehouseCardStyle::styleBlock('fi-warehouse-handover-repeater'))
                        ->extraAttributes(['class' => 'hidden']),

                    // Dạng LƯỚI thẻ giống hệt phần "Chi tiết kiểm kê" — KHÔNG dùng ->relationship()
                    // (chỉ là field mảng thuần để hiển thị/thu thập số liệu đối chiếu, không map
                    // trực tiếp vào model nào) nên ->default() hoạt động bình thường, không bị ghi
                    // đè như Repeater có ->relationship().
                    Repeater::make('handoverLines')
                        ->hiddenLabel()
                        ->extraAttributes(['class' => 'fi-warehouse-handover-repeater'])
                        ->schema([
                            Hidden::make('check_item_id')
                                ->required(),

                            // "Phiếu gốc" và "Đếm lại" — 2 Ô VUÔNG lớn nằm 2 bên (trái/phải), cùng
                            // kích thước như phần "Chi tiết kiểm kê" — xem WarehouseCardStyle. Xem
                            // giải thích 'default' => ... ở WarehouseItemForm — Grid::make(N) dạng số
                            // nguyên không tự có cột mobile, phải khai báo tường minh để 2 ô luôn
                            // nằm NGANG kể cả trên mobile.
                            Grid::make(['default' => 2, 'lg' => 2])
                                ->schema([
                                    Placeholder::make('original_display')
                                        ->label('Phiếu gốc')
                                        ->content(function (Get $get) use ($record) {
                                            $line = $record->items->firstWhere('id', $get('check_item_id'));

                                            return Number::format((float) ($line?->actual_quantity ?? 0), maxPrecision: 2);
                                        })
                                        ->extraAttributes(WarehouseCardStyle::neutralBoxCombined()),

                                    TextInput::make('handover_quantity')
                                        ->label('Đếm lại')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->live(onBlur: true)
                                        ->extraAttributes(function (Get $get) use ($record) {
                                            $line = $record->items->firstWhere('id', $get('check_item_id'));
                                            $diff = round((float) $get('handover_quantity') - (float) ($line?->actual_quantity ?? 0), 2);

                                            return WarehouseCardStyle::comparedBox($diff !== 0.0);
                                        })
                                        ->extraInputAttributes(WarehouseCardStyle::inputStyle()),
                                ]),
                        ])
                        ->itemLabel(function (array $state) use ($record) {
                            $line = $record->items->firstWhere('id', $state['check_item_id'] ?? null);

                            return $line?->item?->name ?? 'Vật tư';
                        })
                        ->grid(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->default($record->items->map(fn ($line) => [
                            'check_item_id'     => $line->id,
                            'handover_quantity' => (float) $line->actual_quantity,
                        ])->toArray()),

                    Textarea::make('handover_note')
                        ->label('Ghi chú bàn giao')
                        ->rows(2)
                        ->default($record->handover_note),
                ];
            })
            ->action(function (array $data, WarehouseStockCheck $record) {
                $record->loadMissing('items.item');

                $hasDiscrepancy = false;

                $handoverByCheckItemId = collect($data['handoverLines'] ?? [])->keyBy('check_item_id');

                DB::transaction(function () use ($handoverByCheckItemId, $record, &$hasDiscrepancy) {
                    foreach ($record->items as $line) {
                        $handoverQty = (float) ($handoverByCheckItemId[$line->id]['handover_quantity'] ?? $line->actual_quantity);
                        $diff        = round($handoverQty - (float) $line->actual_quantity, 2);

                        // updateQuietly() — KHÔNG bắn Eloquent event, tránh chạm vào logic tự điều
                        // chỉnh tồn kho ở WarehouseStockCheckItem::updated() (đúng như thiết kế: đây
                        // chỉ là bước đối chiếu, không phải điều chỉnh tồn kho).
                        $line->updateQuietly([
                            'handover_quantity'   => $handoverQty,
                            'handover_difference' => $diff,
                        ]);

                        if ($diff !== 0.0) {
                            $hasDiscrepancy = true;
                        }
                    }

                    $record->update([
                        'handover_status'       => $hasDiscrepancy ? WarehouseStockCheck::HANDOVER_DISCREPANCY : WarehouseStockCheck::HANDOVER_CONFIRMED,
                        'handover_confirmed_by' => auth()->id(),
                        'handover_confirmed_at' => now(),
                        'handover_note'         => $data['handover_note'] ?? null,
                    ]);
                });

                if ($hasDiscrepancy) {
                    Notification::make()
                        ->title('Có lệch khi bàn giao ca — xem báo cáo chi tiết')
                        ->warning()
                        ->send();

                    // Mở luôn popup báo cáo chi tiết (handoverReportAction bên dưới) ngay sau khi
                    // đóng modal xác nhận — không bắt người dùng phải tự bấm thêm nút nào khác.
                    $this->mountAction('handoverReport');
                } else {
                    Notification::make()
                        ->title('Đã xác nhận bàn giao — tồn kho khớp')
                        ->success()
                        ->send();
                }
            });
    }

    // Popup báo cáo chi tiết khi phát hiện lệch — hiện đủ: ai tạo phiếu gốc (ca trước), ai xác nhận
    // bàn giao (ca sau), và bảng đối chiếu từng vật tư (số phiếu gốc / số ca sau đếm lại / chênh
    // lệch). ->hidden() để không hiện thành nút riêng trên thanh header — chỉ được mở TỰ ĐỘNG từ
    // confirmHandoverAction() ở trên khi phát hiện lệch (qua $this->mountAction()), hoặc người dùng
    // tự mở lại sau này qua chính badge/trạng thái nếu cần xem lại (xem
    // WarehouseStockCheckTable — có thể bấm vào dòng "Có lệch bàn giao").
    protected function handoverReportAction(): Action
    {
        return Action::make('handoverReport')
            ->label('Báo cáo lệch bàn giao')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->modalHeading('Báo cáo lệch khi bàn giao ca')
            ->modalContent(fn (WarehouseStockCheck $record) => view(
                'warehouse::filament.handover-report',
                ['record' => $record->loadMissing(['items.item.unit', 'creator', 'handoverConfirmedBy'])]
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->visible(fn (WarehouseStockCheck $record) => $record->handover_status === WarehouseStockCheck::HANDOVER_DISCREPANCY);
    }
}
