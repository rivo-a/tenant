<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Icon system
|--------------------------------------------------------------------------
| Lucide icons (MIT, lucide-static 0.468.0) stored as SVG files in
| assets/icons. They are inlined rather than referenced as <img> so they
| inherit `currentColor`, align with text and never need extra requests.
|
| Sizing is passed as Tailwind classes; stroke width stays 2 for the whole
| application so icons look like one family.
*/

if (!function_exists('icon')) {
    /**
     * @param string $name   file name without .svg
     * @param string $class  sizing/spacing classes
     * @param string $label  accessible label; when empty the icon is hidden from assistive tech
     */
    function icon(string $name, string $class = 'h-4 w-4 shrink-0', string $label = '', array $extra = []): string
    {
        static $cache = [];

        if (!preg_match('/^[a-z0-9-]+$/', $name)) {
            return '';
        }

        if (!isset($cache[$name])) {
            $path = dirname(__DIR__) . '/assets/icons/' . $name . '.svg';

            if (!is_file($path)) {
                $cache[$name] = '';
            } else {
                $svg = (string)file_get_contents($path);
                // drop the xml prolog and any fixed sizing so classes control it
                $svg = preg_replace('/<\?xml[^>]*\?>/', '', $svg) ?? $svg;
                $svg = preg_replace('/\s(width|height)="[^"]*"/', '', $svg) ?? $svg;
                $svg = preg_replace('/class="[^"]*"/', '', $svg) ?? $svg;
                $cache[$name] = trim($svg);
            }
        }

        $svg = $cache[$name];
        if ($svg === '') {
            return '';
        }

        $attributes = 'class="' . e($class) . '" ';

        if ($label !== '') {
            $attributes .= 'role="img" aria-label="' . e($label) . '" ';
        } else {
            $attributes .= 'aria-hidden="true" focusable="false" ';
        }

        foreach ($extra as $key => $value) {
            if (preg_match('/^[a-z-]+$/', (string)$key)) {
                $attributes .= $key . '="' . e((string)$value) . '" ';
            }
        }

        return preg_replace('/<svg\s/', '<svg ' . $attributes, $svg, 1) ?? $svg;
    }
}

if (!function_exists('icon_url')) {
    /** For places that genuinely need an image source rather than inline SVG. */
    function icon_url(string $name): string
    {
        return '/tenant-system/assets/icons/' . basename($name) . '.svg';
    }
}
