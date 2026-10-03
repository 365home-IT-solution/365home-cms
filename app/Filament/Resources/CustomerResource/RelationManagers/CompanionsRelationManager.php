<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\CustomerResource;
use App\Models\CustomerCompanion;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

// Khách đi cùng đã lưu trong hồ sơ khách hàng (customer_companions) — cùng quy tắc với CCCD của
// chính khách: chỉ nhận dữ liệu đọc từ mã QR, ảnh không đọc được QR thì không lưu.
class CompanionsRelationManager extends RelationManager
{
    protected static string $relationship = 'companions';

    protected static ?string $title = 'Khách đi cùng';

    protected static ?string $modelLabel = 'khách đi cùng';

    protected static ?string $pluralModelLabel = 'khách đi cùng';

    protected static ?string $icon = 'heroicon-o-user-group';

    // Dữ liệu QR của ảnh vừa tải lên (rule của field ghi lúc validate) — action Thêm/Sửa lấy ra ghi
    // vào cccd_data + full_name trong cùng request lưu.
    private static ?array $scannedCccdData = null;

    public function form(Form $form): Form
    {
        return $form
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                FileUpload::make('cccd_qr_image')
                    ->label('Ảnh CCCD mặt có mã QR')
                    ->required()
                    ->image()
                    ->disk('public')
                    ->directory(config('cccd.qr_image_directory'))
                    ->maxSize(10240)
                    ->imagePreviewHeight('240')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                    ->afterStateUpdated(function (FileUpload $component, mixed $state, Set $set, $livewire): void {
                        $file = collect(Arr::wrap($state))->first(fn ($f) => $f instanceof TemporaryUploadedFile);
                        if (! $file) {
                            return;
                        }

                        ['data' => $data, 'error' => $error] = CustomerResource::scanUploadedQr($file);

                        if ($error) {
                            $livewire->addError($component->getStatePath(), $error);
                            Notification::make()->title('Không quét được QR CCCD')->body($error)->danger()->persistent()->send();

                            return;
                        }

                        $livewire->resetErrorBag($component->getStatePath());
                        $set('cccd_data', $data);
                    })
                    ->rule(fn (?CustomerCompanion $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (! $value instanceof TemporaryUploadedFile) {
                            return;
                        }

                        ['data' => $data, 'error' => $error] = CustomerResource::scanUploadedQr($value);
                        if ($error ??= $this->duplicateError($data, $record)) {
                            $fail($error);

                            return;
                        }

                        self::$scannedCccdData = $data;
                    })
                    ->helperText('Chụp đủ sáng, lấy nét vào thẻ, thẻ chiếm gần hết khung hình. Họ tên và thông tin lấy từ mã QR.'),

                Placeholder::make('cccd_data_view')
                    ->label('Dữ liệu CCCD (sau khi quét)')
                    ->content(fn (Get $get): HtmlString => CustomerResource::renderCccdData($get('cccd_data'))),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('orderGuestCccds'))
            ->columns([
                ImageColumn::make('cccd_image')
                    ->label('Ảnh CCCD')
                    ->disk('public')
                    ->getStateUsing(fn (CustomerCompanion $record) => $record->cccd_qr_image ?: $record->cccd_front)
                    ->height(48),

                TextColumn::make('full_name')
                    ->label('Họ và tên')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('cccd_data.cccd')
                    ->label('Số CCCD')
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder('Chưa có dữ liệu'),

                TextColumn::make('cccd_data.dob')
                    ->label('Ngày sinh')
                    ->placeholder('—'),

                TextColumn::make('cccd_data.gender')
                    ->label('Giới tính')
                    ->placeholder('—'),

                TextColumn::make('cccd_data.address')
                    ->label('Địa chỉ')
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('order_guest_cccds_count')
                    ->label('Số đơn đi cùng')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Ngày thêm')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Chưa có khách đi cùng')
            ->emptyStateDescription('Thêm khách đi cùng bằng ảnh CCCD mặt có mã QR để chọn lại nhanh khi đặt phòng.')
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm khách đi cùng')
                    ->modalHeading('Thêm khách đi cùng')
                    ->createAnother(false)
                    ->mutateFormDataUsing(fn (array $data): array => self::withScannedData($data)),
            ])
            ->actions([
                EditAction::make()
                    ->label('Sửa')
                    ->modalHeading('Sửa khách đi cùng')
                    ->mutateFormDataUsing(fn (array $data): array => self::withScannedData($data))
                    ->using(function (CustomerCompanion $record, array $data): CustomerCompanion {
                        $oldImage = $record->cccd_qr_image;
                        $record->update($data);

                        // Thay ảnh mới thì dọn ảnh cũ (chỉ khi không còn đơn/hồ sơ nào dùng).
                        if ($oldImage && $oldImage !== $record->cccd_qr_image) {
                            app(CccdIntakeService::class)->deleteUnreferencedImages([$oldImage]);
                        }

                        return $record;
                    }),

                DeleteAction::make()
                    ->label('Xoá')
                    ->modalDescription('Các đơn cũ đã gắn người này vẫn giữ nguyên thông tin CCCD của đơn.')
                    ->after(fn (CustomerCompanion $record) => app(CccdIntakeService::class)->deleteUnreferencedImages([
                        $record->cccd_qr_image,
                        $record->cccd_front,
                        $record->cccd_back,
                    ])),
            ]);
    }

    // Chặn 1 người bị lưu trùng trong cùng hồ sơ: trùng chính khách hàng, hoặc trùng số CCCD với
    // khách đi cùng khác — cùng quy ước với Api\Admin\CustomerCompanionController.
    private function duplicateError(array $data, ?CustomerCompanion $record): ?string
    {
        $customer = $this->getOwnerRecord();

        if (is_array($customer->cccd_data) && CccdIdentity::samePerson($data, $customer->cccd_data)) {
            return 'CCCD này trùng với CCCD của chính khách hàng — không thể vừa là khách chính vừa là khách đi cùng.';
        }

        $duplicate = $customer->companions()
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->get()
            ->first(fn (CustomerCompanion $c) => trim((string) ($c->cccd_data['cccd'] ?? '')) === $data['cccd']);

        return $duplicate
            ? 'Số CCCD này đã có trong danh sách khách đi cùng (' . ($duplicate->full_name ?: 'chưa có tên') . ').'
            : null;
    }

    private static function withScannedData(array $data): array
    {
        if (self::$scannedCccdData) {
            $data['cccd_data'] = self::$scannedCccdData;
            $data['full_name'] = self::$scannedCccdData['full_name'] ?? null;
        }

        return $data;
    }
}
