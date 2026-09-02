<?php

namespace Tests\Unit;

use App\Services\Templates\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererTest extends TestCase
{
    public function test_variables_fallbacks_and_unknowns(): void
    {
        $text = 'Hi {{first_name|there}}, {{ company }} — {{unknown_thing}} {{Company|x}}';

        $rendered = TemplateRenderer::render($text, ['first_name' => '', 'company' => 'Acme']);

        $this->assertSame('Hi there, Acme — {{unknown_thing}} Acme', $rendered);
        $this->assertSame(['unknown_thing'], TemplateRenderer::unresolved($rendered));
        $this->assertSame(['first_name', 'company', 'unknown_thing'], TemplateRenderer::variables($text));
    }

    public function test_values_are_not_interpreted_as_templates_or_code(): void
    {
        $rendered = TemplateRenderer::render('Hello {{name}}', ['name' => '{{company}} <?php echo 1; ?>']);

        $this->assertSame('Hello {{company}} <?php echo 1; ?>', $rendered);
    }
}
