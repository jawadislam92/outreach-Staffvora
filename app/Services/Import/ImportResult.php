<?php

namespace App\Services\Import;

final class ImportResult
{
    /** @param  list<array{row: int, email: string, reason: string}>  $rejected */
    public function __construct(
        public int $created = 0,
        public int $withDrafts = 0,
        public int $previouslyContacted = 0,
        public int $duplicatesInFile = 0,
        public array $rejected = [],
        public int $extraAddressesIgnored = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'with_drafts' => $this->withDrafts,
            'previously_contacted' => $this->previouslyContacted,
            'duplicates_in_file' => $this->duplicatesInFile,
            'rejected' => count($this->rejected),
            'extra_addresses_ignored' => $this->extraAddressesIgnored,
        ];
    }
}
