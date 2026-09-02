<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->text('brief_offer')->nullable();
            $table->text('brief_goal')->nullable();
            $table->string('brief_tone', 255)->nullable();
            $table->text('extra_instructions')->nullable();
            $table->string('base_subject', 255)->nullable();
            $table->text('base_body_md')->nullable();
            // draft | active | done | archived (App\Enums\CampaignStatus)
            $table->string('status', 20)->default('active')->index();
            // Hard ceiling on AI generations for this campaign (cost guard).
            $table->unsignedInteger('max_generations')->default(500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('email_normalized', 255);
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('company', 150)->nullable();
            // Every extra CSV column, keyed by normalised header.
            $table->json('attributes')->nullable();
            $table->unsignedInteger('import_row')->nullable();
            // pending | generating | ready | sent | skipped | failed (App\Enums\LeadStatus)
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedTinyInteger('generation_attempts')->default(0);
            $table->text('generation_error')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->unsignedInteger('previously_contacted_count')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'email_normalized']);
            $table->index('email_normalized');
        });

        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 255);
            $table->text('body_md');
            // ai | csv | manual (App\Enums\DraftSource)
            $table->string('source', 10);
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedSmallInteger('generation_count')->default(0);
            // draft | sent | skipped (App\Enums\DraftStatus)
            $table->string('status', 20)->default('draft')->index();
            // sent_emails.id (ULID). No FK: sent_emails is created after drafts
            // and the log row must outlive the draft anyway.
            $table->char('sent_email_id', 26)->nullable()->index();
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('campaigns');
    }
};
