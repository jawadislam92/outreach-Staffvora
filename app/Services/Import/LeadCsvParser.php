<?php

namespace App\Services\Import;

use InvalidArgumentException;
use League\Csv\Info;
use League\Csv\Reader;

/**
 * Turns whatever a spreadsheet exported into rows keyed by normalised header.
 * Handles UTF-8 BOMs, Windows-1252 exports, `;`/tab delimiters, CRLF, quoted
 * multi-line cells, blank lines and duplicate/empty header names. Column
 * mapping is by NAME (with aliases), never by position.
 */
class LeadCsvParser
{
    public const FIELDS = ['email', 'first_name', 'last_name', 'name', 'company', 'subject', 'body'];

    private const ALIASES = [
        'email' => ['email', 'e-mail', 'e_mail', 'email_address', 'emailaddress', 'mail', 'work_email', 'contact_email'],
        'first_name' => ['first_name', 'firstname', 'first', 'given_name', 'givenname', 'forename'],
        'last_name' => ['last_name', 'lastname', 'last', 'surname', 'family_name', 'familyname'],
        'name' => ['name', 'full_name', 'fullname', 'contact', 'contact_name', 'person', 'lead_name'],
        'company' => ['company', 'company_name', 'organization', 'organisation', 'org', 'business', 'employer', 'account'],
        'subject' => ['subject', 'email_subject', 'subject_line', 'draft_subject'],
        'body' => ['body', 'email_body', 'message', 'draft', 'draft_body', 'personalized_email', 'personalised_email', 'body_md', 'email_text', 'email_copy'],
    ];

    /**
     * @throws InvalidArgumentException with a user-facing message
     */
    public function parse(string $content, ?int $maxRows = null): ParsedCsv
    {
        $maxRows ??= (int) config('outreach.import.max_rows', 5000);

        [$content, $encoding] = $this->toUtf8($content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        if (trim($content) === '') {
            throw new InvalidArgumentException('The file is empty.');
        }

        $delimiter = $this->detectDelimiter($content);

        $reader = Reader::createFromString($content);
        $reader->setDelimiter($delimiter);
        $reader->setHeaderOffset(0);

        $rawHeader = $reader->getHeader();
        if ($rawHeader === [] || count(array_filter($rawHeader, fn ($h) => trim((string) $h) !== '')) === 0) {
            throw new InvalidArgumentException('The first row must contain column names (for example: email, first_name, company).');
        }

        $headers = $this->normaliseHeaders($rawHeader);
        $keys = array_column($headers, 'key');

        $rows = [];
        $skippedBlank = 0;
        foreach ($reader->getRecords($keys) as $record) {
            $row = [];
            $blank = true;
            foreach ($keys as $key) {
                $value = trim((string) ($record[$key] ?? ''));
                $value = preg_replace('/[\x{00A0}\x{200B}\x{FEFF}]/u', ' ', $value) ?? $value;
                $value = trim($value);
                if ($value !== '') {
                    $blank = false;
                }
                $row[$key] = $value;
            }
            if ($blank) {
                $skippedBlank++;

                continue;
            }
            $rows[] = $row;
            if (count($rows) > $maxRows) {
                throw new InvalidArgumentException("That file has more than {$maxRows} rows. Split it into smaller lists.");
            }
        }

        if ($rows === []) {
            throw new InvalidArgumentException('No data rows were found under the header row.');
        }

        return new ParsedCsv(
            headers: $headers,
            rows: $rows,
            mapping: $this->autoMap($keys),
            delimiter: $delimiter,
            encoding: $encoding,
            skippedBlank: $skippedBlank,
        );
    }

    /** @return array{0: string, 1: string} content, encoding label */
    private function toUtf8(string $content): array
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return [mb_convert_encoding($content, 'UTF-8', 'UTF-16'), 'UTF-16'];
        }
        if (mb_check_encoding($content, 'UTF-8')) {
            return [$content, 'UTF-8'];
        }

        return [mb_convert_encoding($content, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    private function detectDelimiter(string $content): string
    {
        $sample = Reader::createFromString($content);
        $stats = Info::getDelimiterStats($sample, [',', ';', "\t", '|'], 10);
        arsort($stats);
        $best = array_key_first($stats);

        return ($best !== null && $stats[$best] > 0) ? (string) $best : ',';
    }

    /** @return list<array{key: string, label: string}> */
    private function normaliseHeaders(array $rawHeader): array
    {
        $headers = [];
        $seen = [];
        foreach ($rawHeader as $i => $label) {
            $label = trim((string) $label);
            $key = strtolower($label);
            $key = preg_replace('/[^\pL\pN]+/u', '_', $key) ?? $key;
            $key = trim($key, '_');
            if ($key === '') {
                $key = 'col_'.($i + 1);
            }
            if (isset($seen[$key])) {
                $seen[$key]++;
                $key .= '_'.$seen[$key];
            } else {
                $seen[$key] = 1;
            }
            $headers[] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
        }

        return $headers;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, ?string>
     */
    public function autoMap(array $keys): array
    {
        $mapping = array_fill_keys(self::FIELDS, null);
        $taken = [];

        foreach (self::FIELDS as $field) {
            foreach (self::ALIASES[$field] as $alias) {
                foreach ($keys as $key) {
                    if ($key === $alias && ! isset($taken[$key])) {
                        $mapping[$field] = $key;
                        $taken[$key] = true;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }
}
