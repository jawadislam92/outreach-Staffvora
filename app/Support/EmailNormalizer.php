<?php

namespace App\Support;

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

/**
 * One definition of "the same address" for duplicate checks, suppressions
 * and per-campaign uniqueness: trimmed, lower-cased, display-name and
 * mailto: prefix stripped. No DNS lookups (shared hosting).
 */
class EmailNormalizer
{
    private static ?EmailValidator $validator = null;

    /**
     * Pull the first address out of free-form cell text such as
     * `Jane Doe <jane@acme.com>`, `mailto:jane@acme.com` or `a@x.com; b@y.com`.
     *
     * @return array{email: string, name: ?string, extra: int}|null  extra = additional addresses ignored
     */
    public static function parse(?string $raw): ?array
    {
        $raw = self::clean($raw);
        if ($raw === '') {
            return null;
        }

        $name = null;
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>\s*$/u', $raw, $m)) {
            $name = trim($m[1]) !== '' ? trim($m[1]) : null;
            $raw = trim($m[2]);
        }

        $parts = preg_split('/[;,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => str_contains($p, '@')));
        if ($parts === []) {
            return null;
        }

        $email = strtolower(trim($parts[0], " \t\n\r\0\x0B<>\"'"));
        if (str_starts_with($email, 'mailto:')) {
            $email = substr($email, 7);
        }

        return ['email' => $email, 'name' => $name, 'extra' => count($parts) - 1];
    }

    public static function normalize(?string $raw): string
    {
        return self::parse($raw)['email'] ?? '';
    }

    public static function isValid(string $email): bool
    {
        if ($email === '' || strlen($email) > 254) {
            return false;
        }

        self::$validator ??= new EmailValidator;

        return self::$validator->isValid($email, new RFCValidation);
    }

    private static function clean(?string $raw): string
    {
        $raw = (string) $raw;
        // NBSP and zero-width characters that survive copy/paste from spreadsheets.
        $raw = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $raw) ?? $raw;

        return trim($raw);
    }
}
