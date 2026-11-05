<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\RelationManagers;

use App\Models\ContractShareLink;
use App\Models\ContractShareLinkAccess;
use App\Services\ContractShareLinkService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

// Link chia sẻ tải hợp đồng (kiểu Google Drive) — cùng service với API admin .../contract/share-links (App\Services\ContractShareLinkService).
class ContractShareLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'contractShareLinks';

    protected static ?string $title = 'Link chia sẻ hợp đồng';

    protected static ?string $modelLabel = 'link chia sẻ';

    protected static ?string $pluralModelLabel = 'link chia sẻ';

    protected static ?string $icon = 'heroicon-o-link';

    public function table(Table $table): Table
    {
        $service = app(ContractShareLinkService::class);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['version', 'creator:id,fullname']))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('share_url')->label('Link')
                    ->state(fn (ContractShareLink $record) => $record->state() === 'active' ? $record->url() : null)
                    ->formatStateUsing(fn (?string $state) => $state ? '…/' . substr($state, -12) : null)
                    ->placeholder('—')
                    ->icon(fn (ContractShareLink $record) => $record->state() === 'active' ? 'heroicon-o-clipboard-document' : null)
                    ->copyable()
                    ->copyableState(fn (ContractShareLink $record) => $record->url())
                    ->copyMessage('Đã sao chép link')
                    ->tooltip('Bấm để sao chép link'),
                Tables\Columns\TextColumn::make('state')->label('Trạng thái')->badge()
                    ->state(fn (ContractShareLink $record) => $record->state())
                    ->formatStateUsing(fn (string $state) => ContractShareLink::STATES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('version.version_label')->label('Phiên bản')->placeholder('—')
                    ->description(fn (ContractShareLink $record) => $record->version ? ContractShareLinkService::SIGNATURE_STATES[$service->signatureState($record->version)] : null),
                Tables\Columns\IconColumn::make('password')->label('Mật khẩu')
                    ->state(fn (ContractShareLink $record) => $record->hasPassword())->boolean()
                    ->trueIcon('heroicon-o-lock-closed')->falseIcon('heroicon-o-minus')->falseColor('gray'),
                Tables\Columns\TextColumn::make('expires_at')->label('Hết hạn')->dateTime('d/m/Y H:i')->placeholder('Không hết hạn'),
                Tables\Columns\TextColumn::make('download_count')->label('Lượt xem / tải')
                    ->formatStateUsing(fn (ContractShareLink $record) => $record->view_count . ' / ' . $record->download_count . ($record->max_downloads ? ' (tối đa ' . $record->max_downloads . ')' : '')),
                Tables\Columns\TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')
                    ->description(fn (ContractShareLink $record) => $record->creator?->fullname),
            ])
            ->headerActions([
                Tables\Actions\Action::make('createShareLink')
                    ->label('Tạo link chia sẻ')
                    ->icon('heroicon-o-link')
                    ->modalHeading('Tạo link chia sẻ hợp đồng')
                    ->modalDescription('Ai có link đều mở và tải được phiên bản hợp đồng mới nhất. Hợp đồng chưa ký được đóng chìm "BẢN CHƯA KÝ".')
                    ->modalSubmitActionLabel('Tạo link')
                    ->disabled(fn () => ! $this->getOwnerRecord()->contractVersions()->exists())
                    ->tooltip(fn () => $this->getOwnerRecord()->contractVersions()->exists() ? null : 'Đối tác chưa có hợp đồng')
                    ->form([
                        Forms\Components\Select::make('expires_in_days')->label('Thời hạn link')
                            ->options(ContractShareLinkService::EXPIRY_OPTIONS)->default(0)->selectablePlaceholder(false)->required(),
                        Forms\Components\TextInput::make('max_downloads')->label('Giới hạn lượt tải')
                            ->numeric()->minValue(1)->maxValue(10000)->placeholder('Không giới hạn'),
                        Forms\Components\Toggle::make('use_password')->label('Đặt mật khẩu')->default(false)->live(),
                        Forms\Components\TextInput::make('password')->label('Mật khẩu')
                            ->password()->revealable()->minLength(4)->maxLength(100)
                            ->required(fn (Forms\Get $get) => (bool) $get('use_password'))
                            ->visible(fn (Forms\Get $get) => (bool) $get('use_password')),
                    ])
                    ->action(function (array $data) use ($service) {
                        try {
                            $result = $service->create($this->getOwnerRecord(), auth()->user(), [
                                'expires_in_days' => (int) ($data['expires_in_days'] ?? 0),
                                'max_downloads' => $data['max_downloads'] ?? null,
                                'password' => ! empty($data['use_password']) ? ($data['password'] ?? null) : null,
                            ]);
                        } catch (ValidationException $e) {
                            Notification::make()->title('Chưa tạo được link')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

                            return;
                        }

                        Notification::make()->title('Đã tạo link chia sẻ')
                            ->body(new HtmlString('<span style="word-break:break-all;">' . e($result['url']) . '</span><br>Bấm vào link ở bảng để sao chép.'))
                            ->success()->persistent()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('open')->label('Mở')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->visible(fn (ContractShareLink $record) => $record->state() === 'active' && $record->url() !== null)
                    ->url(fn (ContractShareLink $record) => $record->url())
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('accesses')->label('Nhật ký')->icon('heroicon-o-clock')->color('gray')
                    ->modalHeading('Nhật ký mở/tải link')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (ContractShareLink $record) => new HtmlString($this->renderAccesses($record))),
                Tables\Actions\Action::make('revoke')->label('Thu hồi')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (ContractShareLink $record) => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Thu hồi link chia sẻ?')
                    ->modalDescription('Link ngừng hoạt động ngay, người đã nhận link không mở được nữa.')
                    ->action(function (ContractShareLink $record) use ($service) {
                        $service->revoke($record, auth()->user());
                        Notification::make()->title('Đã thu hồi link')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Chưa có link chia sẻ');
    }

    private function renderAccesses(ContractShareLink $link): string
    {
        $accesses = $link->accesses()->limit(100)->get();
        if ($accesses->isEmpty()) {
            return '<p style="font-size:0.875rem;color:#6b7280;">Chưa có ai mở link.</p>';
        }

        $rows = $accesses->map(fn (ContractShareLinkAccess $access) => '<tr style="border-top:1px solid #e5e7eb;">'
            . '<td style="padding:6px 8px;white-space:nowrap;">' . e($access->created_at?->format('d/m/Y H:i:s')) . '</td>'
            . '<td style="padding:6px 8px;">' . e(ContractShareLinkAccess::ACTIONS[$access->action] ?? $access->action) . '</td>'
            . '<td style="padding:6px 8px;">' . e($access->ip) . '</td>'
            . '<td style="padding:6px 8px;color:#6b7280;word-break:break-all;">' . e(\Illuminate\Support\Str::limit((string) $access->user_agent, 80)) . '</td>'
            . '</tr>')->implode('');

        return '<div style="max-height:420px;overflow-y:auto;"><table style="width:100%;font-size:0.8rem;border-collapse:collapse;">'
            . '<thead><tr style="text-align:left;color:#6b7280;"><th style="padding:6px 8px;">Thời gian</th><th style="padding:6px 8px;">Hành động</th><th style="padding:6px 8px;">IP</th><th style="padding:6px 8px;">Thiết bị</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && $ownerRecord->usesContract() && ! $ownerRecord->isSystemPartner();
    }
}
