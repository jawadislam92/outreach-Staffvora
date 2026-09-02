<?php

namespace Tests\Feature\Sending;

use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Sending\MimeBuilder;
use App\Services\Sending\SendRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MimeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function build(array $o = []): array
    {
        $account = EmailAccount::factory()->create(['reply_to' => 'replies@example.com']);
        $request = new SendRequest(
            user: User::factory()->admin()->create(),
            account: $account,
            to: 'to@example.com',
            toName: 'To Person',
            subject: 'Subject {{first_name|there}}',
            bodyMd: "Line one\nLine two\n\n[Site](https://example.com)",
            clientToken: (string) Str::uuid(),
            cc: $o['cc'] ?? [],
            bcc: $o['bcc'] ?? [],
            variables: [],
        );

        return app(MimeBuilder::class)->build($request, $account);
    }

    public function test_gmail_raw_keeps_bcc_while_smtp_serialisation_strips_it(): void
    {
        $built = $this->build(['bcc' => ['hidden@example.com'], 'cc' => ['cc@example.com']]);
        $email = $built['email'];

        $raw = MimeBuilder::toGmailRaw($email);
        $this->assertStringContainsString('Bcc: hidden@example.com', $raw);
        $this->assertStringContainsString('Cc: cc@example.com', $raw);
        $this->assertStringContainsString('Reply-To: replies@example.com', $raw);
        $this->assertStringContainsString('Subject: Subject there', $raw);

        $smtp = $email->toString();
        $this->assertStringNotContainsString('Bcc:', $smtp);
        $this->assertStringContainsString('Cc: cc@example.com', $smtp);
    }

    public function test_base64url_uses_the_url_safe_alphabet_without_padding(): void
    {
        $encoded = MimeBuilder::base64Url(random_bytes(200)."\xff\xfe\xfd");

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
    }

    public function test_message_id_is_set_before_sending_and_soft_breaks_become_line_breaks(): void
    {
        $built = $this->build();
        $email = $built['email'];

        $this->assertNotEmpty($email->getHeaders()->get('Message-ID')?->getBodyAsString());
        $this->assertStringContainsString("Line one<br />\nLine two", $built['body_html']);
        $this->assertStringContainsString('Site (https://example.com)', $built['body_text']);
        $this->assertStringContainsString('<a href="https://example.com">Site</a>', $built['body_html']);
    }
}
