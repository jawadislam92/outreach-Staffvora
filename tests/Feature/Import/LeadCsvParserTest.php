<?php

namespace Tests\Feature\Import;

use App\Services\Import\LeadCsvParser;
use Tests\TestCase;

class LeadCsvParserTest extends TestCase
{
    private function parser(): LeadCsvParser
    {
        return new LeadCsvParser;
    }

    public function test_google_sheets_export_with_bom_and_auto_mapping(): void
    {
        $csv = "\xEF\xBB\xBFEmail Address,First Name,Last Name,Company Name,Website,Notes\r\n"
            ."jane@acme.com,Jane,Doe,Acme,https://acme.com,\"Met at\r\nthe expo\"\r\n"
            ."\r\n"          // truly empty line: dropped by the CSV reader itself
            .",,,,,\r\n"     // row of empty cells: counted as skipped
            ."bob@beta.io,Bob,,Beta,,\r\n";

        $parsed = $this->parser()->parse($csv);

        $this->assertSame(',', $parsed->delimiter);
        $this->assertSame('UTF-8', $parsed->encoding);
        $this->assertSame(['email_address', 'first_name', 'last_name', 'company_name', 'website', 'notes'], array_column($parsed->headers, 'key'));
        $this->assertSame('Email Address', $parsed->headers[0]['label']);
        $this->assertSame(2, $parsed->count());
        $this->assertSame(1, $parsed->skippedBlank);
        $this->assertSame("Met at\nthe expo", $parsed->rows[0]['notes']);
        $this->assertSame('email_address', $parsed->mapping['email']);
        $this->assertSame('first_name', $parsed->mapping['first_name']);
        $this->assertSame('company_name', $parsed->mapping['company']);
        $this->assertNull($parsed->mapping['subject']);
    }

    public function test_excel_windows_1252_with_semicolons(): void
    {
        $csv = "E-mail;Vorname;Firma\n"."ren\xE9@caf\xE9.fr;Ren\xE9;Caf\xE9 Soci\xE9t\xE9\n";

        $parsed = $this->parser()->parse($csv);

        $this->assertSame(';', $parsed->delimiter);
        $this->assertSame('Windows-1252', $parsed->encoding);
        $this->assertSame('René', $parsed->rows[0]['vorname']);
        $this->assertSame('Café Société', $parsed->rows[0]['firma']);
        $this->assertSame('e_mail', $parsed->mapping['email']);
    }

    public function test_tabs_duplicate_and_empty_headers_and_subject_body_columns(): void
    {
        $csv = "email\tname\tname\t\tsubject\tbody\n"
            ."a@x.com\tAnn\tAnnie\tX\tHello Ann\t\"Hi Ann,\n\nLine two\"\n";

        $parsed = $this->parser()->parse($csv);

        $this->assertSame("\t", $parsed->delimiter);
        $this->assertSame(['email', 'name', 'name_2', 'col_4', 'subject', 'body'], array_column($parsed->headers, 'key'));
        $this->assertSame('name', $parsed->mapping['name']);
        $this->assertSame('subject', $parsed->mapping['subject']);
        $this->assertSame('body', $parsed->mapping['body']);
        $this->assertSame("Hi Ann,\n\nLine two", $parsed->rows[0]['body']);
    }

    public function test_errors_are_human_readable(): void
    {
        foreach (["", "   \n", "email,name\n"] as $bad) {
            try {
                $this->parser()->parse($bad);
                $this->fail('Expected an exception for '.json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->expectExceptionMessage('more than 2 rows');
        $this->parser()->parse("email\na@x.com\nb@x.com\nc@x.com\n", maxRows: 2);
    }
}
