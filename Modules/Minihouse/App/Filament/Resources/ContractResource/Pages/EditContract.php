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
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Services\ContractContentRenderer;
use Modules\Minihouse\App\Services\ContractEarlyEndService;

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
                        ->minValue(0)
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
                    //
                    // reprorateInvoicesOnEarlyEnd($preview=true) — hoá đơn tháng hiện tại có thể đã
                    // lập giả định ở tới hết tháng, cần tính lại ĐÚNG số ngày thực ở để gợi ý hoàn cọc
                    // không bị SAI (thiếu hụt nếu hoá đơn đó đã trả dư do rút ngắn ngày ở). CHỈ TÍNH,
                    // không ghi CSDL — form này render lại nhiều lần trước khi staff bấm xác nhận.
                    $unpaidTotal = (float) Invoice::where('contract_id', $record->id)
                        ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
                        ->get()
                        ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
                    $reproratedDelta = $this->reprorateInvoicesOnEarlyEnd($record, now(), preview: true);
                    $unpaidTotal = max(0, $unpaidTotal + $reproratedDelta);
                    $suggested = max(0, (float) $record->deposit_amount - $unpaidTotal);

                    return [
                        Placeholder::make('summary')
                            ->label('')
                            ->content(
                                'Tiền cọc: ' . number_format((float) $record->deposit_amount, 0, ',', '.') . 'đ — '
                                . 'Hoá đơn chưa thanh toán (đã tính lại theo đúng ngày trả phòng hôm nay): ' . number_format($unpaidTotal, 0, ',', '.') . 'đ — '
                                . 'Gợi ý hoàn cọc: ' . number_format($suggested, 0, ',', '.') . 'đ'
                            ),
                        DatePicker::make('checkout_at')
                            ->label('Ngày trả phòng thực tế')
                            ->native(false)
                            ->default(now())
                            ->required()
                            ->helperText('Đổi ngày này KHÔNG tự tính lại gợi ý hoàn cọc ở trên — số gợi ý luôn tính theo hôm nay, kiểm tra lại tay nếu chọn ngày khác.'),
                        TextInput::make('deposit_refunded_amount')
                            ->label('Số tiền cọc hoàn lại')
                            ->numeric()
                            ->minValue(0)
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
                            ->maxSize(10240)
                            ->columnSpanFull(),
                    ];
                })
                ->requiresConfirmation()
                ->modalDescription('Hợp đồng sẽ chuyển sang trạng thái "Hết hạn" và phòng tự động trả lại "Trống". Hoá đơn tháng hiện tại (nếu có) sẽ tự rút ngắn lại đúng theo ngày trả phòng — không tính tiền phòng cho những ngày không còn ở nữa.')
                ->action(function (array $data): void {
                    $this->record->update([
                        ...$data,
                        'status' => Contract::STATUS_EXPIRED,
                    ]);

                    $this->reprorateInvoicesOnEarlyEnd($this->record, Carbon::parse($data['checkout_at']));

                    $this->recordDepositRefundTransaction($this->record, (float) ($data['deposit_refunded_amount'] ?? 0), 'Hoàn cọc khi thanh lý hợp đồng #' . $this->record->id);

                    $this->refreshFormData(['status', 'checkout_at', 'deposit_refunded_amount', 'deposit_deduction_reason', 'checkout_handover_file']);

                    Notification::make()
                        ->title('Đã thanh lý hợp đồng')
                        ->success()
                        ->send();
                }),

            // Huỷ hợp đồng — trước đây Contract::STATUS_CANCELLED chỉ là 1 lựa chọn có thể chọn tay
            // ở field "Trạng thái" (tab Thông tin hợp đồng), không đi qua action/nghiệp vụ nào, nên
            // đã BỎ lựa chọn đó khỏi Select (xem ContractForm.php) — giờ CHỈ chuyển sang "Đã huỷ"
            // qua action này, đảm bảo luôn có lý do huỷ + xử lý hoàn cọc nhất quán như "Thanh lý"
            // (ContractObserver vẫn tự trả phòng về "Trống" y hệt, vì chỉ cần status khác 'active').
            // Dùng cho trường hợp chấm dứt hợp đồng SỚM/không tiếp tục thuê — khác "Thanh lý" (dùng
            // khi hợp đồng đã hết hạn/thuê xong bình thường) chỉ ở Ý NGHĨA + có ghi lý do huỷ, còn lại
            // xử lý hoàn cọc/trả phòng giống hệt nhau.
            Actions\Action::make('cancelContract')
                ->label('Huỷ hợp đồng')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === Contract::STATUS_ACTIVE)
                ->form(function () {
                    $record = $this->record;

                    // Xem chú thích ở checkoutContract — hoá đơn tháng hiện tại cần tính lại đúng số
                    // ngày thực ở trước khi gợi ý hoàn cọc.
                    $unpaidTotal = (float) Invoice::where('contract_id', $record->id)
                        ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
                        ->get()
                        ->sum(fn (Invoice $invoice) => $invoice->remainingAmount());
                    $reproratedDelta = $this->reprorateInvoicesOnEarlyEnd($record, now(), preview: true);
                    $unpaidTotal = max(0, $unpaidTotal + $reproratedDelta);
                    $suggested = max(0, (float) $record->deposit_amount - $unpaidTotal);

                    return [
                        Textarea::make('cancel_reason')
                            ->label('Lý do huỷ hợp đồng')
                            ->required()
                            ->columnSpanFull(),
                        Placeholder::make('summary')
                            ->label('')
                            ->content(
                                'Tiền cọc: ' . number_format((float) $record->deposit_amount, 0, ',', '.') . 'đ — '
                                . 'Hoá đơn chưa thanh toán (đã tính lại theo đúng ngày huỷ hôm nay): ' . number_format($unpaidTotal, 0, ',', '.') . 'đ — '
                                . 'Gợi ý hoàn cọc: ' . number_format($suggested, 0, ',', '.') . 'đ'
                            ),
                        DatePicker::make('checkout_at')
                            ->label('Ngày huỷ')
                            ->native(false)
                            ->default(now())
                            ->required()
                            ->helperText('Đổi ngày này KHÔNG tự tính lại gợi ý hoàn cọc ở trên — số gợi ý luôn tính theo hôm nay, kiểm tra lại tay nếu chọn ngày khác.'),
                        TextInput::make('deposit_refunded_amount')
                            ->label('Số tiền cọc hoàn lại')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('đ')
                            ->default($suggested),
                        Textarea::make('deposit_deduction_reason')
                            ->label('Lý do trừ cọc (nếu có)')
                            ->columnSpanFull(),
                    ];
                })
                ->requiresConfirmation()
                ->modalDescription('Hợp đồng sẽ chuyển sang trạng thái "Đã huỷ" và phòng tự động trả lại "Trống" — dùng cho trường hợp chấm dứt hợp đồng sớm, khác "Thanh lý" (hợp đồng đã hết hạn/thuê xong bình thường). Hoá đơn tháng hiện tại (nếu có) sẽ tự rút ngắn lại đúng theo ngày huỷ.')
                ->action(function (array $data): void {
                    // Contract chưa có cột riêng cho "lý do huỷ" — ghép chung vào deposit_deduction_
                    // reason (có tiền tố rõ ràng) thay vì thêm cột mới cho 1 dòng ghi chú.
                    $note = 'Lý do huỷ: ' . $data['cancel_reason'] . (filled($data['deposit_deduction_reason'] ?? null) ? '. Trừ cọc: ' . $data['deposit_deduction_reason'] : '');

                    $this->record->update([
                        'checkout_at'              => $data['checkout_at'],
                        'deposit_refunded_amount'  => $data['deposit_refunded_amount'] ?? 0,
                        'deposit_deduction_reason' => $note,
                        'status'                   => Contract::STATUS_CANCELLED,
                    ]);

                    $this->reprorateInvoicesOnEarlyEnd($this->record, Carbon::parse($data['checkout_at']));

                    $this->recordDepositRefundTransaction($this->record, (float) ($data['deposit_refunded_amount'] ?? 0), 'Hoàn cọc khi huỷ hợp đồng #' . $this->record->id);

                    $this->refreshFormData(['status', 'checkout_at', 'deposit_refunded_amount', 'deposit_deduction_reason']);

                    Notification::make()
                        ->title('Đã huỷ hợp đồng')
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
                            ->minValue(0)
                            ->prefix('đ')
                            ->required(),
                        Textarea::make('note')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ];
                })
                ->requiresConfirmation()
                ->modalDescription('Hợp đồng hiện tại sẽ kết thúc, phòng cũ tự trả về "Trống". Toàn bộ tiền cọc + người ở cùng + phụ thu (nếu chọn phòng cùng toà nhà) được mang sang hợp đồng mới cho phòng vừa chọn — KHÔNG hoàn cọc như "Thanh lý". Hoá đơn tháng hiện tại của phòng cũ (nếu có) sẽ tự rút ngắn lại đúng theo ngày chuyển phòng.')
                ->action(function (array $data): void {
                    $old = $this->record;

                    $result = DB::transaction(function () use ($old, $data) {
                        $newRoom     = Room::findOrFail($data['new_room_id']);
                        $sameBuilding = $newRoom->building_id === $old->room?->building_id;

                        $old->update([
                            'status'      => Contract::STATUS_EXPIRED,
                            'checkout_at' => $data['transfer_at'],
                        ]);

                        // Xem chú thích ở reprorateInvoicesOnEarlyEnd() — hoá đơn phòng CŨ có thể đã
                        // lập giả định ở tới hết tháng, phải rút ngắn lại đúng ngày chuyển phòng để
                        // không tính tiền phòng CHỒNG LẤN với hoá đơn phòng MỚI sắp lập cho đúng
                        // những ngày còn lại đó.
                        $reproratedDelta = $this->reprorateInvoicesOnEarlyEnd($old, Carbon::parse($data['transfer_at']));

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

                        return ['new_contract_id' => $new->id, 'room_code' => $newRoom->code, 'reproratedDelta' => $reproratedDelta];
                    });

                    // reproratedDelta ÂM = hoá đơn phòng cũ đã thu DƯ (rút ngắn lại làm giảm số tiền
                    // phải trả) — DƯƠNG = vừa tự lập bù 1 hoá đơn cho những ngày đã ở nhưng chưa được
                    // lập hoá đơn trước đó. Cả 2 trường hợp hệ thống KHÔNG tự chuyển tiền/trừ tự
                    // động — chỉ báo rõ để nhân viên tự xử lý.
                    $body = 'Hợp đồng mới đã được tạo cho phòng ' . $result['room_code'] . '.';

                    if ($result['reproratedDelta'] < -0.5) {
                        $body .= ' Hoá đơn phòng cũ đã thu DƯ ' . number_format(abs($result['reproratedDelta']), 0, ',', '.') . 'đ sau khi rút ngắn lại theo đúng ngày chuyển phòng — tự quyết định hoàn tiền mặt hay trừ vào hoá đơn đầu tiên của phòng mới.';
                    } elseif ($result['reproratedDelta'] > 0.5) {
                        $body .= ' Đã tự lập bù 1 hoá đơn ' . number_format($result['reproratedDelta'], 0, ',', '.') . 'đ cho những ngày ở phòng cũ TRƯỚC ĐÓ chưa được lập hoá đơn — kiểm tra lại hoá đơn của hợp đồng cũ (#' . $old->id . ').';
                    }

                    Notification::make()
                        ->title('Đã chuyển phòng')
                        ->body($body)
                        ->success()
                        ->persistent()
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

    // Hoá đơn của hợp đồng khi "Thanh lý"/"Huỷ"/"Chuyển phòng" xảy ra SỚM (giữa kỳ) có 2 CHIỀU lỗi
    // đối lập nhau, PHẢI xử lý cả 2 cùng lúc — chỉ sửa 1 chiều (như bản vá đầu tiên chỉ sửa chiều A)
    // vẫn còn sai với thực tế vận hành:
    //
    //  A) THU THỪA — hoá đơn tháng hiện tại đã được lập TỪ TRƯỚC (giả định ở tới hết tháng/hết hạn
    //     hợp đồng) mà không tự rút ngắn lại thì vẫn tính tiền phòng cho cả những ngày khách KHÔNG
    //     CÒN Ở NỮA. Lỗi thực tế phát hiện 2026-09-09: khách "Chuyển phòng" ngay trong ngày dọn vào,
    //     hoá đơn phòng CŨ vẫn tính đủ tới hết tháng (22 ngày), hợp đồng MỚI (phòng khác) lại tính
    //     tiếp ĐÚNG 22 ngày đó cho phòng mới — tính tiền phòng 2 LẦN cho cùng 1 khoảng thời gian.
    //
    //  B) THẤT THU (chiều ngược lại, dễ bị bỏ sót khi chỉ nghĩ tới chiều A) — nếu hoá đơn tháng hiện
    //     tại CHƯA được lập (chưa bấm "Lập hoá đơn hàng loạt") lúc kết thúc hợp đồng sớm, thì những
    //     ngày khách ĐÃ THỰC SỰ Ở sẽ KHÔNG BAO GIỜ được tính tiền — hợp đồng chuyển sang trạng thái
    //     khác 'active' nên mọi lần lập hoá đơn hàng loạt SAU NÀY đều bỏ qua nó vĩnh viễn (xem
    //     InvoiceGenerationService::generateForMonth() chỉ lấy status=active).
    //
    // Dùng chung cho cả 3 luồng kết thúc hợp đồng sớm (Thanh lý/Huỷ/Chuyển phòng).
    //
    // $preview=true: CHỈ TÍNH, không ghi CSDL — dùng để hiện đúng "Gợi ý hoàn cọc" trong modal TRƯỚC
    // khi staff xác nhận (Filament có thể render lại form nhiều lần, không được phép có side-effect
    // ghi dữ liệu ở nhánh này).
    //
    // @return float Tổng chênh lệch (total_amount mới − amount_paid, cộng dồn qua mọi hoá đơn bị rút
    //                ngắn, CỘNG THÊM tổng hoá đơn mới tạo ra để bù khoảng trống chưa lập) — DƯƠNG =
    //                còn thiếu thêm (hoá đơn mới tạo ra bù khoảng trống luôn dương vì chưa ai trả),
    //                ÂM = đã thu DƯ — staff tự quyết định hoàn tiền mặt hay trừ vào hợp đồng mới, hệ
    //                thống không tự động chuyển tiền.
    // Chuyển sang ContractEarlyEndService (dùng chung với ContractController API — tránh 2 nơi tự
    // viết lại cùng logic rồi lệch nhau, đúng nguyên nhân bản API port trước đây bị THIẾU HẲN bước
    // này). Giữ nguyên tên method + gọi lại từ 4 chỗ cũ (checkoutContract/cancelContract/
    // transferRoom) để không phải sửa các action() ở trên.
    private function reprorateInvoicesOnEarlyEnd(Contract $contract, Carbon $checkoutAt, bool $preview = false): float
    {
        return ContractEarlyEndService::reprorate($contract, $checkoutAt, $preview);
    }

    // Trước đây "Thanh lý/Hoàn cọc" chỉ cập nhật deposit_refunded_amount trên Contract — không tự
    // ghi vào sổ Thu Chi, khác hẳn InvoicePaymentObserver (mọi lần thanh toán hoá đơn đều tự tạo 1
    // dòng "Thu" tương ứng). Kết quả: tiền cọc hoàn thực tế đã chi ra KHÔNG hiện trong báo cáo Thu
    // Chi (FinanceReports), khiến dòng tiền báo cáo sai lệch. Giờ tự tạo 1 dòng "Chi" tương ứng mỗi
    // khi có hoàn cọc — không tạo dòng nếu số tiền hoàn = 0 (huỷ/thanh lý mà cọc bị trừ hết).
    private function recordDepositRefundTransaction(Contract $contract, float $amount, string $note): void
    {
        if ($amount <= 0) {
            return;
        }

        Transaction::create([
            'contract_id'      => $contract->id,
            'building_id'      => $contract->room?->building_id,
            'type'             => Transaction::TYPE_OUT,
            'category'         => Transaction::CATEGORY_DEPOSIT_REFUND,
            'amount'           => $amount,
            'transaction_date' => $contract->checkout_at ?? now(),
            'note'             => $note,
        ]);
    }
}
