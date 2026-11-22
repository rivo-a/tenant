<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Server-rendered SVG charts
|--------------------------------------------------------------------------
| Charts are built from the same rows the table shows, in PHP, so they print
| correctly, need no JavaScript and cannot disagree with the numbers beside
| them. Only series that answer a business question are drawn.
*/

if (!function_exists('chart_money')) {
    function chart_money(float $value): string
    {
        if ($value >= 1000000) {
            return round($value / 1000000, 1) . 'M';
        }
        if ($value >= 1000) {
            return round($value / 1000) . 'k';
        }

        return (string)round($value);
    }
}

if (!function_exists('chart_grouped_bars')) {
    /**
     * Two-series grouped bars, e.g. expected against collected per month.
     *
     * @param list<array{label: string, values: list<float>}> $rows
     * @param list<string> $seriesLabels
     * @param list<string> $colors hex colours per series
     */
    function chart_grouped_bars(array $rows, array $seriesLabels, array $colors, string $caption, int $width = 720, int $height = 220): string
    {
        if (!$rows) {
            return '';
        }

        $seriesCount = count($seriesLabels);
        $padding = ['left' => 52, 'right' => 8, 'top' => 14, 'bottom' => 26];
        $plotWidth = $width - $padding['left'] - $padding['right'];
        $plotHeight = $height - $padding['top'] - $padding['bottom'];

        $max = 0.0;
        foreach ($rows as $row) {
            foreach ($row['values'] as $value) {
                $max = max($max, (float)$value);
            }
        }
        $max = $max > 0 ? $max : 1.0;

        $groupWidth = $plotWidth / max(1, count($rows));
        $barWidth = max(4.0, ($groupWidth * 0.68) / max(1, $seriesCount));

        $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="auto" role="img" aria-label="' . e($caption) . '" class="text-slate-400">';
        $svg .= '<title>' . e($caption) . '</title>';

        // baseline and top grid
        foreach ([0, 0.5, 1] as $fraction) {
            $y = $padding['top'] + $plotHeight - ($plotHeight * $fraction);
            $svg .= '<line x1="' . $padding['left'] . '" y1="' . $y . '" x2="' . ($width - $padding['right']) . '" y2="' . $y . '" stroke="currentColor" stroke-opacity="0.18" stroke-width="1"/>';
            $svg .= '<text x="' . ($padding['left'] - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="10" fill="currentColor">' . e(chart_money($max * $fraction)) . '</text>';
        }

        foreach ($rows as $index => $row) {
            $groupX = $padding['left'] + ($groupWidth * $index);
            $innerOffset = ($groupWidth - ($barWidth * $seriesCount)) / 2;

            foreach (array_values($row['values']) as $seriesIndex => $value) {
                $value = (float)$value;
                $barHeight = $max > 0 ? ($value / $max) * $plotHeight : 0;
                $x = $groupX + $innerOffset + ($barWidth * $seriesIndex);
                $y = $padding['top'] + $plotHeight - $barHeight;

                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barWidth - 2, 1) . '" height="' . round(max(0, $barHeight), 1) . '" rx="2" fill="' . e($colors[$seriesIndex] ?? '#0f172a') . '"><title>' . e($row['label'] . ': ' . number_format($value)) . '</title></rect>';
            }

            $svg .= '<text x="' . round($groupX + $groupWidth / 2, 1) . '" y="' . ($height - 8) . '" text-anchor="middle" font-size="10" fill="currentColor">' . e($row['label']) . '</text>';
        }

        $svg .= '</svg>';

        // legend
        $legend = '';
        foreach ($seriesLabels as $seriesIndex => $label) {
            $legend .= '<span class="inline-flex items-center gap-1.5 text-xs text-slate-600"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:' . e($colors[$seriesIndex] ?? '#0f172a') . '"></span>' . e($label) . '</span>';
        }

        return $svg . '<div class="mt-2 flex flex-wrap gap-4">' . $legend . '</div>';
    }
}

if (!function_exists('chart_line')) {
    /**
     * @param list<array{label: string, value: float}> $points
     */
    function chart_line(array $points, string $color, string $caption, int $width = 720, int $height = 200): string
    {
        if (count($points) < 2) {
            return '';
        }

        $padding = ['left' => 52, 'right' => 12, 'top' => 14, 'bottom' => 26];
        $plotWidth = $width - $padding['left'] - $padding['right'];
        $plotHeight = $height - $padding['top'] - $padding['bottom'];

        $max = 0.0;
        foreach ($points as $point) {
            $max = max($max, (float)$point['value']);
        }
        $max = $max > 0 ? $max : 1.0;

        $step = count($points) > 1 ? $plotWidth / (count($points) - 1) : 0;
        $coords = [];
        foreach ($points as $index => $point) {
            $x = $padding['left'] + ($step * $index);
            $y = $padding['top'] + $plotHeight - (((float)$point['value'] / $max) * $plotHeight);
            $coords[] = [round($x, 1), round($y, 1)];
        }

        $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="auto" role="img" aria-label="' . e($caption) . '" class="text-slate-400">';
        $svg .= '<title>' . e($caption) . '</title>';

        foreach ([0, 0.5, 1] as $fraction) {
            $y = $padding['top'] + $plotHeight - ($plotHeight * $fraction);
            $svg .= '<line x1="' . $padding['left'] . '" y1="' . $y . '" x2="' . ($width - $padding['right']) . '" y2="' . $y . '" stroke="currentColor" stroke-opacity="0.18" stroke-width="1"/>';
            $svg .= '<text x="' . ($padding['left'] - 8) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="10" fill="currentColor">' . e(chart_money($max * $fraction)) . '</text>';
        }

        $path = [];
        foreach ($coords as $coord) {
            $path[] = $coord[0] . ',' . $coord[1];
        }

        $svg .= '<polyline points="' . implode(' ', $path) . '" fill="none" stroke="' . e($color) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

        foreach ($coords as $index => $coord) {
            $value = (float)$points[$index]['value'];
            $svg .= '<circle cx="' . $coord[0] . '" cy="' . $coord[1] . '" r="3" fill="' . e($color) . '"><title>' . e($points[$index]['label'] . ': ' . number_format($value)) . '</title></circle>';
            $svg .= '<text x="' . $coord[0] . '" y="' . ($height - 8) . '" text-anchor="middle" font-size="10" fill="currentColor">' . e($points[$index]['label']) . '</text>';
        }

        return $svg . '</svg>';
    }
}

if (!function_exists('chart_stacked_bar')) {
    /**
     * Single horizontal bar split into parts, e.g. occupancy against vacancy.
     *
     * @param list<array{label: string, value: float, color: string}> $parts
     */
    function chart_stacked_bar(array $parts, string $caption): string
    {
        $total = 0.0;
        foreach ($parts as $part) {
            $total += (float)$part['value'];
        }

        $out = '<div role="img" aria-label="' . e($caption) . '" class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100">';
        if ($total <= 0) {
            $out .= '<span class="sr-only">No rooms recorded</span>';
        } else {
            foreach ($parts as $part) {
                $width = ((float)$part['value'] / $total) * 100;
                if ($width <= 0) {
                    continue;
                }
                $out .= '<span style="width:' . round($width, 2) . '%;background:' . e($part['color']) . '" title="' . e($part['label'] . ': ' . (int)$part['value']) . '"></span>';
            }
        }
        $out .= '</div>';

        $legend = '<div class="mt-2 flex flex-wrap gap-4">';
        foreach ($parts as $part) {
            $legend .= '<span class="inline-flex items-center gap-1.5 text-xs text-slate-600"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:' . e($part['color']) . '"></span>' . e($part['label']) . ' <span class="font-semibold tabular-nums text-slate-900">' . (int)$part['value'] . '</span></span>';
        }
        $legend .= '</div>';

        return $out . $legend;
    }
}
