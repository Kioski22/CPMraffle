<?php

if (!function_exists('ticket_layout_defaults')) {
    function ticket_layout_defaults(): array
    {
        return [
            'background_image' => 'img/ticket.png',
            'page_width_mm' => 101.6,
            'page_height_mm' => 50.8,
            'qr_x_mm' => 80.1,
            'qr_y_mm' => 13.0,
            'qr_size_mm' => 15.0,
            'text_x_mm' => 76.0,
            'text_y_mm' => 40.0,
            'text_font_size_pt' => 8.0,
            'text_color_hex' => '#4b5563',
            'text_align' => 'left',
            'text_mode' => 'ticket_number',
        ];
    }
}

if (!function_exists('ticket_layout_storage_path')) {
    function ticket_layout_storage_path(): string
    {
        return __DIR__ . '/uploads/ticket_layout/layout.json';
    }
}

if (!function_exists('ticket_layout_upload_directory')) {
    function ticket_layout_upload_directory(): string
    {
        return __DIR__ . '/uploads/ticket_layout';
    }
}

if (!function_exists('ticket_layout_public_path')) {
    function ticket_layout_public_path(string $absolutePath): string
    {
        $normalizedRoot = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
        $normalizedPath = str_replace('\\', '/', realpath($absolutePath) ?: $absolutePath);
        if (strpos($normalizedPath, $normalizedRoot) === 0) {
            return ltrim(str_replace($normalizedRoot, '', $normalizedPath), '/');
        }

        return 'img/ticket.png';
    }
}

if (!function_exists('ticket_layout_load')) {
    function ticket_layout_load(): array
    {
        $defaults = ticket_layout_defaults();
        $path = ticket_layout_storage_path();
        if (!is_file($path)) {
            return $defaults;
        }

        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $layout = array_merge($defaults, $decoded);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $layout['text_color_hex'])) {
            $layout['text_color_hex'] = $defaults['text_color_hex'];
        }
        if (!in_array($layout['text_mode'], ['ticket_number', 'ticket_range', 'auto'], true)) {
            $layout['text_mode'] = $defaults['text_mode'];
        }
        if (!in_array($layout['text_align'], ['left', 'center', 'right'], true)) {
            $layout['text_align'] = $defaults['text_align'];
        }

        return $layout;
    }
}

if (!function_exists('ticket_raffle_mechanics_link')) {
    function ticket_raffle_mechanics_link(array $appConfig = []): string
    {
        $resolved = trim((string) ($appConfig['app']['raffle_mechanics_url'] ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }

        global $appConfig;
        if (is_array($appConfig ?? null)) {
            $resolved = trim((string) (($appConfig['app']['raffle_mechanics_url'] ?? '')));
            if ($resolved !== '') {
                return $resolved;
            }
        }

        $localConfigFile = __DIR__ . '/config.php';
        if (is_file($localConfigFile)) {
            $loaded = require $localConfigFile;
            if (is_array($loaded)) {
                $resolved = trim((string) (($loaded['app']['raffle_mechanics_url'] ?? '')));
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        return 'https://icto2.psmeinc.org.ph/CPMraffle/instruction.php';
    }
}

if (!function_exists('ticket_layout_save')) {
    function ticket_layout_save(array $input): array
    {
        $defaults = ticket_layout_defaults();
        $layout = array_merge($defaults, $input);

        $numericKeys = [
            'page_width_mm',
            'page_height_mm',
            'qr_x_mm',
            'qr_y_mm',
            'qr_size_mm',
            'text_x_mm',
            'text_y_mm',
            'text_font_size_pt',
        ];
        foreach ($numericKeys as $key) {
            $value = isset($layout[$key]) ? (float) $layout[$key] : (float) $defaults[$key];
            if ($value <= 0) {
                $value = (float) $defaults[$key];
            }
            $layout[$key] = round($value, 2);
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $layout['text_color_hex'])) {
            $layout['text_color_hex'] = $defaults['text_color_hex'];
        }
        if (!in_array($layout['text_mode'], ['ticket_number', 'ticket_range', 'auto'], true)) {
            $layout['text_mode'] = $defaults['text_mode'];
        }
        if (!in_array($layout['text_align'], ['left', 'center', 'right'], true)) {
            $layout['text_align'] = $defaults['text_align'];
        }

        if (empty($layout['background_image']) || !is_file(__DIR__ . '/' . ltrim((string) $layout['background_image'], '/'))) {
            $layout['background_image'] = $defaults['background_image'];
        }

        $directory = ticket_layout_upload_directory();
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(ticket_layout_storage_path(), json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $layout;
    }
}

if (!function_exists('ticket_layout_color_rgb')) {
    function ticket_layout_color_rgb(string $hex): array
    {
        $value = ltrim($hex, '#');
        return [
            hexdec(substr($value, 0, 2)),
            hexdec(substr($value, 2, 2)),
            hexdec(substr($value, 4, 2)),
        ];
    }
}

if (!function_exists('ticket_layout_display_text')) {
    function ticket_layout_display_text(array $layout, array $ticketNumbers): string
    {
        $ticketNumbers = array_values(array_filter(array_map('trim', $ticketNumbers), static function (string $ticketNumber): bool {
            return $ticketNumber !== '';
        }));
        if ($ticketNumbers === []) {
            return '';
        }

        $mode = (string) ($layout['text_mode'] ?? 'ticket_number');
        if ($mode === 'ticket_range') {
            if (function_exists('ticket_format_number_ranges')) {
                return ticket_format_number_ranges($ticketNumbers);
            }
            return implode(', ', $ticketNumbers);
        }
        if ($mode === 'auto') {
            if (count($ticketNumbers) === 1) {
                return $ticketNumbers[0];
            }
            if (function_exists('ticket_format_number_ranges')) {
                return ticket_format_number_ranges($ticketNumbers);
            }
            return implode(', ', $ticketNumbers);
        }

        return $ticketNumbers[0];
    }
}
