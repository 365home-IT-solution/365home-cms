<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Forms;

use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Modules\Category\Entities\Category;

class UserBranchPermissionForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(1)
                    ->schema([
                        Section::make('Gán chi nhánh cho người dùng')
                            ->description('Người dùng chỉ thấy dữ liệu Booking thuộc các chi nhánh được gán. Super Admin luôn thấy tất cả.')
                            ->schema([
                                Select::make('user_id')
                                    ->label('Người dùng')
                                    ->placeholder('Chọn người dùng...')
                                    ->options(function () {
                                        $actor = auth()->user();
                                        $query = User::query()->orderBy('fullname');

                                        if (! $actor?->hasRole(config('filament-shield.super_admin.name'))) {
                                            // Đối tác gán quyền chi nhánh cho tài khoản thuộc
                                            // đối tác mình (chủ + nhân viên), không phải chỉ
                                            // các tài khoản do chính mình trực tiếp tạo.
                                            $query->where('partner_id', $actor->partner_id);
                                        }

                                        return $query->get(['id', 'fullname', 'email'])
                                            ->mapWithKeys(fn ($u) => [
                                                $u->id => ($u->fullname ?? $u->email) . ' — ' . $u->email,
                                            ])
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->required(),

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
                                            // Category không có global scope partner_id riêng —
                                            // mặc định thấy toàn bộ chi nhánh của đối tác mình;
                                            // allowedBranchIds() chỉ thu hẹp thêm nếu có.
                                            $query->where('partner_id', $actor->partner_id);

                                            $allowedIds = $actor->allowedBranchIds();
                                            if (! empty($allowedIds)) {
                                                $query->whereIn('id', $allowedIds);
                                            }
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
                                            $allowedIds = $actor->allowedDirectPostRootIds();
                                            $query->whereIn('id', $allowedIds);
                                        }

                                        return $query->pluck('name', 'id')->toArray();
                                    })
                                    ->columns(2),
                            ]),
                    ]),
            ]);
    }
}
