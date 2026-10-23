<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CollectionCampaignResource\Pages;
use App\Filament\Resources\CollectionCampaignResource\RelationManagers\PiecesRelationManager;
use App\Filament\Support\GameFormFields;
use App\Models\CollectionCampaign;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

// Chiến dịch SƯU TẦM MẢNH GHÉP theo chi nhánh (chỉ Super Admin, chỉ cấu hình cho app) — cùng dữ liệu với /api/admin/games/collections.
// Chi nhánh tham gia + ảnh mảnh quản lý ở tab "Mảnh ghép" khi sửa chiến dịch.
class CollectionCampaignResource extends Resource
{
    protected static ?string $model = CollectionCampaign::class;

    protected static ?string $navigationIcon   = 'heroicon-o-puzzle-piece';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Mảnh ghép — Chiến dịch';
    protected static ?string $modelLabel       = 'Chiến dịch mảnh ghép';
    protected static ?string $pluralModelLabel = 'Chiến dịch mảnh ghép';
    protected static ?string $slug             = 'game-collections';
    protected static ?int    $navigationSort   = 33;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Chiến dịch')->schema([
                TextInput::make('name')->label('Tên chiến dịch')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('Mô tả')->rows(3)->columnSpanFull(),

                FileUpload::make('cover_image')
                    ->label('Ảnh bìa')
                    ->image()
                    ->disk('public')
                    ->directory('collections')
                    ->maxSize(5120)
                    ->columnSpanFull(),

                Grid::make(3)->schema([
                    DateTimePicker::make('start_at')->label('Bắt đầu')->seconds(false)->required(),
                    DateTimePicker::make('end_at')->label('Kết thúc')->seconds(false)->required()->after('start_at'),
                    DateTimePicker::make('redeem_until')->label('Hạn đổi thưởng')->helperText('Không sớm hơn ngày kết thúc.')->seconds(false)->required()->afterOrEqual('end_at'),
                ]),

                Grid::make(2)->schema([
                    TextInput::make('pieces_required')
                        ->label('Số mảnh cần đủ')
                        ->helperText('Không vượt quá số chi nhánh tham gia. Không đổi được khi chiến dịch đã bắt đầu và có người nhận mảnh.')
                        ->numeric()->integer()->minValue(1)->required(),

                    Toggle::make('is_active')->label('Đang bật')->default(true)->inline(false),
                ]),
            ]),

            Section::make('Phần thưởng khi đủ mảnh')->schema([
                TextInput::make('reward_label')->label('Tên phần thưởng')->required()->maxLength(255)->columnSpanFull(),
                Grid::make(3)->schema(GameFormFields::coupon()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover_image')->label('Ảnh bìa')->disk('public')->height(48),
                TextColumn::make('name')->label('Chiến dịch')->searchable(),
                TextColumn::make('pieces_count')->label('Chi nhánh')->counts('pieces'),
                TextColumn::make('pieces_required')->label('Cần đủ'),
                TextColumn::make('start_at')->label('Bắt đầu')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('end_at')->label('Kết thúc')->dateTime('d/m/Y H:i'),
                TextColumn::make('reward_label')->label('Phần thưởng'),
                IconColumn::make('is_active')->label('Đang bật')->boolean(),
            ])
            ->defaultSort('start_at', 'desc')
            ->actions([
                EditAction::make()->label('Sửa'),
                // Đã có người nhận mảnh thì chỉ tắt, không xoá.
                DeleteAction::make()->label('Xoá')
                    ->action(function (CollectionCampaign $record): void {
                        if ($record->customerPieces()->exists()) {
                            $record->update(['is_active' => false]);
                            Notification::make()->title('Chiến dịch đã có người nhận mảnh nên chỉ được tắt, không xoá.')->warning()->send();

                            return;
                        }

                        $record->pieces()->delete();
                        $record->delete();
                        Notification::make()->title('Đã xoá chiến dịch.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /** Luật khi sửa chiến dịch đã có dữ liệu — giống CollectionAdminController::update. */
    public static function guardUpdate(CollectionCampaign $campaign, array $data): void
    {
        if ((int) ($data['pieces_required'] ?? $campaign->pieces_required) === $campaign->pieces_required) {
            return;
        }

        if ($campaign->isLocked()) {
            throw ValidationException::withMessages(['data.pieces_required' => 'Chiến dịch đã bắt đầu và có người nhận mảnh, không thể đổi số mảnh cần đủ.']);
        }

        if ((int) $data['pieces_required'] > $campaign->pieces()->count()) {
            throw ValidationException::withMessages(['data.pieces_required' => 'Số mảnh cần đủ không được lớn hơn số chi nhánh tham gia.']);
        }
    }

    public static function getRelations(): array
    {
        return [PiecesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCollectionCampaigns::route('/'),
            'create' => Pages\CreateCollectionCampaign::route('/create'),
            'edit'   => Pages\EditCollectionCampaign::route('/{record}/edit'),
        ];
    }
}
