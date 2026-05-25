<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Tables;

use Filament\Forms\Components\CheckboxList;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Category\Entities\Category;
use Modules\DataPermission\Entities\UserBranchPermission;
use Illuminate\Support\HtmlString;

class UserBranchPermissionTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.fullname')
                    ->label('Người dùng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->color('gray'),

                // Hiển thị tất cả chi nhánh của user này dưới dạng badge (phân màu theo type)
                TextColumn::make('all_branches')
                    ->label('Quyền được phép')
                    ->getStateUsing(function ($record) {
                        $permissions = UserBranchPermission::where('user_id', $record->user_id)
                            ->with('branch')
                            ->get();

                        if ($permissions->isEmpty()) {
                            return '—';
                        }

                        $badges = $permissions->map(function ($p) {
                            $name = $p->branch?->name;
                            if (! $name) return '';

                            $isPost = $p->branch?->category_type === 'post';
                            $bg     = $isPost ? '#3b82f6' : '#22c55e';

                            return '<span style="display:inline-block;background:' . $bg . ';color:#fff;padding:2px 10px;border-radius:9999px;font-size:12px;margin:2px">'
                                . e($name) . '</span>';
                        })->filter()->join(' ');

                        return new HtmlString($badges ?: '—');
                    })
                    ->html()
                    ->wrap(),

                TextColumn::make('created_at')
                    ->label('Ngày gán đầu tiên')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Danh mục / Chi nhánh')
                    ->options(fn () => Category::query()
                        ->whereNull('parent_id')
                        ->orderBy('category_type')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($c) => [
                            $c->id => $c->name,
                        ])
                        ->toArray()
                    )
                    ->query(function ($query, array $data) {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }
                        $userIds = UserBranchPermission::where('category_id', $data['value'])
                            ->pluck('user_id');
                        return $query->whereIn('user_id', $userIds);
                    })
                    ->searchable(),
            ])
            ->actions([
                // Quản lý chi nhánh — mở modal với CheckboxList
                Action::make('manage_branches')
                    ->label('Sửa quyền')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->fillForm(function ($record): array {
                        $allIds = UserBranchPermission::where('user_id', $record->user_id)
                            ->with('branch')
                            ->get();

                        return [
                            'product_category_ids' => $allIds
                                ->filter(fn ($p) => $p->branch?->category_type === 'product')
                                ->pluck('category_id')
                                ->toArray(),
                            'post_category_ids' => $allIds
                                ->filter(fn ($p) => $p->branch?->category_type === 'post')
                                ->pluck('category_id')
                                ->toArray(),
                        ];
                    })
                    ->form([
                        CheckboxList::make('product_category_ids')
                            ->label('Chi nhánh Phòng được phép')
                            ->helperText('User sẽ thấy các phòng thuộc chi nhánh này.')
                            ->options(function () {
                                $actor = auth()->user();
                                $query = Category::query()
                                    ->where('category_type', 'product')
                                    ->whereNull('parent_id')
                                    ->orderBy('name');

                                if (! $actor?->hasRole(config('filament-shield.super_admin.name'))) {
                                    $query->whereIn('id', $actor->allowedBranchIds());
                                }

                                return $query->pluck('name', 'id')->toArray();
                            })
                            ->columns(2),

                        CheckboxList::make('post_category_ids')
                            ->label('Danh mục Bài viết được phép')
                            ->helperText('User sẽ thấy và tạo bài viết thuộc danh mục này.')
                            ->options(function () {
                                $actor = auth()->user();
                                $query = Category::query()
                                    ->where('category_type', 'post')
                                    ->whereNull('parent_id')
                                    ->orderBy('name');

                                if (! $actor?->hasRole(config('filament-shield.super_admin.name'))) {
                                    $query->whereIn('id', $actor->allowedDirectPostRootIds());
                                }

                                return $query->pluck('name', 'id')->toArray();
                            })
                            ->columns(2),
                    ])
                    ->action(function ($record, array $data): void {
                        $userId = $record->user_id;
                        $createdBy = auth()->id();

                        $categoryIds = array_merge(
                            $data['product_category_ids'] ?? [],
                            $data['post_category_ids']    ?? [],
                        );

                        UserBranchPermission::where('user_id', $userId)->delete();

                        foreach ($categoryIds as $categoryId) {
                            UserBranchPermission::create([
                                'user_id'     => $userId,
                                'category_id' => $categoryId,
                                'created_by'  => $createdBy,
                            ]);
                        }
                    })
                    ->modalHeading(fn ($record) => 'Sửa quyền: ' . ($record->user?->fullname ?? $record->user?->email))
                    ->modalWidth('lg'),

                // Xóa toàn bộ quyền chi nhánh của user này
                DeleteAction::make()
                    ->label('Xóa tất cả')
                    ->requiresConfirmation()
                    ->action(function ($record): void {
                        UserBranchPermission::where('user_id', $record->user_id)->delete();
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }
}
