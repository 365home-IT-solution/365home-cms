@php
    $old = $record->old_values ?? [];
    $new = $record->new_values ?? [];
    $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
    sort($fields);
@endphp

<div class="space-y-3">
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{ $record->user_name ?? 'Hệ thống' }} — {{ $record->created_at->format('d/m/Y H:i:s') }}
    </div>

    @if (empty($fields))
        <p class="text-sm text-gray-500 dark:text-gray-400">Không có dữ liệu chi tiết.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-4 font-medium">Trường</th>
                        <th class="py-2 pr-4 font-medium">Giá trị cũ</th>
                        <th class="py-2 font-medium">Giá trị mới</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fields as $field)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium text-gray-950 dark:text-white">{{ \Modules\Minihouse\App\Support\ActivityLogFormatter::fieldLabel($field) }}</td>
                            <td class="py-2 pr-4 text-danger-600 dark:text-danger-400">{{ \Modules\Minihouse\App\Support\ActivityLogFormatter::formatValue($record->subject_type, $field, $old[$field] ?? null) }}</td>
                            <td class="py-2 text-success-600 dark:text-success-400">{{ \Modules\Minihouse\App\Support\ActivityLogFormatter::formatValue($record->subject_type, $field, $new[$field] ?? null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
