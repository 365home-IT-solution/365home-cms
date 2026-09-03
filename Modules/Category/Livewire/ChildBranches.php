<?php

declare(strict_types=1);

namespace Modules\Category\Livewire;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Modules\Category\Entities\Category;

// Component Livewire ĐỘC LẬP (không phải field trong form chi nhánh cha như Repeater/TableRepeater
// trước đây) — tự lo toàn bộ CRUD (thêm/sửa/xóa) + upload ảnh cho chi nhánh con, tách hẳn khỏi vòng
// đời lưu của form chi nhánh cha. Nhúng qua Filament\Forms\Components\Livewire (component chính thức
// của Filament để nhúng 1 Livewire component độc lập vào giữa form khác — xem
// CategoryForm::childrenSection()). Mỗi thao tác ở đây ghi thẳng vào DB ngay lập tức, KHÔNG cần bấm
// nút "Lưu" của form chi nhánh cha. Dùng lại Filament Form Builder (InteractsWithForms) cho riêng
// phần thêm/sửa để có sẵn UI upload ảnh/toggle chuẩn — bảng danh sách bên ngoài là Blade tự vẽ.
class ChildBranches extends Component implements HasForms
{
    use InteractsWithForms;

    public ?int $categoryId = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?int $confirmingDeleteId = null;

    public function mount(?int $categoryId = null): void
    {
        $this->categoryId = $categoryId;
        $this->form->fill($this->defaultFormState());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('image')
                    ->label('Ảnh')
                    ->image()
                    ->imageEditor()
                    ->directory('categories')
                    ->imagePreviewHeight('100')
                    ->nullable(),
                TextInput::make('name')
                    ->label('Tên chi nhánh con')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        if (($get('slug') ?? '') !== Str::slug($old)) {
                            return;
                        }
                        $set('slug', Str::slug($state));
                    }),
                TextInput::make('slug')
                    ->label('Đường dẫn (slug)')
                    ->required()
                    ->maxLength(255)
                    ->rules([fn () => $this->editingId
                        ? Rule::unique('categories', 'slug')->ignore($this->editingId)
                        : Rule::unique('categories', 'slug')]),
                TextInput::make('sort_order')
                    ->label('Số thứ tự')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('status')
                    ->label('Đang hoạt động')
                    ->default(true),
            ])
            ->statePath('data');
    }

    private function defaultFormState(): array
    {
        return [
            'image'      => null,
            'name'       => null,
            'slug'       => null,
            'sort_order' => 0,
            'status'     => true,
        ];
    }

    public function openCreateForm(): void
    {
        $this->editingId = null;
        $this->form->fill($this->defaultFormState());
        $this->showForm = true;
    }

    public function openEditForm(int $id): void
    {
        $child = Category::findOrFail($id);

        $this->editingId = $child->id;
        $this->form->fill([
            'image'      => $child->image,
            'name'       => $child->name,
            'slug'       => $child->slug,
            'sort_order' => $child->sort_order ?? 0,
            'status'     => (bool) $child->status,
        ]);
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if ($this->editingId) {
            Category::whereKey($this->editingId)->update($data);
            $title = 'Đã cập nhật chi nhánh con.';
        } else {
            $parent = $this->categoryId ? Category::find($this->categoryId) : null;

            $data['parent_id']     = $this->categoryId;
            $data['category_type'] = 'product';
            $data['partner_id']    = $parent?->partner_id;

            Category::create($data);
            $title = 'Đã thêm chi nhánh con.';
        }

        $this->showForm = false;

        Notification::make()->success()->title($title)->send();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // Xóa VĨNH VIỄN — Category không dùng SoftDeletes. Ảnh cũ trên storage KHÔNG tự xóa theo (giữ
    // đơn giản, giống hành vi hiện có ở CategoryObserver/ChildrenRelationManager — không tự dọn file
    // để tránh rủi ro xóa nhầm ảnh đang được nơi khác tham chiếu).
    public function deleteConfirmed(): void
    {
        if ($this->confirmingDeleteId) {
            Category::whereKey($this->confirmingDeleteId)->delete();
        }

        $this->confirmingDeleteId = null;

        Notification::make()->success()->title('Đã xóa vĩnh viễn chi nhánh con.')->send();
    }

    public function render()
    {
        $children = $this->categoryId
            ? Category::where('parent_id', $this->categoryId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
            : collect();

        return view('category::livewire.child-branches', [
            'children' => $children,
        ]);
    }
}
