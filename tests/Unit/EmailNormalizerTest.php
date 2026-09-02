<?php

namespace Tests\Unit;

use App\Support\EmailNormalizer;
use PHPUnit\Framework\TestCase;

class EmailNormalizerTest extends TestCase
{
    public function test_parses_messy_spreadsheet_cells(): void
    {
        $this->assertSame('jane@acme.com', EmailNormalizer::normalize('  Jane@Acme.com '));
        $this->assertSame('jane@acme.com', EmailNormalizer::normalize('mailto:Jane@Acme.com'));
        $this->assertSame('jane@acme.com', EmailNormalizer::normalize("Jane\u{00A0}Doe <jane@acme.com>"));

        $parsed = EmailNormalizer::parse('"Jane Doe" <Jane@Acme.com>');
        $this->assertSame(['email' => 'jane@acme.com', 'name' => 'Jane Doe', 'extra' => 0], $parsed);

        $multi = EmailNormalizer::parse('a@x.com; b@y.com, c@z.com');
        $this->assertSame('a@x.com', $multi['email']);
        $this->assertSame(2, $multi['extra']);

        $this->assertNull(EmailNormalizer::parse('not an email'));
        $this->assertNull(EmailNormalizer::parse(''));
    }

    public function test_validation_is_rfc_only(): void
    {
        $this->assertTrue(EmailNormalizer::isValid('jane@acme.com'));
        $this->assertTrue(EmailNormalizer::isValid('first.last+tag@sub.example.co.uk'));
        $this->assertFalse(EmailNormalizer::isValid('jane@'));
        $this->assertFalse(EmailNormalizer::isValid('jane acme.com'));
        $this->assertFalse(EmailNormalizer::isValid(''));
    }
}
