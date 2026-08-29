<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\DataPermission\App\Filament\Resources\UserBranchPermissionResource;
use Modules\DataPermission\Entities\UserBranchPermission;

class CreateUserBranchPermission extends CreateRecord
{
    protected static string $resource = UserBranchPermissionResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Tạo 1 record cho mỗi category được chọn (cả product lẫn post).
     * Dùng firstOrCreate để bỏ qua duplicate.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $userId = $data['user_id'];

        $categoryIds = array_merge(
            $data['product_category_ids'] ?? [],
            $data['post_category_ids']    ?? [],
        );

        $createdBy = auth()->id();

        // Xóa quyền cũ rồi gán lại — xoá TỪNG bản ghi (không dùng ::where()->delete() hàng loạt)
        // để UserBranchPermissionObserver::deleted() vẫn bắn đúng, ghi lại audit log (trước đây
        // xoá hàng loạt bằng query builder không bắn Eloquent event nên hoàn toàn không có log).
        UserBranchPermission::where('user_id', $userId)->get()->each->delete();

        foreach ($categoryIds as $categoryId) {
            UserBranchPermission::create([
                'user_id'     => $userId,
                'category_id' => $categoryId,
                'created_by'  => $createdBy,
            ]);
        }

        return UserBranchPermission::where('user_id', $userId)->firstOrFail();
    }
}
