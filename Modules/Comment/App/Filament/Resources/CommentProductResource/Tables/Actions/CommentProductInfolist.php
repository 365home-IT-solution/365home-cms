<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Tables\Actions;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Modules\Comment\Entities\Comment;
use Modules\Comment\Entities\CommentReply;
use Filament\Support\Enums\IconPosition;
use Modules\Product\App\Models\Product;

class CommentProductInfolist
{
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tabs\Tab::make('Thông tin bình luận')
                            ->icon('heroicon-m-identification')
                            ->schema([
                                Section::make('')->schema([
                                    TextEntry::make('commentable.name')
                                        ->label('Tên sản phẩm: ')
                                        ->weight(FontWeight::Bold)
                                        ->icon('heroicon-m-link')
                                        
                                        ->url(fn($record) => $record->commentable
                                            ? config('app.domain') . ($record->commentable->slug
                                                ? "/san-pham/" . $record->commentable->slug
                                                : "/mon-an/" . $record->commentable->slug)
                                            : null)
                                        ->openUrlInNewTab()
                                        ->state(fn($record) => $record->commentable && $record->commentable->name
                                            ? $record->commentable->name
                                            : 'Sản phẩm này đã bị xóa')
                                        ->color(Color::Blue)
                                        ->columnSpan(7),
                                    IconEntry::make('show')
                                        ->label('')
                                        ->boolean()
                                        ->tooltip(function ($record) {
                                            return $record->show ? 'Hiển thị' : 'Ẩn';
                                        })
                                        ->trueIcon('heroicon-o-eye')
                                        ->falseIcon('heroicon-o-eye-slash')
                                        ->alignCenter()
                                        ->columnSpan(1),
                                    IconEntry::make('pin')
                                        ->label('')
                                        ->tooltip('Ghim bình luận')
                                        ->hidden(fn (Comment $record) => !$record->pin)
                                        ->default('')
                                        ->color(Color::Orange)
                                        ->icon('heroicon-o-bookmark')
                                        ->columnSpan(1),
                                    TextEntry::make('text')
                                        ->label('Nội dung: ')
                                        ->alignJustify()
                                        ->weight(FontWeight::Bold)
                                        ->columnSpan(12),
                                ])->columnSpan(9),
                                Section::make('')->schema([
                                    TextEntry::make('name')
                                        ->label('Người bình luận: ')
                                        ->weight(FontWeight::Bold),
                                    TextEntry::make('created_at')
                                        ->label('Ngày tạo: ')
                                        ->badge()
                                        ->color('primary')
                                        ->dateTime(),
                                ])->columnSpan(3),
                            ])->columns(12),
                        Tabs\Tab::make('Người phản hồi bình luận')
                            ->badge(function (Comment $record): int {
                                return $record->replies()->count();
                            })
                            ->icon('heroicon-o-user-group')
                            ->schema([
                                RepeatableEntry::make('replies')
                                    ->label('')
                                    ->schema([
                                        Section::make('')->schema([
                                            TextEntry::make('account.name')
                                                ->label('Người phản hồi: ')
                                                ->weight(FontWeight::Bold)
                                                ->columnSpan(7),
                                            IconEntry::make('show')
                                                ->label('')
                                                ->boolean()
                                                ->tooltip(function ($record) {
                                                    return $record->show ? 'Hiển thị' : 'Ẩn';
                                                })
                                                ->trueIcon('heroicon-o-eye')
                                                ->falseIcon('heroicon-o-eye-slash')
                                                ->alignCenter()
                                                ->columnSpan(1),

                                            IconEntry::make('pin')
                                                ->label('')
                                                ->tooltip('Ghim bình luận')
                                                ->hidden(fn (CommentReply $record) => !$record->pin)
                                                ->default('')
                                                ->color(Color::Orange)
                                                ->icon('heroicon-o-bookmark')
                                                ->columnSpan(1),

                                            TextEntry::make('text')
                                                ->label('Nội dung: ')
                                                ->alignJustify()
                                                ->weight(FontWeight::Bold)
                                                ->columnSpan(12),
                                        ])->columnSpan(9),
                                        Section::make('')->schema([
                                            TextEntry::make('created_at')
                                                ->label('Ngày tạo: ')
                                                ->badge()
                                                ->color('primary')
                                                ->dateTime(),
                                        ])->columnSpan(3),
                                    ])->columns(12)
                            ]),
                    ])
                    ->activeTab(1)
                    ->columnSpan(12),
            ])->columns(12);
    }
}
