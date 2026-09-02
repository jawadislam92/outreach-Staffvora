<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Leads may now live outside any campaign (the shared lead list).
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->change();
        });

        Schema::create('scheduled_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('email_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('draft_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_email', 255);
            $table->string('to_name', 150)->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject', 500);
            $table->longText('body_md');
            $table->json('variables')->nullable();
            $table->json('attachment_ids')->nullable();
            $table->boolean('ack_duplicate')->default(false);
            // UTC instant to fire at (entered in the business timezone).
            $table->dateTime('scheduled_for');
            // Back-off marker: cap-blocked rows retry after this instant.
            $table->dateTime('next_attempt_at')->nullable();
            // scheduled | sent | failed | cancelled (App\Enums\ScheduleStatus)
            $table->string('status', 10)->default('scheduled');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->char('sent_email_id', 26)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_emails');
    }
};
