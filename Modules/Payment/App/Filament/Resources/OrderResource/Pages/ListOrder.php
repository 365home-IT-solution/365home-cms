<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Pages;

use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Payment\App\Exports\OrdersExport;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Filament\Forms\Components\DatePicker;
use Modules\Payment\App\Exports\CustomerOrderCountExport;

class ListOrder extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->visible(fn () => auth()->user()?->can('create_order') ?? false),

            // ACTION: QUẢN LÝ DỊCH VỤ (CARD ĐÓNG MẶC ĐỊNH)
            Actions\Action::make('manage_addition_services')
                ->label('Quản lý Dịch vụ')
                ->icon('heroicon-o-squares-2x2')
                ->color('info')
                ->visible(fn () => auth()->user()?->can('update_order') ?? false)
                ->modalHeading('Danh sách Dịch vụ Bổ sung')
                ->modalWidth('7xl')
                ->modalSubmitActionLabel('Lưu tất cả thay đổi')
                
                ->fillForm(fn () => [
                    'services' => AdditionService::all()->toArray(),
                ])

                ->form([
                    Repeater::make('services')
                        ->label('Danh sách dịch vụ')
                        ->schema([
                            Hidden::make('id'),

                        FileUpload::make('image')
                            ->label('Ảnh dịch vụ')
                            ->image()
                            ->directory('addition-services')
                            ->required()
                            ->imageEditor()                    // Bật editor chỉnh ảnh
                            ->imageEditorAspectRatios([        // Tỉ lệ khung hình
                                null,                          // Tự do
                                '16:9',
                                '4:3',
                                '1:1',
                            ])
                            ->imageCropAspectRatio('1:1')      // Mặc định crop vuông
                            ->imageResizeMode('cover')         // Chế độ resize
                            ->imageResizeTargetWidth('800')    // Chiều rộng target
                            ->imageResizeTargetHeight('800')   // Chiều cao target
                            ->imageEditorViewportWidth('1920') // Viewport editor
                            ->imageEditorViewportHeight('1080'),

                            TextInput::make('name')
                                ->label('Tên dịch vụ')
                                ->required()
                                ->live(onBlur: true), // Cập nhật tiêu đề card ngay khi nhập xong tên

                            TextInput::make('price')
                                ->label('Đơn giá')
                                ->numeric()
                                ->prefix('₫')
                                ->required(),

                            Toggle::make('is_active')
                                ->label('Kích hoạt')
                                ->default(true)
                                ->inline(false),
                        ])
                        ->grid(4)             // Chia 4 cột
                        ->collapsible()       // Cho phép đóng/mở
                        ->collapsed()         // <--- QUAN TRỌNG: Mặc định đóng lại khi mở modal
                        ->cloneable()         // Cho phép nhân bản nhanh một dịch vụ
                        ->addActionLabel('Thêm dịch vụ mới')
                        // Hiển thị tên dịch vụ lên thanh tiêu đề khi card đang đóng
                        ->itemLabel(fn (array $state): ?string => ($state['name'] ?? 'Dịch vụ mới') . ($state['price'] ? ' - ' . number_format($state['price']) . '₫' : ''))
                        ->reorderable(false),
                ])

                ->action(function (array $data) {
                    $currentIds = collect($data['services'])->pluck('id')->filter()->toArray();

                    // Xóa bản ghi bị người dùng gỡ bỏ
                    AdditionService::whereNotIn('id', $currentIds)->delete();

                    // Lưu/Cập nhật dữ liệu
                    foreach ($data['services'] as $item) {
                        AdditionService::updateOrCreate(
                            ['id' => $item['id'] ?? null],
                            [
                                'name'      => $item['name'],
                                'price'     => $item['price'],
                                'image'     => $item['image'],
                                'is_active' => $item['is_active'],
                            ]
                        );
                    }
                }),


            // ACTION: XUẤT SỐ LẦN ĐẶT KHÁCH HÀNG
            Actions\Action::make('export_customer_order_count')
                ->label('Xuất Số lần đặt')
                ->icon('heroicon-o-user-group')
                ->color('warning')
                ->modalHeading('Xuất báo cáo số lần đặt của khách hàng')
                ->modalDescription('Thống kê tổng số lần đặt theo từng khách hàng (nhóm theo số điện thoại).')
                ->modalSubmitActionLabel('Xuất Excel')
                ->form([
                    DateTimePicker::make('date_from')
                        ->label('Từ ngày')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i')
                        ->helperText('Để trống = lấy toàn bộ lịch sử'),
 
                    DateTimePicker::make('date_to')
                        ->label('Đến ngày')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i')
                        ->helperText('Để trống = đến hiện tại'),
 
                    Select::make('status')
                        ->label('Lọc theo trạng thái')
                        ->placeholder('Tất cả trạng thái')
                        ->options([
                            'pending'   => 'Đang chờ',
                            'paid'      => 'Đã thanh toán',
                            'failed'    => 'Thất bại',
                        ]),
                ])
                ->action(function (array $data) {
                    $fileName = 'so_lan_dat_khach_hang_' . now()->format('Y-m-d_His') . '.xlsx';
                    return Excel::download(new CustomerOrderCountExport($data), $fileName);
                }),

            // ACTION: XUẤT EXCEL (GIỮ NGUYÊN)
            Actions\Action::make('export')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->color('success')
                ->form([
                  DateTimePicker::make('date_from')
                    ->label('Từ ngày')
                    ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i'),
                DateTimePicker::make('date_to')
                    ->label('Đến ngày')
                    ->native(false)
                            ->seconds(false)
                            ->timezone('Asia/Ho_Chi_Minh')
                            ->displayFormat('d/m/Y H:i'),
                    Select::make('status')->label('Trạng thái')->options(['pending' => 'Đang chờ','paid' => 'Đã thanh toán','deposit' => 'Đã đặt cọc','failed' => 'Thất bại','cancelled_payment' => 'Hủy QR']),
                    Select::make('payment_method')->label('Phương thức thanh toán')->options(['PayOS' => 'PayOS','cod' => 'Tiền mặt']),
                ])
                ->action(function (array $data) {
                    $fileName = 'orders_' . now()->format('Y-m-d_His') . '.xlsx';
                    return Excel::download(new OrdersExport($data), $fileName);
                }),
        ];
    }
}