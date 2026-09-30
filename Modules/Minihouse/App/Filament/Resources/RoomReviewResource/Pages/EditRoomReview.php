<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomReviewResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\RoomReviewResource;

class EditRoomReview extends EditRecord
{
    protected static string $resource = RoomReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Xóa'),
        ];
    }

    // Ghi nhận người/thời điểm phản hồi khi nội dung phản hồi thay đổi — khớp Api\Admin\RatingController::reply().
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $reply = filled($data['admin_reply'] ?? null) ? $data['admin_reply'] : null;

        if ($reply !== $this->record->admin_reply) {
            $data['admin_reply'] = $reply;
            $data['replied_by']  = $reply ? auth()->id() : null;
            $data['replied_at']  = $reply ? now() : null;
        }

        return $data;
    }
}
