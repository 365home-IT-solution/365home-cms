<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament\Resources\CommentProductResource\Tables\Actions;

use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Comment\Entities\CommentReply;

class CommentProductAction
{
    public static function action()
    {
        return [
            ActionGroup::make([
                ViewAction::make()->label('Xem chi tiết')->color('success'),
                EditAction::make()->label('Cập nhật')->color('warning'),
                Action::make('Phản hồi')
                    ->label('Phản hồi')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->model(CommentReply::class)
                    ->color('info')
                    ->form([
                        TextInput::make('Phản hồi đến')
                            ->label('Phản hồi đến')
                            ->disabled()
                            ->default(fn($record) => $record->name)
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label('Họ và tên')
                            ->columnSpanFull(),

                        Section::make([
                            Toggle::make('show')
                                ->label('Trạng thái (Ẩn / Hiện)')
                                ->onIcon('heroicon-m-eye')
                                ->offIcon('heroicon-m-eye-slash')
                                ->onColor('success')
                                ->columnSpan(4),

                            Toggle::make('pin')
                                ->label('Ghim bình luận')
                                ->onIcon('heroicon-m-bookmark')
                                ->offIcon('heroicon-m-bookmark-slash')
                                ->onColor(Color::Fuchsia)
                                ->columnSpan(4),
                            Textarea::make('text')
                                ->label('Nội dung')
                                ->rules('required')
                                ->columnSpan(12),

                            Hidden::make('account_id')
                                ->label('')
                                ->default(function () {
                                    $user = User::find(Auth::id());
                                    return $user ? $user->id : 'Không xác định';
                                })->columnSpan(4),

                            Hidden::make('comment_id')
                                ->default(fn($record) => $record->id)
                                ->columnSpan(4),
                        ])->columns(12),
                    ])
                    ->action(function ($data, $record) {
                        // Lưu phản hồi bình luận
                        CommentReply::create([
                            'text' => $data['text'],
                            'name' => $data['name'],
                            'show' => $data['show'],
                            'pin' => $data['pin'],
                            'account_id' => Auth::id(),
                            'comment_id' => $data['comment_id'],

                        ]);
                        Notification::make()
                            ->title('Phản hồi thành công!')
                            ->success()
                            ->send();

                    })->slideOver(),

                DeleteAction::make('Xóa')
            ])

        ];
    }

    public static function bulkActions(): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([

                Tables\Actions\DeleteBulkAction::make(),

                Tables\Actions\BulkAction::make('Ẩn tất cả')
                    ->icon('heroicon-o-eye-slash')
                    ->color('primary')
                    ->action(function (Collection $records) {
                        $records->each(function ($record) {
                            $record->update(['show' => false]);
                        });
                        Notification::make()
                            ->title('Ẩn thành công')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),

                Tables\Actions\BulkAction::make('Hiển thị tất cả')
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->action(function (Collection $records) {
                        $records->each(function ($record) {
                            $record->update(['show' => true]);
                        });
                        Notification::make()
                            ->title('Hiện thành công')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation(),
            ]),
        ];
    }
}
