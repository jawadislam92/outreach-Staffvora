<?php

namespace App\Services\Ai;

use App\Models\Campaign;
use App\Models\Lead;

interface DraftGenerator
{
    public function configured(): bool;

    /**
     * @throws AiTransientException when the provider is busy (retry later, no attempt consumed)
     * @throws AiRefusedException when the model declined to write this draft
     * @throws \Throwable for any other failure
     */
    public function generate(Campaign $campaign, Lead $lead): GeneratedDraft;
}
