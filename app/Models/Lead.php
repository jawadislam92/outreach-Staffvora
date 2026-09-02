<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id', 'email', 'email_normalized', 'first_name', 'last_name', 'company',
        'attributes', 'import_row', 'status', 'generation_attempts', 'generation_error',
        'locked_at', 'previously_contacted_count',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'status' => LeadStatus::class,
            'generation_attempts' => 'integer',
            'locked_at' => 'datetime',
            'previously_contacted_count' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function draft(): HasOne
    {
        return $this->hasOne(Draft::class);
    }

    public function fullName(): ?string
    {
        $name = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * Variables available to templates and to the AI prompt: the canonical
     * fields first, then every extra CSV column.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $vars = [
            'email' => $this->email,
            'first_name' => (string) $this->first_name,
            'last_name' => (string) $this->last_name,
            'name' => (string) ($this->fullName() ?? $this->first_name ?? ''),
            'company' => (string) $this->company,
        ];

        foreach ((array) $this->attributes_data() as $key => $value) {
            if (! array_key_exists($key, $vars)) {
                $vars[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $vars;
    }

    /**
     * The `attributes` column collides with Eloquent's internal $attributes
     * property name only in reading style; the cast handles storage, this
     * accessor just gives a clearly named way to read it.
     */
    public function attributes_data(): array
    {
        return (array) ($this->getAttribute('attributes') ?? []);
    }
}
