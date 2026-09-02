<?php

namespace Tests\Feature;

use App\Models\EmailAccount;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_log_lists_filters_and_exports(): void
    {
        $member = User::factory()->create();
        $account = EmailAccount::factory()->create(['label' => 'Main']);
        SentEmail::factory()->create(['user_id' => $member->id, 'email_account_id' => $account->id, 'to_email' => 'ok@example.com', 'subject' => 'Went out']);
        SentEmail::factory()->failed()->create(['user_id' => $member->id, 'email_account_id' => $account->id, 'to_email' => 'bad@example.com']);

        $this->actingAs($member)->get('/log')->assertInertia(fn (Assert $page) => $page
            ->component('Log/Index')
            ->has('rows.data', 2)
            ->where('rows.data.0.account', 'Main')
        );

        $this->actingAs($member)->get('/log?status=failed')->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.to_email', 'bad@example.com')
        );

        $this->actingAs($member)->get('/log?q=went')->assertInertia(fn (Assert $page) => $page->has('rows.data', 1));

        $response = $this->actingAs($member)->get('/log/export?status=sent');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Message-ID', $csv);
        $this->assertStringContainsString('ok@example.com', $csv);
        $this->assertStringNotContainsString('bad@example.com', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'log.export', 'user_id' => $member->id]);
    }

    public function test_a_row_detail_is_available_as_json(): void
    {
        $member = User::factory()->create();
        $row = SentEmail::factory()->create(['user_id' => $member->id, 'subject' => 'Detail me']);

        $this->actingAs($member)->getJson("/log/{$row->id}")
            ->assertOk()
            ->assertJsonPath('subject', 'Detail me')
            ->assertJsonPath('body_html', '<p>Hello there</p>');
    }
}
