<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Filament\Resources\InvoiceResource;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Services\InvoiceGenerationService;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Xem InvoiceGenerationService — tự tính tiền phòng (prorate theo ngày), đơn giá điện/
            // nước, phụ thu định kỳ cho MỌI hợp đồng "Đang hiệu lực" chưa có hoá đơn tháng được
            // chọn. Chỉ số điện/nước để trống (chưa đọc đồng hồ), nhân viên tự bổ sung sau.
            Actions\Action::make('bulkGenerateInvoices')
                ->label('Lập hoá đơn hàng loạt')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn () => InvoiceResource::canCreate())
                ->form([
                    DatePicker::make('month')
                        ->label('Tháng lập hoá đơn')
                        ->displayFormat('m/Y')
                        ->native(false)
                        ->default(now())
                        ->required(),
                    // KHÔNG dùng ->relationship() — đây là field lọc tạm cho action, không map vào
                    // cột/quan hệ thật nào của Invoice.
                    Select::make('building_ids')
                        ->label('Toà nhà')
                        ->options(fn () => Building::pluck('name', 'id'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Để trống = lập cho tất cả toà nhà (trong phạm vi bạn được quản lý).'),
                ])
                ->requiresConfirmation()
                ->modalDescription('Tự tạo hoá đơn cho mọi hợp đồng "Đang hiệu lực" trong tháng chọn, CHƯA có hoá đơn tháng đó — hợp đồng đã có hoá đơn tháng này sẽ được bỏ qua, không tạo trùng. Chỉ số điện/nước để trống, cần bổ sung sau khi đọc đồng hồ.')
                ->action(function (array $data): void {
                    // ->visible() chỉ ẩn nút trên giao diện, không chặn được gọi action trực tiếp
                    // qua Livewire — phải tự kiểm tra quyền lại trong action() vì thao tác này tạo
                    // thật dữ liệu (Invoice) chứ không chỉ hiển thị.
                    abort_unless(InvoiceResource::canCreate(), 403);

                    $month  = Carbon::parse($data['month'])->startOfMonth();
                    $result = InvoiceGenerationService::generateForMonth($month, $data['building_ids'] ?: null);

                    Notification::make()
                        ->title('Đã lập hoá đơn hàng loạt')
                        ->body("Tháng {$month->format('m/Y')}: tạo {$result['created']->count()} hoá đơn, bỏ qua {$result['skipped']->count()} hợp đồng đã có hoá đơn tháng này.")
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
