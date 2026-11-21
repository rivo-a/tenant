<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Room type colour keys
|--------------------------------------------------------------------------
| One wash per room type, used for the template fields and the matching
| Type chip / rent tab in the rooms table. Colour identifies the type only;
| status stays neutral.
|
| Selection is deterministic: known type names keep a fixed key, anything
| added later inherits one by id so its colour never changes between loads.
*/

if (!function_exists('room_type_color_palette')) {
    /**
     * @return list<array{key: string, wash: string, deep: string}>
     */
    function room_type_color_palette(): array
    {
        return [
            ['key' => 'blush', 'on' => 'dark', 'wash' => 'oklch(90% 0.0218 31.8)', 'deep' => 'oklch(52% 0.13 31.8)'],
            ['key' => 'thistle', 'on' => 'dark', 'wash' => 'oklch(79.8% 0.0579 311)', 'deep' => 'oklch(40% 0.14 311)'],
            ['key' => 'sky', 'on' => 'dark', 'wash' => 'oklch(82.9% 0.034 215.6)', 'deep' => 'oklch(40% 0.09 215.6)'],
            ['key' => 'cinnamon', 'on' => 'light', 'wash' => 'oklch(60.3% 0.1213 47.2)', 'deep' => 'oklch(23% 0.06 47.2)'],
            ['key' => 'sage', 'on' => 'dark', 'wash' => 'oklch(86% 0.045 150)', 'deep' => 'oklch(34% 0.07 150)'],
            ['key' => 'sand', 'on' => 'dark', 'wash' => 'oklch(88% 0.04 80)', 'deep' => 'oklch(38% 0.09 80)'],
            ['key' => 'periwinkle', 'on' => 'dark', 'wash' => 'oklch(84% 0.05 285)', 'deep' => 'oklch(38% 0.1 285)'],
        ];
    }
}

if (!function_exists('room_type_color')) {
    /**
     * @return array{key: string, wash: string, deep: string}
     */
    function room_type_color(int $roomTypeId, string $name = ''): array
    {
        $palette = room_type_color_palette();
        $count = count($palette);

        $namedKeys = [
            'shop' => 0,
            'double room' => 1,
            'studio' => 2,
            'cube' => 3,
        ];

        $normalised = strtolower(trim($name));
        if (isset($namedKeys[$normalised])) {
            return $palette[$namedKeys[$normalised] % $count];
        }

        if ($roomTypeId > 0) {
            return $palette[($roomTypeId - 1) % $count];
        }

        return $palette[0];
    }
}

if (!function_exists('room_type_color_css_vars')) {
    /**
     * Renders the palette as CSS custom properties so the same values can be
     * reused in inline styles without repeating literals.
     */
    function room_type_color_css_vars(): string
    {
        $rules = [];
        foreach (room_type_color_palette() as $entry) {
            $rules[] = '--type-' . $entry['key'] . '-wash: ' . $entry['wash'] . ';';
            $rules[] = '--type-' . $entry['key'] . '-deep: ' . $entry['deep'] . ';';
        }

        return ':root{' . implode(' ', $rules) . '}';
    }
}
