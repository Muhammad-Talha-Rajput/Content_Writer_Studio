<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandProfileController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContentBriefController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\GenerationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// These first-party JSON routes always use the web session and CSRF middleware.
Route::prefix('api')->group(function () {
    Route::get('/auth/session', [AuthController::class, 'session']);
    Route::post('/auth/bootstrap', [AuthController::class, 'bootstrap'])->middleware('throttle:account-setup');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-recovery');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-recovery');
    Route::post('/invitations/inspect', [InvitationController::class, 'inspect'])->middleware('throttle:account-setup');
    Route::post('/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:account-setup');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', fn (Request $request) => response()->json(['user' => $request->user()]));
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware('throttle:password-recovery');
        Route::get('/clients', [ClientController::class, 'index']);
        Route::get('/clients/{client}', [ClientController::class, 'show'])->whereUuid('client');
        Route::patch('/clients/{client}', [ClientController::class, 'update'])->whereUuid('client');
        Route::get('/clients/{client}/reviewers', [ClientController::class, 'reviewers'])->whereUuid('client');
        Route::put('/clients/{client}/brand-profile', [BrandProfileController::class, 'update'])->whereUuid('client');
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/clients/{client}/projects', [ProjectController::class, 'store'])->whereUuid('client');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereUuid('project');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->whereUuid('project');
        Route::get('/content-options', [ContentBriefController::class, 'options']);
        Route::get('/briefs', [ContentBriefController::class, 'index']);
        Route::post('/projects/{project}/briefs', [ContentBriefController::class, 'store'])->whereUuid('project');
        Route::get('/briefs/{brief}', [ContentBriefController::class, 'show'])->whereUuid('brief');
        Route::put('/briefs/{brief}', [ContentBriefController::class, 'update'])->whereUuid('brief');
        Route::patch('/briefs/{brief}/archive', [ContentBriefController::class, 'archive'])->whereUuid('brief');
        Route::get('/briefs/{brief}/sources', [SourceController::class, 'index'])->whereUuid('brief');
        Route::post('/briefs/{brief}/sources', [SourceController::class, 'store'])->whereUuid('brief')->middleware('throttle:30,1');
        Route::get('/sources/{source}', [SourceController::class, 'show'])->whereUuid('source');
        Route::get('/sources/{source}/download', [SourceController::class, 'download'])->whereUuid('source');
        Route::get('/sources/{source}/preview', [SourceController::class, 'preview'])->whereUuid('source');
        Route::get('/sources/{source}/pages/{page}', [SourceController::class, 'previewPage'])->whereUuid('source')->whereNumber('page')->middleware('throttle:60,1');
        Route::get('/briefs/{brief}/generation', [GenerationController::class, 'index'])->whereUuid('brief');
        Route::post('/briefs/{brief}/runs', [GenerationController::class, 'store'])->whereUuid('brief')->middleware('throttle:10,1');
        Route::get('/runs/{run}', [GenerationController::class, 'show'])->whereUuid('run');
        Route::post('/runs/{run}/cancel', [GenerationController::class, 'cancel'])->whereUuid('run');
        Route::post('/runs/{run}/continue', [GenerationController::class, 'continueRun'])->whereUuid('run')->middleware('throttle:10,1');
        Route::post('/runs/{run}/revise', [GenerationController::class, 'reviseRun'])->whereUuid('run')->middleware('throttle:10,1');
        Route::get('/versions/{version}', [GenerationController::class, 'version'])->whereUuid('version');
        Route::patch('/sources/{source}/archive', [SourceController::class, 'archive'])->whereUuid('source');
        Route::prefix('admin')->middleware('admin')->group(function () {
            Route::get('/ai-settings', [AiSettingsController::class, 'index']);
            Route::put('/ai-settings/{provider}', [AiSettingsController::class, 'update'])->whereIn('provider',['openrouter','gemini','ollama','huggingface']);
            Route::get('/ai-settings/{provider}/models', [AiSettingsController::class, 'models'])->whereIn('provider',['openrouter','gemini','ollama','huggingface'])->middleware('throttle:10,1');
            Route::post('/ai-settings/{provider}/models', [AiSettingsController::class, 'models'])->whereIn('provider',['openrouter','gemini','ollama','huggingface'])->middleware('throttle:10,1');
            Route::get('/users', [AdministrationController::class, 'users']);
            Route::patch('/users/{user}', [AdministrationController::class, 'updateUser'])->whereUuid('user');
            Route::put('/users/{user}/clients', [AdministrationController::class, 'assignments'])->whereUuid('user');
            Route::post('/users/{user}/recovery-link', [AdministrationController::class, 'recoveryLink'])->whereUuid('user')->middleware('throttle:password-recovery');
            Route::post('/clients', [ClientController::class, 'store']);
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'revoke'])->whereUuid('invitation');
            Route::get('/audit-events', [AdministrationController::class, 'auditEvents']);
        });
    });
});

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'service' => 'Content Studio API',
        'health_url' => url('/api/health'),
        'frontend_url' => config('studio.frontend_url'),
    ]);
});
