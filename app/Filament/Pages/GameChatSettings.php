<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\GameChatSetting;
use App\Services\GameChatService;
use App\Services\GameVoiceService;
use App\Support\MiniGameKey;
use App\Support\VoiceConfig;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FieldAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

// Cấu hình chat / sticker / gọi thoại trong trận của Tiến lên và Caro + máy chủ TURN (chỉ Super Admin)
// — cùng dữ liệu với /api/admin/games/chat-settings và /api/admin/games/voice-settings.
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
        $voice = VoiceConfig::adminPayload();

        $this->form->fill(collect(MiniGameKey::all())->mapWithKeys(fn (string $game) => [
            $game => GameChatService::settingsPayload(GameChatSetting::for($game)),
        ])->all() + [
            'voice' => [
                'turn_urls'         => implode("\n", $voice['turn_urls'] ?? []),
                'stun_urls'         => implode("\n", $voice['stun_urls'] ?? []),
                'turn_secret'       => null,
                'clear_turn_secret' => false,
                'turn_ttl_hours'    => $voice['turn_ttl'] ? round($voice['turn_ttl'] / 3600, 2) : null,
            ],
        ]);
    }

    public function form(Form $form): Form
    {
        $limit = fn (string $field, string $label, ?string $suffix = null) => TextInput::make($field)->label($label)->suffix($suffix)
            ->numeric()->integer()->required()
            ->minValue(GameChatService::SETTING_LIMITS[$field][0])->maxValue(GameChatService::SETTING_LIMITS[$field][1]);

        $games = array_map(fn (string $game) => Section::make(MiniGameKey::label($game))
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
            ]), MiniGameKey::all());

        $voice = Section::make('Máy chủ TURN (gọi thoại)')
            ->description('Bỏ trống thì dùng giá trị trong .env.')
            ->statePath('voice')
            ->collapsible()
            ->schema([
                Placeholder::make('status')->hiddenLabel()->content(fn () => self::voiceStatus()),
                Grid::make(2)->schema([
                    Textarea::make('turn_urls')->label('Địa chỉ TURN')->rows(3)
                        ->placeholder("turn:turn.365home.vn:3478?transport=udp\nturn:turn.365home.vn:3478?transport=tcp\nturns:turn.365home.vn:5349?transport=tcp")
                        ->helperText('Mỗi dòng 1 địa chỉ.'),
                    Textarea::make('stun_urls')->label('Địa chỉ STUN')->rows(3)
                        ->placeholder('stun:turn.365home.vn:3478')
                        ->helperText('Mỗi dòng 1 địa chỉ.'),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('turn_secret')->label('Khoá bí mật (static-auth-secret)')->password()->revealable()->columnSpan(2)
                        ->placeholder(fn () => VoiceConfig::adminPayload()['turn_secret_set'] ? 'Đã lưu — để trống nếu giữ nguyên' : 'Chưa có')
                        ->helperText('Phải giống dòng static-auth-secret trong /etc/turnserver.conf.')
                        ->suffixAction(FieldAction::make('generate')->icon('heroicon-o-sparkles')->tooltip('Tạo khoá ngẫu nhiên')
                            ->action(fn (Set $set) => $set('turn_secret', VoiceConfig::randomSecret()))),
                    TextInput::make('turn_ttl_hours')->label('Hạn mật khẩu TURN')->suffix('giờ')->numeric()->minValue(0.1)->maxValue(168)
                        ->placeholder('6'),
                ]),
                Toggle::make('clear_turn_secret')->label('Xoá khoá đã lưu (quay về .env)')
                    ->visible(fn () => VoiceConfig::adminPayload()['turn_secret_set']),
            ]);

        return $form->statePath('data')->schema([...$games, $voice]);
    }

    // Trạng thái đang dùng: TURN có / chưa, lấy từ CMS hay .env
    private static function voiceStatus(): HtmlString
    {
        $p     = VoiceConfig::adminPayload();
        $src   = fn (string $k) => match ($p['sources'][$k]) { 'cms' => 'CMS', 'env' => '.env', default => 'chưa có' };
        $ok    = $p['effective']['turn_configured'];
        $color = $ok ? 'rgb(22 163 74)' : 'rgb(217 119 6)';

        return new HtmlString('<div style="font-size:13px;line-height:1.7">'
            . '<span style="color:' . $color . ';font-weight:600">' . ($ok ? 'TURN đang bật' : 'Chưa có TURN — chỉ nối thẳng được (Wi-Fi), mạng 4G có thể không gọi được') . '</span><br>'
            . 'Địa chỉ TURN: ' . e($src('turn_urls')) . ' · Khoá: ' . e($src('turn_secret')) . ' · STUN: ' . e($src('stun_urls'))
            . ' · Hạn mật khẩu: ' . round($p['effective']['turn_ttl'] / 3600, 2) . ' giờ</div>');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (MiniGameKey::all() as $game) {
            GameChatService::updateSettings($game, $data[$game] ?? []);
        }

        $v = $data['voice'] ?? [];
        VoiceConfig::save([
            'turn_urls'         => $v['turn_urls'] ?? '',
            'stun_urls'         => $v['stun_urls'] ?? '',
            'turn_secret'       => $v['turn_secret'] ?? null,
            'clear_turn_secret' => (bool) ($v['clear_turn_secret'] ?? false),
            'turn_ttl'          => filled($v['turn_ttl_hours'] ?? null) ? (int) round((float) $v['turn_ttl_hours'] * 3600) : null,
        ], 'data.voice.');

        $this->mount();
        Notification::make()->title('Đã lưu cấu hình chat & gọi thoại')->success()->send();
    }
}
