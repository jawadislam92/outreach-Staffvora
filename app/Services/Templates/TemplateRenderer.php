<?php

namespace App\Services\Templates;

/**
 * `{{first_name}}` and `{{company|there}}` substitution. Deliberately not
 * Blade: template text and lead data are user content, never code. Unknown
 * variables without a fallback are left visible so a reviewer notices.
 */
class TemplateRenderer
{
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_.\-]+)\s*(?:\|\s*([^}]*?)\s*)?\}\}/u';

    /** @param  array<string, mixed>  $vars */
    public static function render(string $text, array $vars): string
    {
        $normalized = [];
        foreach ($vars as $key => $value) {
            $normalized[strtolower((string) $key)] = is_scalar($value) ? trim((string) $value) : '';
        }

        return preg_replace_callback(self::PATTERN, function (array $m) use ($normalized) {
            $key = strtolower($m[1]);
            $fallback = $m[2] ?? null;
            $value = $normalized[$key] ?? '';

            if ($value !== '') {
                return $value;
            }

            return $fallback !== null ? $fallback : $m[0];
        }, $text) ?? $text;
    }

    /** @return list<string> variable names referenced in the text */
    public static function variables(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }

    /** Variables still unresolved after rendering (no value, no fallback). */
    public static function unresolved(string $rendered): array
    {
        return self::variables($rendered);
    }
}
