<?php

namespace Modules\Minihouse\App\Filament\Resources\ContractResource\Pages;

use App\Services\PdfSigning\ContractPdfRenderer;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Minihouse\App\Filament\Resources\ContractResource;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Services\ContractContentRenderer;

class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Gia hạn hợp đồng — thay vì sửa tay end_date/monthly_price ở tab "Thông tin hợp đồng"
            // (không giữ lại lịch sử giá/ngày cũ), action này lưu thêm 1 dòng nhật ký vào
            // minihouse_contract_renewals trước khi cập nhật, để sau này còn tra lại được đã gia
            // hạn bao nhiêu lần, giá cũ là bao nhiêu.
            Actions\Action::make('renewContract')
                ->label('Gia hạn hợp đồng')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->visible(fn () => $this->record->status === Contract::STATUS_ACTIVE)
                ->form([
                    DatePicker::make('new_end_date')
                        ->label('Ngày kết thúc mới')
                        ->native(false)
                        ->required()
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if ($this->record->end_date && Carbon::parse($value)->lte($this->record->end_date)) {
                                $fail('Ngày kết thúc mới phải sau ngày kết thúc hiện tại (' . $this->record->end_date->format('d/m/Y') . ').');
                            }
                        }),
                    TextInput::make('new_monthly_price')
                        ->label('Giá thuê / tháng (mới)')
                        ->numeric()
                        ->prefix('đ')
                        ->required()
                        ->default(fn () => $this->record->monthly_price),
                    Textarea::make('note')
                        ->label('Ghi chú gia hạn')
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $record = $this->record;

                    $record->renewals()->create([
                        'old_end_date'      => $record->end_date,
                        'new_end_date'      => $data['new_end_date'],
                        'old_monthly_price' => $record->monthly_price,
                        'new_monthly_price' => $data['new_monthly_price'],
                        'note'              => $data['note'] ?? null,
                        'created_by'        => auth()->id(),
                    ]);

                    $record->update([
                        'end_date'      => $data['new_end_date'],
                        'monthly_price' => $data['new_monthly_price'],
                    ]);

                    $this->refreshFormData(['end_date', 'monthly_price']);

                    Notification::make()
                        ->title('Đã gia hạn hợp đồng')
                        ->body('Ngày kết thúc mới: ' . Carbon::parse($data['new_end_date'])->format('d/m/Y'))
                        ->success()
                        ->send();
                }),

            // Thanh lý hợp đồng / hoàn cọc — trước đây chỉ có các ô nhập tay rời rạc ở tab "Thanh lý
            // hợp đồng" (checkout_at/deposit_refunded_amount...), dễ quên đổi trạng thái khiến phòng
            // không được trả lại (xem ContractForm's checkout_at ->afterStateUpdated cho trường hợp
            // sửa tay). Action này gộp thành 1 luồng: tự gợi ý số tiền hoàn cọc (cọc − hoá đơn chưa
            // thanh toán), rồi cập nhật đủ 1 lần + luôn đổi status='expired' để ContractObserver tự
            // trả phòng.
            Actions\Action::make('checkoutContract')
                ->label('Thanh lý / Hoàn cọc')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->visible(fn () => $this->record->status === Contract::STATUS_ACTIVE)
                ->form(function () {
                    $record = $this->record;

                    // Phải tính theo SỐ CÒN LẠI (total_amount - amount_paid) và gồm cả hoá đơn
                    // "partial" — chỉ lọc status=unpaid + sum(total_amount) sẽ bỏ sót hoá đơn trả 1
                    // phần, khiến gợi ý hoàn cọc bị TÍNH THỪA (hoàn nhiều hơn số thực còn được nhận).
                    $unpaidTotal = (float) Invoice::where('contract_id', $record->id)
                        ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
                        ->get()
                        ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
                    $suggested = max(0, (float) $record->deposit_amount - $unpaidTotal);

                    return [
                        Placeholder::make('summary')
                            ->label('')
                            ->content(
                                'Tiền cọc: ' . number_format((float) $record->deposit_amount, 0, ',', '.') . 'đ — '
                                . 'Hoá đơn chưa thanh toán: ' . number_format($unpaidTotal, 0, ',', '.') . 'đ — '
                                . 'Gợi ý hoàn cọc: ' . number_format($suggested, 0, ',', '.') . 'đ'
                            ),
                        DatePicker::make('checkout_at')
                            ->label('Ngày trả phòng thực tế')
                            ->native(false)
                            ->default(now())
                            ->required(),
                        TextInput::make('deposit_refunded_amount')
                            ->label('Số tiền cọc hoàn lại')
                            ->numeric()
                            ->prefix('đ')
                            ->default($suggested)
                            ->helperText('Đã tự trừ hoá đơn chưa thanh toán khỏi tiền cọc — sửa lại nếu có thêm khoản trừ khác (hư hỏng...).'),
                        Textarea::make('deposit_deduction_reason')
                            ->label('Lý do trừ cọc (nếu có)')
                            ->columnSpanFull(),
                        FileUpload::make('checkout_handover_file')
                            ->label('Biên bản bàn giao lúc trả phòng')
                            ->directory('minihouse/contracts')
                            ->disk('public')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->columnSpanFull(),
                    ];
                })
                ->requiresConfirmation()
                ->modalDescription('Hợp đồng sẽ chuyển sang trạng thái "Hết hạn" và phòng tự động trả lại "Trống".')
                ->action(function (array $data): void {
                    $this->record->update([
                        ...$data,
                        'status' => Contract::STATUS_EXPIRED,
                    ]);

                    $this->refreshFormData(['status', 'checkout_at', 'deposit_refunded_amount', 'deposit_deduction_reason', 'checkout_handover_file']);

                    Notification::make()
                        ->title('Đã thanh lý hợp đồng')
                        ->success()
                        ->send();
                }),

            // Chuyển phòng — khác "Thanh lý/Hoàn cọc": hợp đồng CŨ vẫn kết thúc (status=expired, tự
            // trả phòng cũ về "Trống" qua ContractObserver) nhưng KHÔNG hoàn cọc, mà tạo NGAY 1 hợp
            // đồng MỚI cho phòng mới, mang theo tiền cọc + người ở cùng + phụ thu (nếu cùng toà nhà)
            // — coi như 1 lần thuê liên tục, chỉ đổi phòng, không phải kết thúc rồi thuê lại từ đầu.
            // 2 hợp đồng nối nhau qua transferred_to/from_contract_id (xem Contract::transferredTo/
            // From) để tra lại lịch sử.
            Actions\Action::make('transferRoom')
                ->label('Chuyển phòng')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('gray')
                ->visible(fn () => $this->record->status === Contract::STATUS_ACTIVE)
                ->form(function () {
                    $record = $this->record;

                    return [
                        Select::make('new_room_id')
                            ->label('Phòng mới')
                            ->options(fn () => Room::query()
                                ->where('status', Room::STATUS_EMPTY)
                                ->where('id', '!=', $record->room_id)
                                ->with('building')
                                ->get()
                                ->mapWithKeys(fn (Room $room) => [$room->id => "{$room->building?->name} - {$room->code}"]))
                            ->searchable()
                            ->required()
                            ->live()
                            ->helperText('Chỉ hiện phòng đang "Trống" (khác toà nhà cũng chọn được).')
                            ->afterStateUpdated(fn (Select $component, Set $set, $state) => $set('new_monthly_price', Room::find($state)?->price)),
                        DatePicker::make('transfer_at')
                            ->label('Ngày chuyển phòng')
                            ->native(false)
                            ->default(now())
                            ->required(),
                        TextInput::make('new_monthly_price')
                            ->label('Giá thuê / tháng (phòng mới)')
                            ->numeric()
                            ->prefix('đ')
                            ->required(),
                        Textarea::make('note')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ];
                })
                ->requiresConfirmation()
                ->modalDescription('Hợp đồng hiện tại sẽ kết thúc, phòng cũ tự trả về "Trống". Toàn bộ tiền cọc + người ở cùng + phụ thu (nếu chọn phòng cùng toà nhà) được mang sang hợp đồng mới cho phòng vừa chọn — KHÔNG hoàn cọc như "Thanh lý".')
                ->action(function (array $data): void {
                    $old = $this->record;

                    $result = DB::transaction(function () use ($old, $data) {
                        $newRoom     = Room::findOrFail($data['new_room_id']);
                        $sameBuilding = $newRoom->building_id === $old->room?->building_id;

                        $old->update([
                            'status'      => Contract::STATUS_EXPIRED,
                            'checkout_at' => $data['transfer_at'],
                        ]);

                        $new = Contract::create([
                            'room_id'                       => $newRoom->id,
                            'tenant_id'                     => $old->tenant_id,
                            'start_date'                    => $data['transfer_at'],
                            'end_date'                      => $old->end_date,
                            'monthly_price'                 => $data['new_monthly_price'],
                            'deposit_amount'                => $old->deposit_amount,
                            'status'                         => Contract::STATUS_ACTIVE,
                            // Không mang theo đơn giá điện/nước RIÊNG của hợp đồng cũ nếu khác toà
                            // nhà — để trống thì InvoiceGenerationService/InvoiceForm tự lấy đơn giá
                            // mặc định của TOÀ NHÀ MỚI, tránh áp nhầm đơn giá của toà cũ.
                            'electric_unit_price'           => $sameBuilding ? $old->electric_unit_price : null,
                            'water_unit_price'               => $sameBuilding ? $old->water_unit_price : null,
                            'transferred_from_contract_id'  => $old->id,
                        ]);

                        $old->update(['transferred_to_contract_id' => $new->id]);

                        // Mang theo người ở cùng — vẫn cùng nhóm người thuê, chỉ đổi phòng.
                        foreach ($old->occupantEntries as $occupant) {
                            ContractTenant::create([
                                'contract_id'             => $new->id,
                                'tenant_id'               => $occupant->tenant_id,
                                'role'                    => ContractTenant::ROLE_OCCUPANT,
                                'relationship_to_primary' => $occupant->relationship_to_primary,
                            ]);
                        }

                        // Phụ thu định kỳ chỉ mang theo được nếu phòng mới CÙNG toà nhà — Surcharge
                        // là danh mục riêng theo từng toà, phụ thu của toà cũ không hợp lệ cho toà
                        // khác.
                        if ($sameBuilding) {
                            $new->surcharges()->sync($old->surcharges()->pluck('minihouse_surcharges.id'));
                        }

                        return ['new_contract_id' => $new->id, 'room_code' => $newRoom->code];
                    });

                    Notification::make()
                        ->title('Đã chuyển phòng')
                        ->body('Hợp đồng mới đã được tạo cho phòng ' . $result['room_code'])
                        ->success()
                        ->send();

                    $this->redirect(ContractResource::getUrl('edit', ['record' => $result['new_contract_id']]));
                }),

            // LƯU toàn bộ thay đổi đang có trên form (mọi tab — giống hệt bấm "Lưu thay đổi"), rồi
            // sinh lại "Nội dung hợp đồng" từ đúng dữ liệu vừa lưu (phòng/toà nhà, khách thuê +
            // người ở cùng, giá thuê, đơn giá điện/nước, phụ thu định kỳ) — xem
            // ContractContentRenderer. GHI ĐÈ TOÀN BỘ nội dung đang có, kể cả phần đã sửa tay trong
            // RichEditor — có confirm trước khi bấm. Form không hợp lệ (thiếu field bắt buộc...) thì
            // dừng lại báo lỗi y hệt bấm "Lưu thay đổi", không tự sinh nội dung.
            Actions\Action::make('regenerateContractContent')
                ->label('Cập nhật nội dung hợp đồng')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Toàn bộ thay đổi bạn vừa nhập ở các tab sẽ được LƯU LẠI, sau đó nội dung hợp đồng sẽ được sinh lại theo đúng dữ liệu đó. Phần bạn đã tự sửa tay trong "Nội dung hợp đồng" sẽ bị mất.')
                ->action(function (): void {
                    // save() validate + lưu y hệt nút "Lưu thay đổi" — dừng lại báo lỗi ở đây nếu
                    // form có field bắt buộc bị thiếu/sai, KHÔNG sinh nội dung với dữ liệu chưa lưu.
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    $record = $this->record->fresh();
                    $html   = ContractContentRenderer::render($record);

                    // Ghi thẳng contract_content xuống CSDL (record đã lưu ở bước trên rồi, không
                    // cần validate lại cả form) — rồi data_set() để RichEditor trên màn hình hiện
                    // ngay nội dung mới, không cần tải lại trang.
                    $record->update(['contract_content' => $html]);
                    data_set($this->data, 'contract_content', $html);

                    Notification::make()
                        ->title('Đã lưu thay đổi và cập nhật nội dung hợp đồng')
                        ->success()
                        ->send();
                }),

            // Mở PDF ở tab mới — route thường (KHÔNG qua Livewire), xem lý do ở
            // ContractPrintController. In từ trình xem PDF của trình duyệt (Ctrl+P).
            Actions\Action::make('printContract')
                ->label('In')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->tooltip('In đúng nội dung ĐÃ LƯU ở tab "Nội dung hợp đồng" — lưu thay đổi trước nếu vừa sửa.')
                ->url(fn () => URL::route('minihouse.contracts.print', ['contract' => $this->record]))
                ->openUrlInNewTab(),

            // Tải file PDF về máy — dùng response()->streamDownload() qua Livewire action (đúng
            // cách Warehouse module đang làm cho phiếu xuất/nhập kho, xem WarehousePrinter).
            Actions\Action::make('downloadContractPdf')
                ->label('Xuất PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->tooltip('Xuất đúng nội dung ĐÃ LƯU ở tab "Nội dung hợp đồng" — lưu thay đổi trước nếu vừa sửa.')
                ->action(function () {
                    $record = $this->record->fresh();
                    $pdf    = ContractPdfRenderer::render(ContractContentRenderer::renderPrintable($record));

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        "hop-dong-{$record->id}.pdf",
                        ['Content-Type' => 'application/pdf']
                    );
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
