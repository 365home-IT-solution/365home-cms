<?php

namespace Modules\BladeThemeV1\Traits;

trait HandleCalculateTrait {
    public function calculateColumnsTrait($config, $default = 4)
    {
        $totalColumns = $this->getColumnValue($config, $default);

        return [
            'lg' => $totalColumns,
            'md' => $totalColumns >= 3 ? floor($totalColumns / 2) : 2,
            'sm' => 1
        ];
    }

    private function getColumnValue($config, $default)
    {
        if (!isset($config['column'])) {
            return $default;
        }

        $column = $config['column'];

        if (is_numeric($column)) {
            return (int)$column;
        }

        if (is_string($column) && preg_match('/^\d+$/', $column)) {
            return (int)$column;
        }

        if (is_string($column)) {
            $decoded = json_decode($column, true);
            if (json_last_error() === JSON_ERROR_NONE && is_numeric($decoded)) {
                return (int)$decoded;
            }
        }

        return $default;
    }
}