<?php

namespace App\Services\Import;

/** Result of LeadCsvParser::parse — rows keyed by normalised header. */
final class ParsedCsv
{
    /**
     * @param  list<array{key: string, label: string}>  $headers
     * @param  list<array<string, string>>  $rows
     * @param  array<string, ?string>  $mapping  canonical field → header key (auto-detected)
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $mapping,
        public readonly string $delimiter,
        public readonly string $encoding,
        public readonly int $skippedBlank,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }

    /** @return list<array<string, string>> */
    public function sample(int $n = 20): array
    {
        return array_slice($this->rows, 0, $n);
    }

    public function toArray(): array
    {
        return [
            'headers' => $this->headers,
            'rows' => $this->rows,
            'mapping' => $this->mapping,
            'delimiter' => $this->delimiter,
            'encoding' => $this->encoding,
            'skipped_blank' => $this->skippedBlank,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            headers: $data['headers'],
            rows: $data['rows'],
            mapping: $data['mapping'],
            delimiter: $data['delimiter'],
            encoding: $data['encoding'],
            skippedBlank: (int) ($data['skipped_blank'] ?? 0),
        );
    }
}
