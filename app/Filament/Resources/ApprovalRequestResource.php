<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Exceptions\ApprovalException;
use App\Filament\Resources\ApprovalRequestResource\Pages;
use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// DUYỆT TRƯỚC KHI ĐĂNG trên trang quản trị web (cùng nghiệp vụ với API /api/admin/approvals — App\Services\ApprovalService): đối tác gửi bảng giá / phòng,
// Super Admin duyệt hoặc từ chối (kèm lý do); đối tác xem trạng thái, rút lại hoặc gửi lại. Hard-code quyền như các màn tài chính khác, không dùng permission Shield.
class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Duyệt thay đổi';

    protected static ?string $modelLabel = 'Yêu cầu duyệt';

    protected static ?string $pluralModelLabel = 'Yêu cầu duyệt';

    protected static ?string $slug = 'approval-requests';

    protected static ?int $navigationSort = 3;

    private static function service(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    private static function canReview(): bool
    {
        return self::service()->canReview(auth()->user());
    }

    private static function isPlatformSide(): bool
    {
        return self::service()->isPlatformSide(auth()->user());
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user ? self::service()->visibleTo($user) : ApprovalRequest::query()->whereRaw('1 = 0');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::getEloquentQuery()->where('status', ApprovalRequest::STATUS_PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kind')->label('Loại')->badge()
                    ->formatStateUsing(fn (string $state) => ApprovalRequest::KINDS[$state] ?? $state)
                    ->color(fn (string $state) => str_ends_with($state, '_create') ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('subject_label')->label('Đối tượng')->weight('bold')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('partner.name')->label('Đối tác')->visible(fn () => self::isPlatformSide())->searchable(),
                Tables\Columns\TextColumn::make('requester.fullname')->label('Người gửi')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => ApprovalRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        ApprovalRequest::STATUS_PENDING => 'warning', ApprovalRequest::STATUS_APPROVED => 'success',
                        ApprovalRequest::STATUS_REJECTED => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Gửi lúc')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('reviewed_at')->label('Xử lý lúc')->dateTime('d/m/Y H:i')->placeholder('—')
                    ->description(fn (ApprovalRequest $record) => $record->reviewer?->fullname),
                Tables\Columns\TextColumn::make('review_note')->label('Ghi chú / lý do')->placeholder('—')->wrap()->limit(80)->tooltip(fn (ApprovalRequest $record) => $record->review_note),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(ApprovalRequest::STATUSES)->default(ApprovalRequest::STATUS_PENDING),
                Tables\Filters\SelectFilter::make('kind')->label('Loại')->options(ApprovalRequest::KINDS),
                Tables\Filters\SelectFilter::make('partner_id')->label('Đối tác')->relationship('partner', 'name')->searchable()->preload()->visible(fn () => self::isPlatformSide()),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Xem')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (ApprovalRequest $record) => $record->kindLabel() . ($record->subject_label ? ' — ' . $record->subject_label : ''))
                    ->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (ApprovalRequest $record) => view('filament.resources.approval-request.detail', [
                        'record'  => $record->loadMissing(['partner', 'requester', 'reviewer']),
                        'changes' => self::service()->changesFor($record),
                        'subject' => self::subjectSummary($record),
                    ])),

                Tables\Actions\Action::make('approve')
                    ->label('Duyệt')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ApprovalRequest $record) => self::canReview() && $record->isPending())
                    ->modalHeading('Duyệt yêu cầu này?')
                    ->modalDescription(fn (ApprovalRequest $record) => $record->isCreate()
                        ? 'Sau khi duyệt, ' . ($record->subject_type === ApprovalRequest::SUBJECT_PRICE_BOARD ? 'bảng giá được dùng' : 'phòng được hiện cho khách và đặt được') . '.'
                        : 'Bản sửa được áp xuống ngay, thay bản đang chạy.')
                    ->form([Forms\Components\Textarea::make('note')->label('Ghi chú (không bắt buộc)')->maxLength(1000)->rows(2)])
                    ->action(function (ApprovalRequest $record, array $data) {
                        try {
                            self::service()->approve($record, auth()->user(), $data['note'] ?? null);
                            Notification::make()->success()->title('Đã duyệt.')->send();
                        } catch (ApprovalException $e) {
                            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        }
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (ApprovalRequest $record) => self::canReview() && $record->isPending())
                    ->modalHeading('Từ chối yêu cầu này?')
                    ->form([Forms\Components\Textarea::make('reason')->label('Lý do (đối tác sẽ thấy)')->required()->maxLength(1000)->rows(3)])
                    ->action(function (ApprovalRequest $record, array $data) {
                        try {
                            self::service()->reject($record, auth()->user(), $data['reason']);
                            Notification::make()->success()->title('Đã từ chối.')->send();
                        } catch (ApprovalException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Rút lại')->icon('heroicon-o-arrow-uturn-left')->color('gray')->requiresConfirmation()
                    ->modalDescription('Rút lại yêu cầu đang chờ duyệt. Bản đang chạy giữ nguyên; bản đăng mới sẽ quay về trạng thái chưa được duyệt, sửa xong gửi lại được.')
                    ->visible(fn (ApprovalRequest $record) => $record->isPending() && self::canAct($record))
                    ->action(function (ApprovalRequest $record) {
                        try {
                            self::service()->cancel($record, auth()->user());
                            Notification::make()->success()->title('Đã rút lại yêu cầu.')->send();
                        } catch (ApprovalException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('resubmit')
                    ->label('Gửi lại')->icon('heroicon-o-paper-airplane')->color('warning')->requiresConfirmation()
                    ->modalDescription('Gửi lại cùng nội dung cho 365home duyệt.')
                    ->visible(fn (ApprovalRequest $record) => in_array($record->status, [ApprovalRequest::STATUS_REJECTED, ApprovalRequest::STATUS_CANCELLED], true) && self::canAct($record))
                    ->action(function (ApprovalRequest $record) {
                        try {
                            self::service()->resubmit($record, auth()->user());
                            Notification::make()->success()->title('Đã gửi lại 365home duyệt.')->send();
                        } catch (ApprovalException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('open')
                    ->label('Mở')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (ApprovalRequest $record) => self::subjectUrl($record), shouldOpenInNewTab: true)
                    ->visible(fn (ApprovalRequest $record) => self::subjectUrl($record) !== null),
            ])
            // Chọn nhiều yêu cầu ĐANG CHỜ để duyệt / từ chối / rút lại cùng lúc. Mỗi yêu cầu vẫn đi qua đúng ApprovalService như nút
            // từng dòng (transaction + khoá riêng), xử lý theo thứ tự gửi; cái nào lỗi (vd bảng giá trùng ngày) thì bỏ qua và báo lại.
            ->checkIfRecordIsSelectableUsing(fn (ApprovalRequest $record) => $record->isPending() && (self::canReview() || self::canAct($record)))
            ->bulkActions([
                Tables\Actions\BulkAction::make('approveSelected')
                    ->label('Duyệt đã chọn')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn () => self::canReview())
                    ->modalHeading('Duyệt các yêu cầu đã chọn?')
                    ->modalDescription('Phòng / bảng giá đăng mới sẽ được hiện, bản sửa được áp xuống ngay. Yêu cầu nào không duyệt được sẽ được bỏ qua và báo lại.')
                    ->form([Forms\Components\Textarea::make('note')->label('Ghi chú chung (không bắt buộc)')->maxLength(1000)->rows(2)])
                    ->action(fn (\Illuminate\Support\Collection $records, array $data) => self::runBulk(
                        $records, 'Đã duyệt', fn (ApprovalRequest $r) => self::service()->approve($r, auth()->user(), $data['note'] ?? null),
                    ))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('rejectSelected')
                    ->label('Từ chối đã chọn')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn () => self::canReview())
                    ->modalHeading('Từ chối các yêu cầu đã chọn?')
                    ->form([Forms\Components\Textarea::make('reason')->label('Lý do (đối tác sẽ thấy, dùng chung cho mọi yêu cầu đã chọn)')->required()->maxLength(1000)->rows(3)])
                    ->action(fn (\Illuminate\Support\Collection $records, array $data) => self::runBulk(
                        $records, 'Đã từ chối', fn (ApprovalRequest $r) => self::service()->reject($r, auth()->user(), $data['reason']),
                    ))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('cancelSelected')
                    ->label('Rút lại đã chọn')->icon('heroicon-o-arrow-uturn-left')->color('gray')->requiresConfirmation()
                    ->modalDescription('Rút lại các yêu cầu đang chờ duyệt đã chọn. Bản đang chạy giữ nguyên; bản đăng mới quay về chưa được duyệt.')
                    ->visible(fn () => ! self::canReview())
                    ->action(fn (\Illuminate\Support\Collection $records) => self::runBulk(
                        $records, 'Đã rút lại', fn (ApprovalRequest $r) => self::service()->cancel($r, auth()->user()),
                    ))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Chạy $action cho từng yêu cầu đang chờ đã chọn (cũ trước), gom kết quả vào 1 thông báo: số thành công + lý do từng cái lỗi.
     *
     * @param  \Illuminate\Support\Collection<int, ApprovalRequest>  $records
     */
    private static function runBulk(\Illuminate\Support\Collection $records, string $doneLabel, \Closure $action): void
    {
        $done   = 0;
        $failed = [];

        foreach ($records->filter(fn (ApprovalRequest $r) => $r->isPending())->sortBy('id') as $record) {
            try {
                $action($record);
                $done++;
            } catch (ApprovalException $e) {
                $failed[] = ($record->subject_label ?: $record->kindLabel()) . ': ' . $e->getMessage();
            }
        }

        $skipped = $records->count() - $done - count($failed);
        $body    = collect($failed)->take(5)->map(fn ($line) => '• ' . e($line))
            ->when(count($failed) > 5, fn ($c) => $c->push('… và ' . (count($failed) - 5) . ' yêu cầu khác'))
            ->when($skipped > 0, fn ($c) => $c->push($skipped . ' yêu cầu không còn chờ duyệt nên bỏ qua'))
            ->implode('<br>');

        $notification = Notification::make()
            ->title($doneLabel . ' ' . $done . ' yêu cầu' . ($failed ? ', ' . count($failed) . ' không xử lý được' : '') . '.')
            ->body($body ?: null);

        $failed ? $notification->warning()->persistent()->send() : $notification->success()->send();
    }

    /** Đối tác của yêu cầu (hoặc Super Admin) mới rút lại / gửi lại được. */
    private static function canAct(ApprovalRequest $record): bool
    {
        $user = auth()->user();

        return self::canReview() || (filled($user?->getRawOriginal('partner_id')) && (string) $user->getRawOriginal('partner_id') === (string) $record->partner_id);
    }

    private static function subjectUrl(ApprovalRequest $record): ?string
    {
        try {
            return $record->subject_type === ApprovalRequest::SUBJECT_PRICE_BOARD
                ? \Modules\Book\App\Filament\Resources\PriceBoardResource::getUrl('edit', ['record' => $record->subject_id])
                : \Modules\Product\App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $record->subject_id]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tóm tắt bản ghi gốc cho yêu cầu "đăng mới" (không có danh sách đổi cũ → mới): [nhãn => giá trị].
     *
     * @return array<string, string>
     */
    private static function subjectSummary(ApprovalRequest $record): array
    {
        if ($record->kind === ApprovalRequest::KIND_PRICE_BOARD_CREATE) {
            $board = \Modules\Product\App\Models\PriceBoard::withoutGlobalScopes()->find($record->subject_id);

            return $board ? array_filter([
                'Tên bảng giá' => (string) $board->name,
                'Phạm vi' => ['rooms' => 'Một số phòng', 'branches' => 'Theo chi nhánh', 'partner' => 'Toàn bộ đối tác'][$board->scope ?: 'rooms'] ?? (string) $board->scope,
                'Hiệu lực' => ($board->start_date?->format('d/m/Y') ?? 'Không giới hạn') . ' → ' . ($board->end_date?->format('d/m/Y') ?? 'Không giới hạn'),
                'Đối tác muốn' => ! empty($record->payload['requested_is_active']) ? 'Bật bảng ngay sau khi duyệt' : 'Để tắt sau khi duyệt',
                'Số bộ giá' => (string) $board->sheets()->count(),
            ]) : [];
        }

        if ($record->kind === ApprovalRequest::KIND_ROOM_CREATE) {
            $room = \Modules\Product\App\Models\Product::withoutGlobalScopes()->find($record->subject_id);

            return $room ? array_filter([
                'Tên phòng' => (string) $room->name,
                'Địa chỉ' => (string) $room->address,
                'Kiểu đặt phòng' => (int) $room->styles === 2 ? 'Theo ngày' : 'Theo khung giờ',
                'Giá' => $room->price !== null ? number_format((float) $room->price, 0, ',', '.') . 'đ' : null,
                'Đối tác muốn' => ($record->payload['requested_is_activated'] ?? true) ? 'Hiện cho khách ngay sau khi duyệt' : 'Để tắt sau khi duyệt',
            ]) : [];
        }

        return [];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListApprovalRequests::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) (self::isPlatformSide() || filled($user?->getRawOriginal('partner_id')));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
