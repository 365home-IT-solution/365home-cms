<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Get;

// Bộ lọc "Nhóm chức năng" ở trang Vai trò/Phân quyền (Home + MiniHouse): chọn 1 tiêu đề lớn của mega menu
// (Vận hành toà nhà, Kho vật tư, Tài chính…) thì chỉ còn các Resource thuộc nhóm đó. Nhóm lấy đúng từ
// config/mega-menu.php (khớp theo navigationLabel của Resource, cùng cách topbar gom cột) nên menu và bộ
// lọc luôn khớp nhau. Section chỉ bị ẨN BẰNG CSS — không dùng ->hidden() vì Filament bỏ state của component
// ẩn khi lưu, sẽ làm mất quyền đã tick ở nhóm đang không hiển thị.
class PermissionModuleFilter
{
    public const FIELD = 'module_filter';

    /** Giá trị của mục "Tất cả" — hiển thị toàn bộ nhóm. */
    public const ALL = '__all';

    private const OTHER = 'Khác';

    /** @return array<string, string[]> tiêu đề mega menu => danh sách navigationLabel, theo panel đang mở. */
    private static function map(): array
    {
        $panelGroups = (array) config('mega-menu.panels.' . filament()->getId(), []);
        $groups = $panelGroups ?: collect(config('mega-menu'))->except('panels')->all();

        $map = [];
        foreach ($groups as $subGroups) {
            foreach ((array) $subGroups as $title => $labels) {
                $map[$title] = array_values(array_unique([...($map[$title] ?? []), ...(array) $labels]));
            }
        }

        return $map;
    }

    /** Nhóm mega menu của 1 Resource (null-safe: không khớp thì vào "Khác"). */
    public static function groupOf(string $resourceClass): string
    {
        $label = method_exists($resourceClass, 'getNavigationLabel') ? $resourceClass::getNavigationLabel() : null;

        foreach (self::map() as $title => $labels) {
            if ($label !== null && in_array($label, $labels, true)) {
                return $title;
            }
        }

        return self::OTHER;
    }

    /** Thứ tự nhóm theo mega menu (Khác ở cuối) — dùng để xếp các section cùng nhóm gần nhau. */
    public static function rank(string $resourceClass): int
    {
        $titles = array_keys(self::map());
        $index = array_search(self::groupOf($resourceClass), $titles, true);

        return $index === false ? count($titles) : (int) $index;
    }

    /** @param  iterable<array{fqcn: class-string}>  $entities */
    public static function select(iterable $entities): Select
    {
        $counts = collect($entities)->countBy(fn (array $entity) => self::groupOf($entity['fqcn']));

        $groups = collect(array_keys(self::map()))->push(self::OTHER)
            ->filter(fn (string $title) => $counts->has($title))
            ->mapWithKeys(fn (string $title) => [$title => "{$title} ({$counts[$title]})"])
            ->all();

        return Select::make(self::FIELD)
            ->label('Lọc theo nhóm chức năng')
            ->options([self::ALL => 'Tất cả (' . $counts->sum() . ')', ...$groups])
            ->default(self::ALL)
            ->selectablePlaceholder(false)
            ->native(false)
            ->live()
            ->dehydrated(false)
            ->columnSpanFull();
    }

    /** Style ẩn ô lưới bọc section bị lọc — đặt 1 lần trong tab Resources, cạnh ô chọn nhóm. */
    public static function style(): \Filament\Forms\Components\View
    {
        return \Filament\Forms\Components\View::make('filament.forms.permission-filter-style');
    }

    /** @return \Closure(Get): array<string, string> thuộc tính ẩn section khi khác nhóm đang lọc. */
    public static function hideUnless(string $resourceClass): \Closure
    {
        $group = self::groupOf($resourceClass);

        return fn (Get $get): array => filled($get(self::FIELD)) && $get(self::FIELD) !== self::ALL && $get(self::FIELD) !== $group
            ? ['data-perm-hidden' => 'true', 'style' => 'display: none;']
            : [];
    }
}
