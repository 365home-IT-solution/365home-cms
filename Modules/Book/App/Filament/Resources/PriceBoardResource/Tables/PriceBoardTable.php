<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Tables;

use App\Services\PriceBoardSyncService;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Book\App\Filament\Resources\PriceBoardResource;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardPriceLog;

class PriceBoardTable
{
    public static function table(Table $table): Table
    {
        // Chia sẻ giữa before()/after() của DeleteAction bên dưới — xem giải thích ở
        // EditPriceBoard::getHeaderActions().
        $affectedProducts = collect();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên bảng giá')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('scope')
                    ->label('Phạm vi')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, PriceBoard $record) => $record->is_template
                        ? 'Bảng dựng sẵn'
                        : match ($state) {
                            PriceBoard::SCOPE_PARTNER  => 'Toàn đối tác',
                            PriceBoard::SCOPE_BRANCHES => $record->scopeBranches->count() . ' chi nhánh',
                            default                    => 'Một số phòng',
                        })
                    ->color(fn (PriceBoard $record) => $record->is_template ? 'info' : 'gray'),

                TextColumn::make('branch.name')
                    ->label('Chi nhánh')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('sheets_count')
                    ->label('Bộ giá')
                    ->counts('sheets')
                    ->badge(),

                TextColumn::make('items_count')
                    ->label('Số phòng áp dụng')
                    ->counts('items')
                    ->badge(),

                TextColumn::make('source_preset')
                    ->label('Từ bảng dựng sẵn')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('start_date')
                    ->label('Ngày bắt đầu')
                    ->date('d/m/Y')
                    ->placeholder('Không giới hạn'),

                TextColumn::make('end_date')
                    ->label('Ngày kết thúc')
                    ->date('d/m/Y')
                    ->placeholder('Không giới hạn'),

                TextColumn::make('trang_thai')
                    ->label('Trạng thái')
                    ->state(fn (PriceBoard $record) => match (true) {
                        ! $record->is_active => 'Đã tắt',
                        $record->coversDate() => 'Đang áp dụng',
                        $record->start_date && now()->startOfDay()->lt($record->start_date) => 'Chờ áp dụng',
                        default => 'Hết hạn',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Đang áp dụng' => 'success',
                        'Chờ áp dụng'  => 'warning',
                        'Hết hạn'      => 'gray',
                        default        => 'danger',
                    }),

                TextColumn::make('approval_status')
                    ->label('Duyệt')
                    ->state(function (PriceBoard $record) {
                        $label = ['approved' => 'Đã duyệt', 'pending' => 'Chờ duyệt', 'rejected' => 'Bị từ chối'][$record->approval_status ?? 'approved'] ?? $record->approval_status;

                        return $record->isApproved() && app(\App\Services\ApprovalService::class)->hasPendingUpdate(\App\Models\ApprovalRequest::SUBJECT_PRICE_BOARD, $record->id)
                            ? $label . ' · có bản sửa chờ duyệt'
                            : $label;
                    })
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_contains($state, 'Bị từ chối') => 'danger',
                        str_contains($state, 'Chờ duyệt'), str_contains($state, 'chờ duyệt') => 'warning',
                        default => 'success',
                    }),

                ToggleColumn::make('is_active')
                    ->label('Kích hoạt')
                    // Ghi giá thật xuống hệ thống ngay khi bật/tắt — cần đúng quyền update, không chỉ
                    // quyền xem danh sách (view_any).
                    // Đối tác thường bật/tắt bảng cũng là đổi giá đang bán → phải sửa trong form và gửi 365home duyệt; bảng chưa duyệt không bật được.
                    ->disabled(fn (PriceBoard $record) => $record->is_template || ! PriceBoardResource::canEdit($record)
                        || ! $record->isApproved() || app(\App\Services\ApprovalService::class)->requiresApproval(auth()->user()))
                    ->afterStateUpdated(function (PriceBoard $record) {
                        $service = app(PriceBoardSyncService::class);

                        // Bật lại phải qua đúng kiểm tra trùng ngày như khi Tạo/Sửa — nếu không, có
                        // thể bật 1 bảng trùng khoảng ngày hiệu lực với bảng khác đang active cho
                        // cùng phòng, khiến việc "bảng nào thắng" không xác định được.
                        if ($record->is_active) {
                            try {
                                $service->assertNoOverlap($record);
                            } catch (\RuntimeException $e) {
                                $record->update(['is_active' => false]);

                                Notification::make()
                                    ->title('Không thể kích hoạt')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        // Bật/tắt phải có hiệu lực NGAY — không chờ job price-boards:sync-due chạy
                        // lúc nửa đêm mới thấy giá đổi.
                        $service->resyncBoardProducts($record);

                        Notification::make()
                            ->title($record->is_active ? 'Đã kích hoạt, giá đã được áp dụng ngay' : 'Đã tắt, giá đã được khôi phục')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('scope')
                    ->label('Phạm vi')
                    ->options([
                        PriceBoard::SCOPE_ROOMS    => 'Một số phòng',
                        PriceBoard::SCOPE_BRANCHES => 'Theo chi nhánh',
                        PriceBoard::SCOPE_PARTNER  => 'Toàn đối tác',
                    ]),
                \Filament\Tables\Filters\TernaryFilter::make('is_active')->label('Kích hoạt'),
                \Filament\Tables\Filters\SelectFilter::make('approval_status')->label('Duyệt')
                    ->options(['approved' => 'Đã duyệt', 'pending' => 'Chờ duyệt', 'rejected' => 'Bị từ chối']),
            ])
            ->headerActions([
                // Tạo bảng thật từ bảng giá dựng sẵn (copy một lần, mặc định TẮT).
                \Filament\Tables\Actions\Action::make('use_preset')
                    ->label('Tạo từ bảng dựng sẵn')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn () => PriceBoardResource::canCreate())
                    ->modalHeading('Tạo bảng giá từ bảng dựng sẵn')
                    ->modalSubmitActionLabel('Tạo bảng giá')
                    ->form(fn () => [
                        \Filament\Forms\Components\Select::make('code')
                            ->label('Bảng dựng sẵn')
                            ->options(fn () => PriceBoard::query()->where('is_template', true)->whereNull('partner_id')
                                ->orderBy('sort_order')->pluck('name', 'code')->all())
                            ->required(),
                        \Filament\Forms\Components\Select::make('partner_id')
                            ->label('Đối tác')
                            ->options(fn () => \App\Models\Partner::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(fn () => \Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardSheetsForm::needsPartnerChoice())
                            ->visible(fn () => \Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardSheetsForm::needsPartnerChoice())
                            ->live(),
                        \Filament\Forms\Components\Select::make('scope')
                            ->label('Phạm vi')
                            ->options([
                                PriceBoard::SCOPE_PARTNER  => 'Toàn bộ chi nhánh của đối tác',
                                PriceBoard::SCOPE_BRANCHES => 'Một số chi nhánh',
                            ])
                            ->default(PriceBoard::SCOPE_PARTNER)
                            ->required()
                            ->live(),
                        \Filament\Forms\Components\Select::make('branch_ids')
                            ->label('Chi nhánh')
                            ->multiple()
                            ->options(fn (\Filament\Forms\Get $get) => \Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardSheetsForm::branchOptions(
                                \Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardSheetsForm::partnerId() ?: ($get('partner_id') ?: null)
                            ))
                            ->visible(fn (\Filament\Forms\Get $get) => $get('scope') === PriceBoard::SCOPE_BRANCHES)
                            ->required(fn (\Filament\Forms\Get $get) => $get('scope') === PriceBoard::SCOPE_BRANCHES),
                        \Filament\Forms\Components\TextInput::make('name')->label('Tên bảng giá')->maxLength(255),
                        \Filament\Forms\Components\DatePicker::make('start_date')->label('Ngày bắt đầu')->native(false),
                        \Filament\Forms\Components\DatePicker::make('end_date')->label('Ngày kết thúc')->native(false)->afterOrEqual('start_date'),
                    ])
                    ->action(function (array $data) {
                        $user      = auth()->user();
                        $partnerId = $user->seesAllPartners() ? ($data['partner_id'] ?? null) : $user->partner_id;
                        $preset    = PriceBoard::query()->where('is_template', true)->where('code', $data['code'])->first();

                        if (! $partnerId || ! $preset) {
                            Notification::make()->title('Chọn bảng dựng sẵn và đối tác')->danger()->send();

                            return;
                        }

                        if ($preset->requires_dates && (empty($data['start_date']) || empty($data['end_date']))) {
                            Notification::make()->title('Bảng này bắt buộc chọn ngày bắt đầu và kết thúc')->danger()->send();

                            return;
                        }

                        $service = app(\App\Services\PriceBoards\PriceBoardV2Service::class);
                        $clone   = $service->cloneBoard($preset, (string) $partnerId, [
                            'name'       => $data['name'] ?: $preset->name,
                            'start_date' => $data['start_date'] ?? null,
                            'end_date'   => $data['end_date'] ?? null,
                            'is_active'  => false,
                            'scope'      => $data['scope'],
                            'branch_ids' => $data['branch_ids'] ?? [],
                            'source_preset' => $preset->code,
                        ], resolvePresetTimes: true);

                        $service->persist($clone['spec'], null);

                        Notification::make()->title('Đã tạo bảng giá "' . $clone['spec']['name'] . '" (đang tắt)')->success()->send();
                    }),
            ])
            ->actions([
                Action::make('history')
                    ->label('Lịch sử')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (PriceBoard $record) => 'Lịch sử thay đổi giá — ' . $record->name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (PriceBoard $record) => view('book::filament.resources.price-board-resource.history-modal', [
                        'logs' => PriceBoardPriceLog::where('price_board_id', $record->id)
                            ->with(['product', 'changedByUser'])
                            ->orderByDesc('created_at')
                            ->limit(200)
                            ->get(),
                    ])),
                Action::make('apply_now')
                    ->label('Áp dụng ngay')
                    ->icon('heroicon-o-bolt')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Áp ngay giá của bảng này xuống các phòng đã chọn, bất kể ngày hiệu lực.')
                    // Ghi giá thật xuống hệ thống NGAY lập tức — cùng mức rủi ro với Sửa/Xoá, phải
                    // đòi đúng quyền update thay vì chỉ cần quyền xem danh sách (view_any) là bấm được.
                    ->visible(fn (PriceBoard $record) => ! $record->is_template && PriceBoardResource::canEdit($record))
                    ->action(function (PriceBoard $record) {
                        app(PriceBoardSyncService::class)->applyBoard($record);

                        Notification::make()
                            ->title('Đã áp dụng bảng giá "' . $record->name . '"')
                            ->success()
                            ->send();
                    }),
                Action::make('duplicate')
                    ->label('Nhân bản')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->visible(fn (PriceBoard $record) => ! $record->is_template && PriceBoardResource::canCreate())
                    ->action(function (PriceBoard $record) {
                        $service = app(\App\Services\PriceBoards\PriceBoardV2Service::class);
                        $clone   = $service->cloneBoard($record, (string) $record->partner_id, [
                            'name' => $record->name . ' (bản sao)', 'is_active' => false,
                        ]);
                        $service->persist($clone['spec'], null);

                        Notification::make()->title('Đã nhân bản bảng giá')->success()->send();
                    }),
                EditAction::make(),
                // Tính lại giá ĐÚNG cho mọi phòng trong bảng SAU khi xoá — xem giải thích ở
                // EditPriceBoard::getHeaderActions() (không tự ý ghi thẳng baseline của bảng vừa xoá,
                // tránh ghi đè nhầm lên giá của 1 bảng KHÁC đang thật sự active cho cùng phòng).
                DeleteAction::make()
                    ->before(function (PriceBoard $record) use (&$affectedProducts) {
                        $affectedProducts = app(PriceBoardSyncService::class)->productsAffectedByBoard($record);
                    })
                    ->after(function () use (&$affectedProducts) {
                        app(PriceBoardSyncService::class)->resyncProductsAfterBoardDeleted($affectedProducts);
                    }),
            ]);
    }
}
