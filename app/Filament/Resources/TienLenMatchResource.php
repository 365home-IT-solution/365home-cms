<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TienLenMatchResource\Pages;
use App\Filament\Resources\TienLenMatchResource\RelationManagers\MovesRelationManager;
use App\Games\TienLen\Card;
use App\Games\TienLen\Rules;
use App\Models\TienLenMatch;
use App\Services\TienLenService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Các ván Tiến lên của khách (chỉ xem, chỉ Super Admin) — cùng dữ liệu với /api/admin/games/tien-len/matches.
// Cài đặt game (bật/tắt, điểm theo hạng…) là nút "Cài đặt Tiến lên" ở đầu danh sách.
class TienLenMatchResource extends Resource
{
    protected static ?string $model = TienLenMatch::class;

    protected static ?string $navigationIcon   = 'heroicon-o-puzzle-piece';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Tiến lên — Ván chơi';
    protected static ?string $modelLabel       = 'Ván Tiến lên';
    protected static ?string $pluralModelLabel = 'Ván Tiến lên';
    protected static ?string $slug             = 'game-tien-len-matches';
    protected static ?int    $navigationSort   = 35;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'room']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Bắt đầu')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('mode')->label('Chế độ')->badge()
                    ->state(fn (TienLenMatch $r) => $r->room_id ? 'Phòng ' . ($r->room?->code ?? '') : 'Với máy')
                    ->color(fn (TienLenMatch $r) => $r->room_id ? 'info' : 'gray'),
                TextColumn::make('customer.fullname')->label('Khách / chủ phòng')->searchable(),
                TextColumn::make('customer.phone')->label('SĐT')->searchable(),
                TextColumn::make('player_count')->label('Bàn')
                    ->formatStateUsing(fn (int $state) => $state . ' người (' . ($state - 1) . ' máy)'),
                TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => TienLenMatch::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => [TienLenMatch::STATUS_PLAYING => 'info', TienLenMatch::STATUS_FINISHED => 'success', TienLenMatch::STATUS_ABANDONED => 'gray'][$state] ?? 'gray'),
                TextColumn::make('players_summary')->label('Người chơi')->wrap()
                    ->state(fn (TienLenMatch $r) => collect($r->seatList())->map(fn (array $s) => $s['name'])->join(', ')),
                TextColumn::make('human_rank')->label('Khách về')->placeholder('—')
                    ->formatStateUsing(fn (?int $state, TienLenMatch $r) => $state ? TienLenService::rankLabel($state, $r->player_count) : '—'),
                TextColumn::make('instant_win')->label('Tới trắng')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? (Rules::INSTANT_LABELS[$state] ?? $state) : '—'),
                TextColumn::make('human_score')->label('Điểm ván')->placeholder('—'),
                TextColumn::make('reward_points')->label('Điểm thưởng')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(TienLenMatch::STATUS_LABELS),
                Filter::make('mode')->label('Chế độ')
                    ->form([Select::make('mode')->label('Chế độ')->options(['bot' => 'Với máy', 'room' => 'Phòng nhiều người'])])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(($data['mode'] ?? null) === 'bot', fn ($q) => $q->whereNull('room_id'))
                        ->when(($data['mode'] ?? null) === 'room', fn ($q) => $q->whereNotNull('room_id'))),
                SelectFilter::make('player_count')->label('Số người')->options([2 => '2 người', 3 => '3 người', 4 => '4 người']),
                Filter::make('range')->label('Khoảng ngày')
                    ->form([DatePicker::make('from')->label('Từ ngày'), DatePicker::make('to')->label('Đến ngày')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))),
            ])
            ->actions([ViewAction::make()->label('Xem')])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Thông tin ván')->schema([
                Grid::make(4)->schema([
                    TextEntry::make('mode')->label('Chế độ')
                        ->state(fn (TienLenMatch $r) => $r->room_id ? 'Phòng ' . ($r->room?->code ?? '') : 'Chơi với máy'),
                    TextEntry::make('customer.fullname')->label('Khách / chủ phòng'),
                    TextEntry::make('customer.phone')->label('SĐT'),
                    TextEntry::make('player_count')->label('Bàn')->formatStateUsing(fn (int $state) => $state . ' người (' . ($state - 1) . ' máy)'),
                    TextEntry::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => TienLenMatch::STATUS_LABELS[$state] ?? $state),
                    TextEntry::make('human_rank')->label('Khách về')->placeholder('—')
                        ->formatStateUsing(fn (?int $state, TienLenMatch $r) => $state ? TienLenService::rankLabel($state, $r->player_count) : '—'),
                    TextEntry::make('human_score')->label('Điểm ván')->placeholder('—'),
                    TextEntry::make('reward_points')->label('Điểm thưởng'),
                    TextEntry::make('is_first_match')->label('Ván đầu')->formatStateUsing(fn (bool $state) => $state ? 'Có (đi trước bằng lá nhỏ nhất)' : 'Không (người nhất ván trước đi)'),
                    TextEntry::make('created_at')->label('Bắt đầu')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('finished_at')->label('Kết thúc')->dateTime('d/m/Y H:i:s')->placeholder('—'),
                ]),
            ]),

            Section::make('Người chơi')->schema([
                TextEntry::make('players_list')->label('')
                    ->state(fn (TienLenMatch $r) => self::playerLines($r))
                    ->listWithLineBreaks(),
            ]),

            Section::make('Kết quả')->schema([
                TextEntry::make('ranking')->label('')
                    ->state(fn (TienLenMatch $r) => self::rankingLines($r))
                    ->listWithLineBreaks(),
            ])->visible(fn (TienLenMatch $r) => ! empty($r->state['result'])),

            Section::make('Bài chia ban đầu')->schema([
                TextEntry::make('initial_hands')->label('')
                    ->state(fn (TienLenMatch $r) => collect($r->state['initial_hands'] ?? [])
                        ->map(fn (array $hand, int $seat) => self::seatName($seat, $r) . ': ' . Card::labels($hand))->values()->all())
                    ->listWithLineBreaks(),
            ])->collapsible(),
        ]);
    }

    public static function seatName(?int $seat, ?TienLenMatch $match = null): string
    {
        if ($seat === null) {
            return '—';
        }

        return $match ? ($match->seatList()[$seat]['name'] ?? 'Ghế ' . $seat) : ($seat === TienLenService::HUMAN ? 'Khách' : 'Máy ' . $seat);
    }

    /** @return string[] */
    private static function playerLines(TienLenMatch $r): array
    {
        $rows = $r->players()->get()->keyBy('seat');

        return collect($r->seatList())->map(function (array $s, int $seat) use ($rows, $r) {
            $p    = $rows->get($seat);
            $tags = array_filter([
                $s['is_bot'] ? 'bot' : null,
                ($s['left'] ?? false) ? 'đã rời' : null,
                ! ($s['left'] ?? false) && ($s['auto'] ?? false) ? 'bot đánh thay' : null,
            ]);

            return 'Ghế ' . ($seat + 1) . ': ' . $s['name'] . ($tags ? ' (' . implode(', ', $tags) . ')' : '')
                . ($p && $p->rank ? ' — về ' . mb_strtolower(TienLenService::rankLabel($p->rank, $r->player_count)) . ', +' . $p->reward_points . ' điểm' : '');
        })->values()->all();
    }

    /** @return string[] */
    private static function rankingLines(TienLenMatch $r): array
    {
        $result = $r->state['result'] ?? [];
        $lines  = collect($result['ranks'] ?? [])->sort()->map(fn (int $rank, int $seat) => self::seatName($seat, $r) . ' — ' . TienLenService::rankLabel($rank, $r->player_count)
            . ' (' . sprintf('%+d', $result['scores'][$seat] ?? 0) . ')'
            . (in_array($seat, $r->state['cong'] ?? [], true) ? ' — cóng' : '')
            . (($result['forfeit'] ?? null) === $seat ? ' — bỏ ván' : ''))->values()->all();

        foreach ($result['chops'] ?? [] as $c) {
            $lines[] = 'Chặt: ' . self::seatName($c['chopper'], $r) . ' chặt ' . self::seatName($c['victim'], $r) . ' (' . $c['units'] . ')';
        }
        foreach ($result['penalties'] ?? [] as $p) {
            $lines[] = ($p['kind'] === 'cong' ? 'Cóng: ' : 'Thối: ') . self::seatName($p['seat'], $r) . ' đền ' . self::seatName($p['to'], $r) . ' (' . $p['units'] . ')';
        }
        if ($r->room_id === null && ($reward = $result['reward'] ?? null)) {
            $lines[] = 'Điểm thưởng: ' . $reward['rank_points'] . ' theo hạng + ' . $reward['chop_bonus'] . ' thưởng chặt'
                . ($reward['daily_cap_reached'] ? ' — chạm trần ngày, nhận ' . $reward['total'] : '');
        }

        return $lines;
    }

    public static function getRelations(): array
    {
        return [MovesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTienLenMatches::route('/'),
            'view'  => Pages\ViewTienLenMatch::route('/{record}'),
        ];
    }
}
