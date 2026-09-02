<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\GoogleConnectController;
use App\Http\Controllers\Admin\LibraryController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SuppressionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CampaignGenerateController;
use App\Http\Controllers\CampaignImportController;
use App\Http\Controllers\ComposeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadListController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MarkdownPreviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecipientCheckController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Compose & shared helpers
    Route::get('/compose', [ComposeController::class, 'create'])->name('compose.create');
    Route::post('/compose', [ComposeController::class, 'store'])->name('compose.send');
    Route::post('/markdown/preview', MarkdownPreviewController::class)->name('markdown.preview');
    Route::get('/recipients/check', RecipientCheckController::class)->name('recipients.check');
    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');

    // Send log
    Route::get('/log', [LogController::class, 'index'])->name('log.index');
    Route::get('/log/export', [LogController::class, 'export'])->name('log.export');
    Route::get('/log/{sentEmail}', [LogController::class, 'show'])->name('log.show');

    // Reports (Phase 6)
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportsController::class, 'export'])->name('reports.export');

    // Templates: everyone can view/use, admins manage (Phase 3)
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::middleware('admin')->group(function () {
        Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
        Route::patch('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    });

    // Campaigns, CSV import, drafts queue, AI generation (Phases 4–5)
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
    Route::get('/campaigns/{campaign}/export', [CampaignController::class, 'export'])->name('campaigns.export');
    Route::get('/campaigns/{campaign}/queue', [LeadController::class, 'queue'])->name('campaigns.queue');

    Route::get('/campaigns/{campaign}/import', [CampaignImportController::class, 'create'])->name('campaigns.import.create');
    Route::post('/campaigns/{campaign}/import/preview', [CampaignImportController::class, 'preview'])->name('campaigns.import.preview');
    Route::post('/campaigns/{campaign}/import', [CampaignImportController::class, 'store'])->name('campaigns.import.store');
    Route::get('/imports/{token}/rejects.csv', [CampaignImportController::class, 'rejects'])->name('campaigns.import.rejects');

    Route::post('/campaigns/{campaign}/generate', [CampaignGenerateController::class, 'generate'])->name('campaigns.generate');
    Route::get('/campaigns/{campaign}/progress', [CampaignGenerateController::class, 'progress'])->name('campaigns.progress');

    // Shared lead list (static lead routes MUST come before /leads/{lead})
    Route::get('/leads', [LeadListController::class, 'index'])->name('leads.index');
    Route::post('/leads', [LeadListController::class, 'store'])->name('leads.store');
    Route::post('/leads/assign', [LeadListController::class, 'assign'])->name('leads.assign');
    Route::get('/leads/export', [LeadListController::class, 'export'])->name('leads.export');
    Route::get('/leads/import', [CampaignImportController::class, 'create'])->name('leads.import.create');
    Route::post('/leads/import/preview', [CampaignImportController::class, 'preview'])->name('leads.import.preview');
    Route::post('/leads/import/store', [CampaignImportController::class, 'store'])->name('leads.import.store');

    // Scheduled sending
    Route::post('/schedule', [ScheduleController::class, 'store'])->name('schedule.store');
    Route::post('/scheduled/{scheduledEmail}/cancel', [ScheduleController::class, 'cancel'])->name('schedule.cancel');
    Route::post('/scheduled/{scheduledEmail}/send-now', [ScheduleController::class, 'sendNow'])->name('schedule.send-now');

    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::patch('/leads/{lead}/draft', [LeadController::class, 'saveDraft'])->name('leads.draft');
    Route::post('/leads/{lead}/send', [LeadController::class, 'send'])->name('leads.send');
    Route::post('/leads/{lead}/skip', [LeadController::class, 'skip'])->name('leads.skip');
    Route::post('/leads/{lead}/regenerate', [LeadController::class, 'regenerate'])->name('leads.regenerate');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');

    Route::middleware('admin')->group(function () {
        // Must match GOOGLE_REDIRECT_URI exactly, so it lives outside /admin.
        Route::get('/oauth/google/callback', [GoogleConnectController::class, 'callback'])->name('oauth.google.callback');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
            Route::get('accounts/gmail/connect', [GoogleConnectController::class, 'connect'])->name('accounts.gmail.connect');
            Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
            Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
            Route::patch('accounts/{account}/status', [AccountController::class, 'status'])->name('accounts.status');
            Route::put('accounts/{account}/members', [AccountController::class, 'members'])->name('accounts.members');
            Route::post('accounts/{account}/test', [AccountController::class, 'test'])->name('accounts.test');
            Route::post('accounts/{account}/send-test', [AccountController::class, 'sendTest'])->name('accounts.send-test');

            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

            // Shared attachment library (Phase 3)
            Route::get('library', [LibraryController::class, 'index'])->name('library.index');
            Route::post('library', [LibraryController::class, 'store'])->name('library.store');
            Route::patch('library/{attachment}', [LibraryController::class, 'update'])->name('library.update');
            Route::delete('library/{attachment}', [LibraryController::class, 'destroy'])->name('library.destroy');

            // Do-not-contact list (Phase 3)
            Route::get('suppressions', [SuppressionController::class, 'index'])->name('suppressions.index');
            Route::post('suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
            Route::delete('suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');

            Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

            Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        });
    });
});

require __DIR__.'/auth.php';
