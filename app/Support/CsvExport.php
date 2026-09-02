<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV download. Every export writes an audit row before any byte
 * leaves, so a data pull can never happen without a trail.
 */
class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<scalar|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows, ?User $actor, string $action, array $detail = []): StreamedResponse
    {
        AuditLog::record($action, $actor, 'export', $filename, null, $detail);

        return response()->streamDownload(function () use ($headers, $rows) {
            $writer = Writer::createFromStream(fopen('php://output', 'w'));
            // UTF-8 BOM so Excel opens non-ASCII names correctly.
            echo "\xEF\xBB\xBF";
            $writer->insertOne($headers);
            $writer->insertAll($rows);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
