<?php

declare(strict_types=1);

namespace App\Filament\Resources\TienLenMatchResource\RelationManagers;

use App\Filament\Resources\TienLenMatchResource;
use App\Games\TienLen\Card;
use App\Games\TienLen\Combo;
use App\Games\TienLen\Rules;
use App\Models\TienLenMatch;
use App\Models\TienLenMove;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Nhật ký từng nước của ván (chỉ xem).
class MovesRelationManager extends RelationManager
{
    protected static string $relationship = 'moves';

    protected static ?string $title = 'Diễn biến ván';

    public const TYPE_LABELS = [
        'deal'         => 'Chia bài',
        'play'         => 'Đánh',
        'pass'         => 'Bỏ lượt',
        'decline_chop' => 'Không chặt ngoài lượt',
        'round_end'    => 'Hết vòng',
        'chop_settled' => 'Chốt tiền chặt',
        'finish'       => 'Hết bài',
        'cong'         => 'Cóng',
        'instant_win'  => 'Tới trắng',
        'forfeit'      => 'Bỏ ván',
        'left'         => 'Rời bàn',
        'auto_on'      => 'Bot đánh thay',
        'back'         => 'Quay lại',
        'game_end'     => 'Kết thúc ván',
    ];

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event_no')->label('#'),
                TextColumn::make('seat')->label('Người')->formatStateUsing(fn (?int $state) => TienLenMatchResource::seatName($state, $this->getOwnerRecord()))->placeholder('—'),
                TextColumn::make('type')->label('Sự kiện')->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => ['play' => 'primary', 'pass' => 'gray', 'cong' => 'danger', 'instant_win' => 'warning', 'finish' => 'success', 'forfeit' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('cards')->label('Lá bài')->placeholder('—')
                    ->state(fn (TienLenMove $r) => $r->cards ? Card::labels($r->cards) : null),
                TextColumn::make('detail')->label('Chi tiết')->placeholder('—')
                    ->state(fn (TienLenMove $r) => self::detail($r, $this->getOwnerRecord()))
                    ->wrap(),
                TextColumn::make('created_at')->label('Lúc')->dateTime('H:i:s'),
            ])
            ->defaultSort('event_no')
            ->paginated([25, 50, 100]);
    }

    private static function detail(TienLenMove $r, TienLenMatch $match): ?string
    {
        $p = $r->payload ?? [];

        return match ($r->type) {
            'play'         => implode(' · ', array_filter([
                $r->cards ? Combo::detect($r->cards)?->label() : null,
                ! empty($p['chop']) ? 'chặt' : null,
                ! empty($p['out_of_turn']) ? 'ngoài lượt' : null,
                ! empty($p['auto']) ? 'hết giờ, tự đánh' : null,
                isset($p['left']) ? 'còn ' . $p['left'] . ' lá' : null,
            ])),
            'pass', 'decline_chop' => ! empty($p['auto']) ? 'hết giờ, tự bỏ' : null,
            'deal'         => isset($p['must_include']) ? 'Phải đánh kèm ' . Card::label((int) $p['must_include']) : 'Người nhất ván trước đi trước',
            'round_end'    => TienLenMatchResource::seatName($p['leader'] ?? null, $match) . ' mở vòng mới' . (! empty($p['inherited']) ? ' (hưởng sái)' : ''),
            'chop_settled' => TienLenMatchResource::seatName($p['chopper'] ?? null, $match) . ' ăn ' . ($p['units'] ?? 0) . ' của ' . TienLenMatchResource::seatName($p['victim'] ?? null, $match),
            'finish'       => 'Về thứ ' . ($p['place'] ?? '?'),
            'instant_win'  => Rules::INSTANT_LABELS[$p['kind'] ?? ''] ?? null,
            default        => null,
        };
    }
}
