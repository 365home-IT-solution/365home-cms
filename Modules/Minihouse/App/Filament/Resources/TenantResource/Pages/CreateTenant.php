<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\TenantResource;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Support\CccdScanMapper;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    // Dữ liệu hợp đồng "tạo kèm" lấy từ 4 field new_* trên TenantForm (chỉ hiện lúc Tạo mới) — tách
    // ra khỏi $data ở mutateFormDataBeforeCreate() (Tenant model không có các cột này) rồi dùng lại
    // ở afterCreate() khi đã có $this->record (Tenant vừa tạo, cần tenant_id cho Contract).
    private ?array $pendingContract = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (filled($data['new_room_id'] ?? null)) {
            $this->pendingContract = [
                'room_id'        => $data['new_room_id'],
                'monthly_price'  => $data['new_monthly_price'] ?? Room::find($data['new_room_id'])?->price,
                'start_date'     => $data['new_start_date'] ?? now(),
                'deposit_amount' => $data['new_deposit_amount'] ?? 0,
            ];
        }

        unset($data['new_room_id'], $data['new_monthly_price'], $data['new_start_date'], $data['new_deposit_amount']);

        return $data;
    }

    // Tạo Contract NGAY SAU khi Tenant đã lưu — dùng đúng Contract::create() (không tự làm lại logic
    // Room.status/Tenant.room_id ở đây) để ContractObserver::created() tự chạy y hệt tạo hợp đồng
    // thật qua ContractResource: đổi phòng "Đã thuê", gán "Phòng đang ở" cho khách, và tự sinh Khai
    // báo lưu trú.
    protected function afterCreate(): void
    {
        if (! $this->pendingContract) {
            return;
        }

        $contract = Contract::create([
            ...$this->pendingContract,
            'tenant_id' => $this->record->id,
            'status'    => Contract::STATUS_ACTIVE,
        ]);

        Notification::make()
            ->title('Đã tự tạo hợp đồng thuê phòng ' . ($contract->room?->code ?? ''))
            ->body('Vào trang Hợp đồng nếu cần bổ sung thêm (người ở cùng, phụ thu, lý do lưu trú...).')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Action cấp trang (đọc/ghi qua $this->form->getState()/fill()) — KHÔNG dùng
            // Filament\Forms\Components\Actions gắn trong field như bản trước, cơ chế đó
            // (mountFormComponentAction qua /livewire/update) bị lỗi 419 dù đã thử tab ẩn
            // danh hoàn toàn mới. Đây đúng kiểu action đã chạy ổn định ở EditTenant.
            Actions\Action::make('scanCccd')
                ->label('Quét CCCD')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->action(function (): void {
                    // getRawState() — KHÔNG dùng getState() (sẽ validate toàn bộ form, chặn ngay
                    // vì các field required như "Họ tên" chưa nhập lúc mới tải ảnh CCCD lên).
                    $data = $this->form->getRawState();

                    $scan = CccdScanMapper::scan($data['id_card_front'] ?? null, $data['id_card_back'] ?? null);

                    if (! $scan) {
                        Notification::make()
                            ->title('Không đọc được thông tin từ ảnh CCCD')
                            ->body('Ảnh quá mờ/nhỏ hoặc chưa tải ảnh. Vui lòng tải ảnh gốc chất lượng cao ở mục "Ảnh giấy tờ tuỳ thân" rồi thử lại.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $updates = CccdScanMapper::mapToTenantFields($scan);

                    if (empty($updates)) {
                        return;
                    }

                    // Ghi THẲNG từng field chữ vào $this->data — KHÔNG dùng $this->form->fill()
                    // với nguyên state cũ: fill() ghi đè lại CẢ 2 field ảnh id_card_front/back bằng
                    // đúng giá trị tạm vừa đọc ra, làm đứt liên kết ảnh đang chờ tải lên (upload tạm
                    // của Livewire) phía trình duyệt — ảnh biến mất khỏi form dù chưa bấm Tạo. Chỉ
                    // set đúng những field chữ cần điền, không đụng tới 2 field ảnh.
                    foreach ($updates as $field => $value) {
                        data_set($this->data, $field, $value instanceof \Carbon\Carbon ? $value->toDateString() : $value);
                    }

                    $labels = [
                        'fullname'          => 'Họ tên',
                        'id_card_number'    => 'Số CCCD',
                        'date_of_birth'     => 'Ngày sinh',
                        'gender'            => 'Giới tính',
                        'permanent_address' => 'Nơi thường trú',
                    ];

                    $note = collect($updates)
                        ->map(fn ($value, $field) => ($labels[$field] ?? $field) . ': ' . ($value instanceof \Carbon\Carbon ? $value->format('d/m/Y') : $value))
                        ->implode("\n");

                    Notification::make()
                        ->title('Đã tự điền từ CCCD')
                        ->body($note)
                        ->success()
                        ->send();
                }),
        ];
    }
}
