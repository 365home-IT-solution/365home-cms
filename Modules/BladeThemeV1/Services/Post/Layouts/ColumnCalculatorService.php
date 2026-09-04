<?php

namespace Modules\BladeThemeV1\Services\Post\Layouts;

class ColumnCalculatorService
{
    protected bool $showSidebar;
    protected ?int $configColumns;

    public function __construct(bool $showSidebar, ?int $configColumns)
    {
        $this->showSidebar = $showSidebar;
        $this->configColumns = $configColumns;
    }

    public function calculateColumns(): array
    {
        $selectedColumns = $this->determineSelectedColumns();

        return [
            'sm' => 1,
            'md' => min($selectedColumns, 2),
            'lg' => $this->calculateLgColumns($selectedColumns)
        ];
    }

    public function getGridClasses(string $currentStyle = 'grid'): string
    {
        if ($currentStyle === 'list') {
            return 'grid grid-cols-1 gap-5';
        }

        $columns = $this->determineSelectedColumns();

        return match ($columns) {
            1 => "xs:grid-cols-1 sm:grid-cols-1 md:grid-cols-1 lg:grid-cols-1 xl:grid-cols-1",
            2 => "xs:grid-cols-1 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-2",
            3 => "xs:grid-cols-1 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3",
            4 => "xs:grid-cols-1 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4",
            default => "xs:grid-cols-1 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-4",
        };
    }

    protected function determineSelectedColumns(): int
    {
        return $this->configColumns !== null
            ? (int) $this->configColumns
            : ($this->showSidebar ? 3 : 4);
    }

    protected function calculateLgColumns(int $selectedColumns): int
    {
        return $selectedColumns;
    }
}