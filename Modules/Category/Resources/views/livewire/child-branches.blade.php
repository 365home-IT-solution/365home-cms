<div class="space-y-4">
    {{-- Form thêm/sửa — dùng Filament Form Builder (upload ảnh, toggle... đã có sẵn UI chuẩn), chỉ
         hiện khi bấm "Thêm chi nhánh con" hoặc "Sửa" ở 1 dòng trong bảng bên dưới. --}}
    @if ($showForm)
        <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 dark:border-white/10 dark:bg-white/5">
            <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">
                {{ $editingId ? 'Sửa chi nhánh con' : 'Thêm chi nhánh con' }}
            </h3>

            <form wire:submit="save">
                {{ $this->form }}

                <div class="mt-4 flex items-center gap-x-3">
                    <x-filament::button type="submit" size="sm">
                        Lưu
                    </x-filament::button>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="cancelForm">
                        Hủy
                    </x-filament::button>
                </div>
            </form>
        </div>
    @else
        <x-filament::button type="button" size="sm" icon="heroicon-o-plus" wire:click="openCreateForm">
            Thêm chi nhánh con
        </x-filament::button>
    @endif

    {{-- Danh sách chi nhánh con hiện có --}}
    @if ($children->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">Chưa có chi nhánh con nào.</p>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs font-medium uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Ảnh</th>
                        <th class="px-3 py-2">Tên chi nhánh con</th>
                        <th class="px-3 py-2">Slug</th>
                        <th class="px-3 py-2 text-center">Số thứ tự</th>
                        <th class="px-3 py-2 text-center">Trạng thái</th>
                        <th class="px-3 py-2 text-right">Hành động</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($children as $child)
                        <tr wire:key="child-branch-{{ $child->id }}" class="text-gray-950 dark:text-white">
                            <td class="px-3 py-2">
                                @if ($child->image)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($child->image) }}"
                                         alt="{{ $child->name }}"
                                         class="h-10 w-10 rounded-lg object-cover" />
                                @else
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-100 text-gray-400 dark:bg-white/5">
                                        <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5" />
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-medium">{{ $child->name }}</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $child->slug }}</td>
                            <td class="px-3 py-2 text-center">{{ $child->sort_order }}</td>
                            <td class="px-3 py-2 text-center">
                                <x-filament::badge :color="$child->status ? 'success' : 'gray'">
                                    {{ $child->status ? 'Đang hoạt động' : 'Ngừng hoạt động' }}
                                </x-filament::badge>
                            </td>
                            <td class="px-3 py-2 text-right">
                                @if ($confirmingDeleteId === $child->id)
                                    <span class="inline-flex items-center gap-x-2">
                                        <span class="text-xs text-danger-600">Xóa vĩnh viễn?</span>
                                        <x-filament::button type="button" color="danger" size="xs" wire:click="deleteConfirmed">
                                            Xóa
                                        </x-filament::button>
                                        <x-filament::button type="button" color="gray" size="xs" wire:click="cancelDelete">
                                            Hủy
                                        </x-filament::button>
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-x-1">
                                        <x-filament::icon-button
                                            icon="heroicon-o-pencil-square"
                                            label="Sửa"
                                            wire:click="openEditForm({{ $child->id }})"
                                        />
                                        <x-filament::icon-button
                                            icon="heroicon-o-trash"
                                            label="Xóa"
                                            color="danger"
                                            wire:click="confirmDelete({{ $child->id }})"
                                        />
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
