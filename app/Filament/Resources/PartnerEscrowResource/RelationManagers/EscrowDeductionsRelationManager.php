<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerEscrowResource\RelationManagers;

use App\Models\PartnerEscrowDeduction as Deduction;
use App\Models\PartnerEscrowEntry as Entry;
use App\Services\EscrowService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

// ĐỀ XUẤT TRỪ ký quỹ: đối tác đồng ý hoặc khiếu nại (1 lần, trong hạn), Super Admin chốt giữ/giảm/huỷ — cùng nghiệp vụ với API …/escrow/deductions.
class EscrowDeductionsRelationManager extends RelationManager
{
    protected static string $relationship = 'escrowDeductions';

    protected static ?string $title = 'Đề xuất trừ ký quỹ';

    public function isReadOnly(): bool
    {
        return false;
    }

    private function canRespond(Deduction $record): bool
    {
        $user = auth()->user();

        return $record->canRespond() && ($user?->isSuperAdmin() || ($user?->partner_id === $record->partner_id && ($user->hasRole('partner') || $user->can('update_partner'))));
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('type')->label('Loại')->formatStateUsing(fn (string $state) => Entry::TYPES[$state] ?? $state)
                    ->description(fn (Deduction $record) => Deduction::CASES[$record->case_code] ?? null),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền')->formatStateUsing(fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ')
                    ->description(fn (Deduction $record) => $record->final_amount !== null && $record->final_amount !== $record->amount ? 'Thực trừ ' . number_format($record->final_amount, 0, ',', '.') . 'đ' : null),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => Deduction::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { Deduction::STATUS_APPLIED => 'danger', Deduction::STATUS_DISPUTED => 'warning', Deduction::STATUS_CANCELLED => 'gray', default => 'info' }),
                Tables\Columns\IconColumn::make('is_urgent')->label('Khẩn')->boolean(),
                Tables\Columns\TextColumn::make('reason')->label('Lý do')->wrap()->limit(100)->tooltip(fn (Deduction $record) => $record->reason),
                Tables\Columns\TextColumn::make('order_code')->label('Mã đơn')->placeholder('—'),
                Tables\Columns\TextColumn::make('respond_by')->label('Hạn phản hồi')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('accept')
                    ->label('Đồng ý')->icon('heroicon-o-check')->color('success')->requiresConfirmation()
                    ->modalDescription('Đồng ý khoản trừ này — số tiền sẽ được trừ ngay khỏi ký quỹ.')
                    ->visible(fn (Deduction $record) => $this->canRespond($record) && $record->status === Deduction::STATUS_PENDING)
                    ->action(function (Deduction $record) {
                        try {
                            app(EscrowService::class)->acceptDeduction($record, auth()->user());
                            Notification::make()->success()->title('Đã đồng ý khoản trừ.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('dispute')
                    ->label('Khiếu nại')->icon('heroicon-o-exclamation-triangle')->color('warning')
                    ->modalDescription('Chỉ khiếu nại được một lần, trong hạn phản hồi.')
                    ->visible(fn (Deduction $record) => $this->canRespond($record))
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Lý do khiếu nại')->required()->maxLength(2000),
                        Forms\Components\FileUpload::make('evidence')->label('Chứng từ')->multiple()->maxFiles(5)->maxSize(10240)
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->storeFiles(false),
                    ])
                    ->action(function (Deduction $record, array $data) {
                        try {
                            $disputed = app(EscrowService::class)->disputeDeduction($record, $data['reason'], auth()->user());
                            foreach ((array) ($data['evidence'] ?? []) as $file) {
                                $disputed->addMedia($file->getRealPath())->usingFileName($file->getClientOriginalName())->toMediaCollection('dispute_evidence');
                            }
                            Notification::make()->success()->title('Đã gửi khiếu nại.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('resolve')
                    ->label('Chốt')->icon('heroicon-o-scale')->color('primary')
                    ->visible(fn (Deduction $record) => (auth()->user()?->isSuperAdmin() ?? false) && in_array($record->status, [Deduction::STATUS_PENDING, Deduction::STATUS_DISPUTED], true) && $record->resolved_at === null)
                    ->modalDescription('Đề xuất còn đang chờ (chưa khiếu nại) chỉ huỷ được. Giữ = trừ đủ, Giảm = trừ số nhỏ hơn, Huỷ = không trừ.')
                    ->form([
                        Forms\Components\Select::make('resolution')->label('Kết luận')->required()->native(false)->live()->options([
                            Deduction::RESOLUTION_KEEP => 'Giữ — trừ đủ số đề xuất', Deduction::RESOLUTION_REDUCE => 'Giảm — trừ số nhỏ hơn', Deduction::RESOLUTION_CANCEL => 'Huỷ — không trừ',
                        ]),
                        Forms\Components\TextInput::make('final_amount')->label('Số tiền thực trừ (đ)')->numeric()->minValue(1)
                            ->visible(fn (Forms\Get $get) => $get('resolution') === Deduction::RESOLUTION_REDUCE)->required(fn (Forms\Get $get) => $get('resolution') === Deduction::RESOLUTION_REDUCE),
                        Forms\Components\Textarea::make('note')->label('Ghi kết luận xử lý')->required()->maxLength(2000),
                    ])
                    ->action(function (Deduction $record, array $data) {
                        try {
                            app(EscrowService::class)->resolveDeduction($record, $data['resolution'], isset($data['final_amount']) ? (int) $data['final_amount'] : null, $data['note'], auth()->user());
                            Notification::make()->success()->title('Đã chốt đề xuất.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
