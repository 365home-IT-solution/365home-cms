<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\RelationManagers;

use App\Models\PartnerLegalDocument;
use App\Services\LegalDocumentScanService;
use App\Services\PartnerLegalDocumentService;
use App\Support\LegalDocumentFields;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class LegalDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'legalDocuments';

    protected static ?string $title = 'Hồ sơ pháp lý';

    protected static ?string $modelLabel = 'giấy tờ';

    protected static ?string $pluralModelLabel = 'giấy tờ';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')->label('Loại giấy tờ')
                ->options(PartnerLegalDocument::TYPES)->required()->live()
                ->afterStateUpdated(function ($state, Forms\Set $set): void {
                    if ($state === 'business_license') {
                        $set('is_required', true);
                    }
                    if (! in_array($state, ['fire_safety', 'security_order', 'property_ownership_or_use'], true)) {
                        $set('building_id', null);
                    }
                }),
            Forms\Components\Select::make('building_id')->label('Tòa nhà áp dụng')
                ->options(fn () => $this->getOwnerRecord()->categories()
                    ->where('category_type', 'product')->whereNull('parent_id')->orderBy('name')->pluck('name', 'id'))
                // MiniHouse đăng ký dùng thử: PCCC/ANTT nộp ở cấp đối tác như Homestay → không chọn toà nhà.
                ->required(fn (Forms\Get $get) => $this->getOwnerRecord()->isMinihouse() && ! $this->getOwnerRecord()->minihouseDocumentsFlow()
                    && in_array($get('type'), ['fire_safety', 'security_order', 'property_ownership_or_use'], true))
                ->visible(fn (Forms\Get $get) => $this->getOwnerRecord()->isMinihouse() && ! $this->getOwnerRecord()->minihouseDocumentsFlow()
                    && in_array($get('type'), ['fire_safety', 'security_order', 'property_ownership_or_use'], true))
                ->searchable()->preload(),
            SpatieMediaLibraryFileUpload::make('file')->label('Tệp giấy tờ')
                ->collection('file')->disk('local')->required()
                // CCCD chỉ nhận ảnh (phải đọc được mã QR trên thẻ).
                ->acceptedFileTypes(fn (Forms\Get $get) => $get('type') === 'citizen_id' ? ['image/jpeg', 'image/png', 'image/webp'] : ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->helperText(fn (Forms\Get $get) => $get('type') === 'citizen_id' ? 'Ảnh chụp mặt CCCD có mã QR. Hệ thống phải đọc được mã QR thì mới lưu; các ô bên dưới lấy từ mã QR (trừ Nơi cấp).' : null)
                ->maxSize(10240)->columnSpanFull()
                // Quét tệp vừa chọn (hoặc tệp đã lưu của giấy tờ đang sửa) → điền gợi ý vào các ô CÒN TRỐNG của loại đó; không lưu gì cho tới khi bấm lưu.
                ->hintAction(Forms\Components\Actions\Action::make('scan')->label('Quét giấy tờ để tự điền')->icon('heroicon-o-viewfinder-circle')
                    ->visible(fn (Forms\Get $get, string $operation) => $operation !== 'view' && LegalDocumentFields::has($get('type')))
                    ->action(fn (Forms\Get $get, Forms\Set $set, ?PartnerLegalDocument $record) => $this->scanIntoForm($get, $set, $record))),
            // ĐKKD / ANTT / PCCC / CCCD: MỖI LOẠI MỘT BỘ CỘT RIÊNG (dkkd_* / antt_* / pccc_* / cccd_* — App\Support\LegalDocumentFields), kể cả số / ngày cấp / nơi cấp.
            // Loại khác giữ form chung (tên, số, cơ quan cấp, ngày cấp, ngày hết hạn) trên các cột chung.
            ...array_merge(...array_map(fn (string $type) => array_map(function (string $key, array $definition) use ($type) {
                [$label, $input] = $definition;
                $field = match ($input) {
                    'date'     => Forms\Components\DatePicker::make($key)->native(false)->maxDate(now())->disabled($this->qrLocked($key))->dehydrated(),
                    'textarea' => Forms\Components\Textarea::make($key)->rows(2)->maxLength(2000),
                    default    => Forms\Components\TextInput::make($key)->rules(LegalDocumentFields::rules()[$key])->readOnly($this->qrLocked($key))
                        ->validationMessages(['regex' => LegalDocumentFields::messages()["{$key}.regex"] ?? 'Giá trị không hợp lệ.']),
                };
                if ($key === 'pccc_document_number') {
                    $field->live(onBlur: true)->helperText(fn (Forms\Get $get) => LegalDocumentFields::fireSafetyStage($get($key))['label']
                        ?? 'TD-PCCC = thẩm duyệt (chưa hoạt động); NT / BB / GXN-PCCC = đã nghiệm thu (chuẩn bị hoạt động).');
                }

                return $field->label($label)->visible(fn (Forms\Get $get) => $get('type') === $type);
            }, LegalDocumentFields::keys($type), array_values(LegalDocumentFields::for($type))), LegalDocumentFields::types())),
            Forms\Components\TextInput::make('name')->label('Tên giấy tờ')->maxLength(255)
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\TextInput::make('document_number')->label('Số giấy tờ')->maxLength(100)
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\TextInput::make('issuer')->label('Cơ quan cấp')->maxLength(255)
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\DatePicker::make('issued_at')->label('Ngày cấp')->native(false)
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\DatePicker::make('expires_at')->label('Ngày hết hạn')->native(false)->afterOrEqual('issued_at')
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\Toggle::make('is_required')->label('Bắt buộc')->default(false)
                ->disabled(fn (Forms\Get $get) => $get('type') === 'business_license')
                ->dehydrated(),
            Forms\Components\Hidden::make('status')->default('draft'),
            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
        ])->columns(2);
    }

    /** Nút "Quét giấy tờ để tự điền" trên form: dùng chung bộ quét với API (LegalDocumentScanService). */
    private function scanIntoForm(Forms\Get $get, Forms\Set $set, ?PartnerLegalDocument $record): void
    {
        $scanner = app(LegalDocumentScanService::class);
        // CCCD đọc mã QR, không cần khoá OCR.
        if ($get('type') !== 'citizen_id' && ! $scanner->isConfigured()) {
            Notification::make()->title('Chức năng quét giấy tờ chưa được cấu hình')->body('Vui lòng tự nhập thông tin.')->warning()->send();

            return;
        }

        $file = $this->formFile($get('file'), $record);
        if (! $file) {
            Notification::make()->title('Chưa có tệp để quét')->body('Chọn tệp giấy tờ trước, chờ tải lên xong rồi bấm quét.')->warning()->send();

            return;
        }

        $result = $scanner->scan($file, (string) $get('type'));
        $filled = 0;
        foreach ($result['fields'] as $key => $value) {
            // Không ghi đè ô đã nhập — trừ ô CCCD lấy từ mã QR (QR quyết định).
            if (filled($value) && (blank($get($key)) || $this->qrLocked($key))) {
                $set($key, $value);
                $filled++;
            }
        }

        Notification::make()
            ->title($result['text_found'] ? "Đã đọc được {$result['found']}/{$result['total']} ô, điền {$filled} ô còn trống" : 'Không đọc được nội dung tệp')
            ->body(implode("\n", [...$result['warnings'], 'Vui lòng đối chiếu lại với giấy tờ trước khi lưu.']))
            ->{$result['found'] > 0 ? 'success' : 'warning'}()->persistent()->send();
    }

    /** Tệp vừa chọn trên form (chưa lưu) → ưu tiên; không có thì dùng tệp đã lưu của giấy tờ đang sửa. */
    private function formFile(mixed $state, ?PartnerLegalDocument $record): ?UploadedFile
    {
        $file = collect(Arr::wrap($state))->first(fn ($item) => $item instanceof UploadedFile);
        if (! $file && ($media = $record?->getFirstMedia('file')) && is_file($media->getPath())) {
            $file = new UploadedFile($media->getPath(), $media->file_name, $media->mime_type, null, true);
        }

        return $file;
    }

    /** Ô CCCD lấy từ mã QR: khoá trên form, giá trị do QR quyết định khi lưu. */
    private function qrLocked(string $key): bool
    {
        return in_array($key, array_map(fn (string $name) => LegalDocumentFields::key('citizen_id', $name), LegalDocumentFields::CITIZEN_ID_QR_FIELDS), true);
    }

    /**
     * Lưu CCCD từ trang quản trị: cùng quy tắc với API (LegalDocumentScanService::citizenIdValues) — ảnh phải đọc được mã QR,
     * các ô từ QR do QR quyết định. Không đọc được → báo lỗi ngay dưới ô tệp, không lưu.
     */
    private function applyCitizenIdRule(array $data, ?PartnerLegalDocument $record = null): array
    {
        if (($data['type'] ?? $record?->type) !== 'citizen_id') {
            return $data;
        }
        $file = $this->formFile($this->getMountedTableActionForm()?->getRawState()['file'] ?? null, $record);

        return array_merge($data, app(LegalDocumentScanService::class)->citizenIdValues($file, 'mountedTableActionsData.0.file'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')->label('Loại')->formatStateUsing(fn ($state) => PartnerLegalDocument::TYPES[$state] ?? $state)->wrap(),
                Tables\Columns\TextColumn::make('document_number')->label('Số giấy tờ')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_required')->label('Bắt buộc')->boolean(),
                Tables\Columns\TextColumn::make('expires_at')->label('Hết hạn')->date('d/m/Y')->placeholder('Không thời hạn')
                    ->color(fn (PartnerLegalDocument $record) => $record->isExpired() ? 'danger' : null),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => PartnerLegalDocument::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'pending_review' => 'warning',
                        'changes_requested', 'rejected' => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('review_note')->label('Ghi chú duyệt')->limit(45)->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Thêm giấy tờ')->mutateFormDataUsing(fn (array $data) => $this->applyCitizenIdRule($data)),
                Tables\Actions\Action::make('submit')
                    ->label('Gửi hồ sơ xét duyệt')->icon('heroicon-o-paper-airplane')->color('warning')
                    ->requiresConfirmation()
                    ->action(function () {
                        app(PartnerLegalDocumentService::class)->submit($this->getOwnerRecord());
                        Notification::make()->title('Đã gửi hồ sơ để xét duyệt')->success()->send();
                    }),
                Tables\Actions\Action::make('approveDossier')
                    ->label('Duyệt toàn bộ hồ sơ')->icon('heroicon-o-check-badge')->color('success')
                    ->requiresConfirmation()
                    ->action(function () {
                        try {
                            app(PartnerLegalDocumentService::class)->approveDossier($this->getOwnerRecord(), auth()->user());
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()
                                ->title('Chưa thể phê duyệt — hồ sơ pháp lý chưa đủ điều kiện')
                                ->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Đã phê duyệt hồ sơ pháp lý')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('download')->label('Tải file')->icon('heroicon-o-arrow-down-tray')
                    ->action(function (PartnerLegalDocument $record) {
                        $media = $record->getFirstMedia('file');

                        return $media ? response()->download($media->getPath(), $media->file_name) : null;
                    }),
                // Xem đủ các ô riêng theo loại của MỌI giấy tờ (kể cả đang chờ duyệt / đã duyệt) để đối chiếu với tệp khi duyệt.
                Tables\Actions\ViewAction::make()->label('Xem')->modalHeading(fn (PartnerLegalDocument $record) => PartnerLegalDocument::TYPES[$record->type] ?? 'Giấy tờ'),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data, PartnerLegalDocument $record) => $this->applyCitizenIdRule($data, $record))
                    ->visible(fn (PartnerLegalDocument $record) => in_array($record->status, ['draft', 'changes_requested', 'rejected'], true)),
                Tables\Actions\Action::make('approve')->label('Duyệt')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (PartnerLegalDocument $record) => $record->status === 'pending_review')
                    ->requiresConfirmation()
                    ->action(function (PartnerLegalDocument $record) {
                        app(PartnerLegalDocumentService::class)->review($record, 'approved', null, auth()->user());
                        Notification::make()->title('Đã duyệt giấy tờ')->success()->send();
                    }),
                Tables\Actions\Action::make('requestChanges')->label('Yêu cầu bổ sung')->icon('heroicon-o-arrow-path')->color('danger')
                    ->visible(fn (PartnerLegalDocument $record) => $record->status === 'pending_review')
                    ->form([Forms\Components\Textarea::make('review_note')->label('Lý do')->required()->maxLength(2000)])
                    ->action(function (PartnerLegalDocument $record, array $data) {
                        app(PartnerLegalDocumentService::class)->review($record, 'changes_requested', $data['review_note'], auth()->user());
                        Notification::make()->title('Đã yêu cầu bổ sung giấy tờ')->warning()->send();
                    }),
                Tables\Actions\Action::make('rejectDocument')->label('Từ chối')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (PartnerLegalDocument $record) => $record->status === 'pending_review')
                    ->form([Forms\Components\Textarea::make('review_note')->label('Lý do từ chối')->required()->maxLength(2000)])
                    ->action(function (PartnerLegalDocument $record, array $data) {
                        app(PartnerLegalDocumentService::class)->review($record, 'rejected', $data['review_note'], auth()->user());
                        Notification::make()->title('Đã từ chối giấy tờ')->warning()->send();
                    }),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (PartnerLegalDocument $record) => in_array($record->status, ['draft', 'changes_requested', 'rejected'], true)),
            ])
            ->emptyStateHeading('Chưa có giấy tờ pháp lý');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && ($ownerRecord->usesLegalDocuments());
    }
}
