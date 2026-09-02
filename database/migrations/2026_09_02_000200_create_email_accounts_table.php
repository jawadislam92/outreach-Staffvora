<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            // smtp | gmail_oauth (App\Enums\AccountProvider)
            $table->string('provider', 20)->index();
            $table->string('from_name', 120);
            $table->string('from_email', 255);
            $table->string('reply_to', 255)->nullable();
            $table->string('smtp_host', 255)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            // ssl (implicit TLS, 465) | starttls (587)
            $table->string('smtp_encryption', 10)->nullable();
            $table->string('smtp_username', 255)->nullable();
            // Encrypted at rest (encrypted:array cast): smtp_password, or the
            // Google refresh/access tokens + expiry + connected mailbox address.
            $table->text('secrets')->nullable();
            $table->unsignedInteger('daily_cap')->default(30);
            $table->text('signature_md')->nullable();
            // active | needs_reconnect | disabled (App\Enums\AccountStatus)
            $table->string('status', 20)->default('active')->index();
            $table->dateTime('last_test_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('account_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['email_account_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_user');
        Schema::dropIfExists('email_accounts');
    }
};
