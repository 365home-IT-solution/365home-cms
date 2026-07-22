<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Forms\BranchDetailForm;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Category\Entities\Category;

// Trang THẬT (không phải modal) "Chi tiết cơ sở lưu trú" — chỉ truy cập được từ tab "Cơ sở lưu
// trú" của PartnerResource, không đăng ký trong menu điều hướng, không đụng vào Modules/Category
// (CategoryResource dùng chung cho mọi vai trò khác không bị ảnh hưởng).
class BranchDetail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = PartnerResource::class;

    protected static string $view = 'filament.resources.partner-resource.pages.branch-detail';

    public Category $branch;

    public ?array $data = [];

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    // QUAN TRỌNG: vì có thuộc tính public "Category $branch" cùng tên với route param
    // {branch}, Livewire TỰ ĐỘNG áp dụng route-model-binding — nghĩa là tham số $branch nhận
    // được ở đây đã LÀ 1 Category model (Livewire tự Category::find() trước khi gọi mount()),
    // không phải chuỗi ID thô như tưởng ban đầu. Đây chính là nguyên nhân bug "Not Found": code
    // cũ query Category::where('id', $branch) với $branch là cả object → không bao giờ khớp.
    // Sửa đúng: nhận thẳng model đã bind sẵn, chỉ tự kiểm tra lại nó thuộc đúng đối tác.
    public function mount(string $record, Category $branch): void
    {
        if ($branch->partner_id !== $record || $branch->category_type !== 'product') {
            throw (new ModelNotFoundException())->setModel(Category::class, [$branch->getKey()]);
        }

        $this->branch = $branch;

        $this->form->fill($this->branch->only([
            'status', 'lodging_type', 'timezone', 'operation_manager_name',
            'area_sqm', 'established_year', 'checkin_time', 'checkout_time', 'default_policy',
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(BranchDetailForm::schema($this->branch))
            ->statePath('data');
    }

    public function getTitle(): string|Htmlable
    {
        return "Chi tiết cơ sở: {$this->branch->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('← Quay lại đối tác')
                ->color('gray')
                ->url(fn () => PartnerResource::getUrl('edit', ['record' => $this->branch->partner_id])),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Lưu thay đổi')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $this->branch->update($this->form->getState());

        Notification::make()
            ->title('Đã lưu chi tiết cơ sở')
            ->success()
            ->send();
    }
}
