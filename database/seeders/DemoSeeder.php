<?php

namespace Database\Seeders;

use App\Enums\DraftSource;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\Draft;
use App\Models\Lead;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local-only demo data: a template and a campaign with a few leads in
 * different states, so every screen has something to show.
 * `php artisan db:seed --class=DemoSeeder`
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $template = Template::query()->firstOrCreate(['name' => 'Automation intro'], [
            'subject' => 'Quick question about {{company|your team}}',
            'body_md' => "Hi {{first_name|there}},\n\nI noticed {{company|your team}} is growing fast and thought of one thing we could take off your plate: the manual reporting that eats the first hour of every day.\n\nWe build small automations and dashboards for agencies — usually live within two weeks.\n\nWorth a 15-minute call next week?",
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $campaign = Campaign::query()->firstOrCreate(['name' => 'Demo — Shopify agencies'], [
            'template_id' => $template->id,
            'brief_offer' => 'Sparking Asia builds automation, dashboards and internal tools for small agencies. Typical result: 10+ hours a week of manual reporting removed. Clients include e-commerce and marketing agencies in the UK and UAE.',
            'brief_goal' => 'Book a 15-minute intro call next week',
            'brief_tone' => 'Friendly, direct, no hype',
            'extra_instructions' => 'Mention their website if provided. Never mention pricing.',
            'max_generations' => 200,
            'created_by' => $admin->id,
        ]);

        if ($campaign->leads()->exists()) {
            return;
        }

        $rows = [
            ['jane@acme-demo.test', 'Jane', 'Doe', 'Acme Robotics', ['website' => 'acme-demo.test', 'role' => 'Founder', 'notes' => 'Runs a 12-person Shopify agency in Manchester'], LeadStatus::Ready, ['Quick idea for Acme Robotics', "Hi Jane,\n\nSaw that Acme Robotics is hiring two more account managers — usually the sign that reporting is about to eat everyone's mornings.\n\nWe build the dashboards and automations that give that hour back; most agencies your size are live within two weeks.\n\nWorth a 15-minute call next week?"], DraftSource::Ai],
            ['omar@bluepeak-demo.test', 'Omar', 'Farouk', 'Blue Peak Media', ['website' => 'bluepeak-demo.test', 'role' => 'Operations lead'], LeadStatus::Ready, ['Blue Peak Media — one less spreadsheet', "Hi Omar,\n\nQuick one. Ops leads at agencies like Blue Peak Media tell us the weekly client report is the task nobody wants.\n\nWe automate exactly that — data in, branded dashboard out, no copy-paste.\n\nOpen to a 15-minute call next week?"], DraftSource::Csv],
            ['sara@northwind-demo.test', 'Sara', 'Lund', 'Northwind Studio', ['website' => 'northwind-demo.test'], LeadStatus::Pending, null, null],
            ['dev@pixelforge-demo.test', 'Dev', 'Patel', 'PixelForge', ['role' => 'Co-founder', 'notes' => 'Met at Expand North Star'], LeadStatus::Pending, null, null],
            ['lena@harbor-demo.test', 'Lena', 'Novak', 'Harbor & Co', [], LeadStatus::Failed, null, null],
        ];

        foreach ($rows as $i => [$email, $first, $last, $company, $attributes, $status, $draft, $source]) {
            $lead = Lead::query()->create([
                'campaign_id' => $campaign->id,
                'email' => $email,
                'email_normalized' => $email,
                'first_name' => $first,
                'last_name' => $last,
                'company' => $company,
                'attributes' => $attributes,
                'import_row' => $i + 2,
                'status' => $status,
                'generation_attempts' => $status === LeadStatus::Failed ? 3 : 0,
                'generation_error' => $status === LeadStatus::Failed ? 'Anthropic rejected the request (400): example error kept for the demo' : null,
            ]);

            if ($draft) {
                Draft::query()->create([
                    'lead_id' => $lead->id,
                    'campaign_id' => $campaign->id,
                    'subject' => $draft[0],
                    'body_md' => $draft[1],
                    'source' => $source,
                    'model' => $source === DraftSource::Ai ? 'claude-opus-5' : null,
                    'input_tokens' => $source === DraftSource::Ai ? 812 : 0,
                    'output_tokens' => $source === DraftSource::Ai ? 210 : 0,
                    'generation_count' => $source === DraftSource::Ai ? 1 : 0,
                    'edited_by' => $admin->id,
                ]);
            }
        }
    }
}
