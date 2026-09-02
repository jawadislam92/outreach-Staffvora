<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(2, true)),
            'subject' => 'Quick question, {{first_name|there}}',
            'body_md' => "Hi {{first_name|there}},\n\nI noticed {{company|your team}} is growing.\n\nBest,\nJawad",
            'is_active' => true,
            'created_by' => null,
        ];
    }
}
