<?php

namespace Tests\Feature\Import;

use App\Enums\DraftSource;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\SentEmail;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Import\LeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImporterTest extends TestCase
{
    use RefreshDatabase;

    private function mapping(array $overrides = []): array
    {
        return array_merge(['email' => 'email', 'first_name' => 'first', 'last_name' => null, 'name' => 'name', 'company' => 'company', 'subject' => 'subject', 'body' => 'body'], $overrides);
    }

    public function test_rows_become_leads_with_attributes_drafts_flags_and_rejections(): void
    {
        $user = User::factory()->admin()->create();
        $campaign = Campaign::query()->create(['name' => 'Test', 'created_by' => $user->id]);
        Lead::query()->create(['campaign_id' => $campaign->id, 'email' => 'already@x.com', 'email_normalized' => 'already@x.com']);
        Suppression::query()->create(['email_normalized' => 'optout@x.com']);
        SentEmail::factory()->create(['to_email' => 'seen@x.com', 'to_email_normalized' => 'seen@x.com', 'user_id' => $user->id]);

        $rows = [
            ['email' => 'Jane Doe <Jane@Acme.com>', 'first' => '', 'name' => '', 'company' => 'Acme', 'website' => 'acme.com', 'subject' => '', 'body' => ''],
            ['email' => 'mailto:seen@x.com', 'first' => 'Seen', 'name' => '', 'company' => '', 'website' => '', 'subject' => 'Hi Seen', 'body' => 'Ready body'],
            ['email' => 'jane@acme.com', 'first' => 'Dup', 'name' => '', 'company' => '', 'website' => '', 'subject' => '', 'body' => ''],
            ['email' => 'not-an-email', 'first' => 'Bad', 'name' => '', 'company' => '', 'website' => '', 'subject' => '', 'body' => ''],
            ['email' => 'already@x.com', 'first' => 'Al', 'name' => '', 'company' => '', 'website' => '', 'subject' => '', 'body' => ''],
            ['email' => 'optout@x.com', 'first' => 'Opt', 'name' => '', 'company' => '', 'website' => '', 'subject' => '', 'body' => ''],
            ['email' => 'a@y.com; b@y.com', 'first' => '', 'name' => 'Alan Turing', 'company' => 'Bletchley', 'website' => '', 'subject' => 'Only subject', 'body' => ''],
        ];

        $result = app(LeadImporter::class)->import($campaign, $rows, $this->mapping(), $user);

        $this->assertSame(3, $result->created);
        $this->assertSame(1, $result->withDrafts);
        $this->assertSame(1, $result->previouslyContacted);
        $this->assertSame(1, $result->duplicatesInFile);
        $this->assertSame(1, $result->extraAddressesIgnored);
        $this->assertSame(['Invalid or missing email address', 'Already in this campaign', 'On the do-not-contact list'], array_column($result->rejected, 'reason'));
        $this->assertSame([5, 6, 7], array_column($result->rejected, 'row'));

        $jane = Lead::query()->where('email_normalized', 'jane@acme.com')->firstOrFail();
        $this->assertSame('Jane', $jane->first_name);
        $this->assertSame('Doe', $jane->last_name);
        $this->assertSame('Acme', $jane->company);
        $this->assertSame(['website' => 'acme.com'], $jane->attributes_data());
        $this->assertSame(LeadStatus::Pending, $jane->status);
        $this->assertSame(2, $jane->import_row);

        $seen = Lead::query()->where('email_normalized', 'seen@x.com')->firstOrFail();
        $this->assertSame(1, $seen->previously_contacted_count);
        $this->assertSame(LeadStatus::Ready, $seen->status);
        $this->assertSame(DraftSource::Csv, $seen->draft->source);
        $this->assertSame('Ready body', $seen->draft->body_md);

        $alan = Lead::query()->where('email_normalized', 'a@y.com')->firstOrFail();
        $this->assertSame('Alan', $alan->first_name);
        $this->assertSame('Turing', $alan->last_name);
        $this->assertNull($alan->draft); // subject without body is not a draft
        $this->assertSame(LeadStatus::Pending, $alan->status);
    }

    public function test_email_column_is_mandatory(): void
    {
        $user = User::factory()->admin()->create();
        $campaign = Campaign::query()->create(['name' => 'Test', 'created_by' => $user->id]);

        $this->expectException(\InvalidArgumentException::class);
        app(LeadImporter::class)->import($campaign, [['x' => 'y']], $this->mapping(['email' => null]), $user);
    }
}
