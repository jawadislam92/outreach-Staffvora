<?php

namespace App\Console\Commands;

use App\Models\ScheduledEmail;
use App\Services\Sending\ScheduledSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDueScheduled extends Command
{
    protected $signature = 'outreach:send-due {--limit=20 : Max emails to send in one run}';

    protected $description = 'Send scheduled emails whose time has come (run every minute by the scheduler)';

    public function handle(ScheduledSender $sender): int
    {
        // Claim under a lock so overlapping runs never fire the same row twice.
        $ids = DB::transaction(function () {
            $ids = ScheduledEmail::query()
                ->due()
                ->orderBy('scheduled_for')
                ->limit((int) $this->option('limit'))
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                ScheduledEmail::query()->whereIn('id', $ids)->update(['next_attempt_at' => now()->addMinutes(5)]);
            }

            return $ids;
        });

        $sent = $failed = $deferred = 0;
        foreach ($ids as $id) {
            $row = ScheduledEmail::query()->find($id);
            if (! $row) {
                continue;
            }
            $result = $sender->fire($row);
            match ($result->status->value) {
                'sent' => $sent++,
                'failed' => $failed++,
                default => $deferred++,
            };
        }

        $this->info("Due: {$ids->count()} — sent {$sent}, failed {$failed}, deferred {$deferred}.");

        return self::SUCCESS;
    }
}
