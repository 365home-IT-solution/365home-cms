<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;
use Modules\Category\Entities\Category;
use Modules\Product\App\Filament\Resources\ManualLockPasswordResource;
use Modules\Product\App\Imports\ManualLockPasswordExcelImport;

class ListManualLockPasswords extends ListRecords
{
    protected static string $resource = ManualLockPasswordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importExcel')
            ->label('Import Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->modalHeading('Import mật khẩu từ Excel')
            ->modalDescription(new HtmlString(
                '<div class="text-sm text-gray-500 space-y-1">'
                . '<p>File Excel phải có cấu trúc:</p>'
                . '<ul class="list-disc pl-4 mt-1 space-y-0.5">'
                . '<li><strong>Cột A</strong>: Tên phòng (dòng đầu = "Tên phòng", dòng cuối = "Pass Cổng")</li>'
                . '<li><strong>Cột B, C, …</strong>: Ngày (hàng 1 chứa ngày, các ô bên dưới là mật khẩu phòng, dòng Pass Cổng là mật khẩu cổng)</li>'
                . '</ul>'
                . '</div>'
            ))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Import ngay')
            ->form([
                Section::make('File Excel')
                    ->icon('heroicon-o-document-arrow-up')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Chọn file Excel (.xlsx / .xls)')
                            ->required()
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                '.xlsx',
                                '.xls',
                            ])
                            ->disk('local')
                            ->directory('imports/manual-lock-passwords')
                            ->preserveFilenames(false)
                            ->helperText('Tối đa 10MB'),
                    ]),

                Section::make('Thiết lập thời hạn hiệu lực chung')
                    ->icon('heroicon-o-clock')
                    ->iconColor('info')
                    ->description('Áp dụng cho tất cả bản ghi được import. Mỗi ngày trong cột sẽ có khoảng thời gian hiệu lực theo cấu hình bên dưới.')
                    ->schema([
                        Select::make('category_id')
                            ->label('Chi nhánh')
                            ->options(function () {
                                $user  = auth()->user();
                                $query = Category::query()
                                    ->where('category_type', 'product')
                                    ->orderBy('name');
                                if ($user && ! $user->isSuperAdmin()) {
                                    $ids = $user->allowedCategoryIds();
                                    if (empty($ids)) return [];
                                    $query->whereIn('id', $ids);
                                }
                                return $query->pluck('name', 'id');
                            })
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->helperText('Chi nhánh sẽ được gán cho tất cả bản ghi được tạo'),

                        Grid::make(2)->schema([
                            TimePicker::make('valid_from_time')
                                ->label('Bắt đầu hiệu lực lúc')
                                ->default('06:00')
                                ->seconds(false)
                                ->helperText('Giờ bắt đầu trong ngày của cột đó'),

                            TimePicker::make('valid_until_time')
                                ->label('Hết hạn lúc')
                                ->default('12:00')
                                ->seconds(false)
                                ->helperText('Giờ hết hạn trong ngày của cột đó'),
                        ]),
                    ]),

                Section::make('Thiết lập khớp phòng')
                    ->icon('heroicon-o-home')
                    ->iconColor('primary')
                    ->schema([
                        Select::make('match_by')
                            ->label('Khớp phòng theo')
                            ->options([
                                'name' => 'Tên phòng (name — khớp linh hoạt)',
                                'slug' => 'Slug (chính xác tuyệt đối)',
                            ])
                            ->default('name')
                            ->native(false)
                            ->helperText('Chọn "Tên phòng" nếu tên trong Excel là tên viết tắt/gần đúng'),

                        Toggle::make('skip_existing')
                            ->label('Bỏ qua bản ghi đã tồn tại')
                            ->default(true)
                            ->helperText('Nếu tắt, sẽ tạo thêm bản ghi mới dù đã có bản ghi trùng ngày & cổng'),
                    ]),
            ])
            ->action(function (array $data): void {
                $filePath = storage_path('app/imports/manual-lock-passwords/' . basename($data['file']));

                if (! file_exists($filePath)) {
                    Notification::make()
                        ->title('Không tìm thấy file đã upload')
                        ->danger()
                        ->send();
                    return;
                }

                $importer = new ManualLockPasswordExcelImport(
                    categoryId:     (int) $data['category_id'],
                    validFromTime:  $data['valid_from_time'],
                    validUntilTime: $data['valid_until_time'],
                    matchBy:        $data['match_by'],
                    skipExisting:   (bool) $data['skip_existing'],
                );

                $result = $importer->handle($filePath);

                // Clean up uploaded file
                @unlink($filePath);

                if (! empty($result['errors'])) {
                    Notification::make()
                        ->title('Import hoàn tất nhưng có lỗi')
                        ->body(implode("\n", array_slice($result['errors'], 0, 5)))
                        ->warning()
                        ->persistent()
                        ->send();
                }

                if (! empty($result['notFound'])) {
                    Notification::make()
                        ->title('Không tìm thấy ' . count($result['notFound']) . ' phòng')
                        ->body('Phòng không khớp: ' . implode(', ', $result['notFound']))
                        ->warning()
                        ->persistent()
                        ->send();
                }

                Notification::make()
                    ->title('Import thành công')
                    ->body(
                        "Đã tạo: **{$result['created']}** bản ghi"
                        . ($result['skipped'] > 0 ? ", bỏ qua: **{$result['skipped']}** bản ghi trùng" : '')
                    )
                    ->success()
                    ->send();
            });
    }
}
