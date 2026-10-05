<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\RelationManagers;

use App\Models\PartnerLegalDocument;
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
            // ĐKKD / ANTT / PCCC có BỘ Ô RIÊNG (App\Support\LegalDocumentFields): nhãn 3 ô chung đổi theo loại, các ô còn lại lưu ở cột `extra`.
            // Loại khác giữ form chung (tên, số, cơ quan cấp, ngày cấp, ngày hết hạn).
            Forms\Components\TextInput::make('name')->label('Tên giấy tờ')->maxLength(255)
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\TextInput::make('document_number')->maxLength(100)
                ->label(fn (Forms\Get $get) => LegalDocumentFields::for($get('type'))['document_number'][0] ?? 'Số giấy tờ')
                ->live(onBlur: true)
                ->helperText(fn (Forms\Get $get) => $get('type') === 'fire_safety'
                    ? (LegalDocumentFields::fireSafetyStage($get('document_number'))['label'] ?? 'TD-PCCC = thẩm duyệt (chưa hoạt động); NT / BB / GXN-PCCC = đã nghiệm thu (chuẩn bị hoạt động).')
                    : null),
            Forms\Components\DatePicker::make('issued_at')->label('Ngày cấp')->native(false)->maxDate(now()),
            Forms\Components\TextInput::make('issuer')->maxLength(255)
                ->label(fn (Forms\Get $get) => LegalDocumentFields::for($get('type'))['issuer'][0] ?? 'Cơ quan cấp'),
            ...array_map(fn (string $key) => ($key === 'business_lines' ? Forms\Components\Textarea::make("extra.{$key}")->rows(2)->maxLength(2000) : Forms\Components\TextInput::make("extra.{$key}")->maxLength(500))
                ->label(fn (Forms\Get $get) => LegalDocumentFields::for($get('type'))[$key][0] ?? $key)
                ->visible(fn (Forms\Get $get) => in_array($key, LegalDocumentFields::extraKeys($get('type')), true))
                ->rules(LegalDocumentFields::rules()[$key])
                ->validationMessages(['regex' => str_ends_with($key, '_id_number') ? 'Phải gồm 9 hoặc 12 chữ số.' : 'Số điện thoại không hợp lệ.']), LegalDocumentFields::allExtraKeys()),
            Forms\Components\DatePicker::make('expires_at')->label('Ngày hết hạn')->native(false)->afterOrEqual('issued_at')
                ->visible(fn (Forms\Get $get) => ! LegalDocumentFields::has($get('type'))),
            Forms\Components\Toggle::make('is_required')->label('Bắt buộc')->default(false)
                ->disabled(fn (Forms\Get $get) => $get('type') === 'business_license')
                ->dehydrated(),
            Forms\Components\Hidden::make('status')->default('draft'),
            Forms\Components\Hidden::make('created_by')->default(fn () => auth()->id()),
            SpatieMediaLibraryFileUpload::make('file')->label('Tệp giấy tờ')
                ->collection('file')->disk('local')->required()
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(10240)->columnSpanFull(),
        ])->columns(2);
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
                Tables\Actions\CreateAction::make()->label('Thêm giấy tờ'),
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
                Tables\Actions\EditAction::make()
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
