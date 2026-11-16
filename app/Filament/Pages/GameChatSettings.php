<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\GameChatSetting;
use App\Services\GameChatService;
use App\Services\GameVoiceService;
use App\Support\MiniGameKey;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

// Cấu hình chat / sticker trong trận của Tiến lên và Caro (chỉ Super Admin) — cùng dữ liệu với /api/admin/games/chat-settings.
class GameChatSettings extends Page
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Quản lý API';
    protected static ?string $navigationLabel = 'Mini game — Chat';
    protected static ?string $title           = 'Chat trong mini game';
    protected static ?string $slug            = 'game-chat-settings';
    protected static ?int    $navigationSort  = 38;

    protected static string $view = 'filament.pages.game-chat-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(collect(MiniGameKey::all())->mapWithKeys(fn (string $game) => [
            $game => GameChatService::settingsPayload(GameChatSetting::for($game)),
        ])->all());
    }

    public function form(Form $form): Form
    {
        $limit = fn (string $field, string $label, ?string $suffix = null) => TextInput::make($field)->label($label)->suffix($suffix)
            ->numeric()->integer()->required()
            ->minValue(GameChatService::SETTING_LIMITS[$field][0])->maxValue(GameChatService::SETTING_LIMITS[$field][1]);

        return $form->statePath('data')->schema(array_map(fn (string $game) => Section::make(MiniGameKey::label($game))
            ->statePath($game)
            ->schema([
                Grid::make(3)->schema([
                    Toggle::make('chat_enabled')->label('Cho chat chữ')->inline(false),
                    Toggle::make('sticker_enabled')->label('Cho gửi sticker')->inline(false),
                    Toggle::make('voice_enabled')->label('Cho gọi thoại')->inline(false)
                        ->helperText(fn () => ! GameVoiceService::configured() ? 'Máy chủ chưa cấu hình WS server.' : (! GameVoiceService::turnConfigured() ? 'Chưa cấu hình TURN — mạng 4G có thể không nối được.' : null)),
                ]),
                Grid::make(4)->schema([
                    $limit('max_length', 'Độ dài tối đa', 'ký tự'),
                    $limit('message_cooldown_seconds', 'Giãn cách tin nhắn', 'giây'),
                    $limit('sticker_cooldown_seconds', 'Giãn cách sticker', 'giây'),
                    $limit('history_size', 'Số tin giữ tạm', 'tin')
                        ->helperText('Để khách vào lại vẫn thấy, không lưu DB.'),
                ]),
                TagsInput::make('banned_words')->label('Từ cấm')->placeholder('Nhập từ rồi Enter')
                    ->helperText('Tin nhắn chứa từ cấm bị thay bằng ***.'),
            ]), MiniGameKey::all()));
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (MiniGameKey::all() as $game) {
            GameChatService::updateSettings($game, $data[$game] ?? []);
        }

        $this->mount();
        Notification::make()->title('Đã lưu cấu hình chat')->success()->send();
    }
}
