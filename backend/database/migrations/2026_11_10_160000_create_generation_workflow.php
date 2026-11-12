<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('provider')->default('openrouter');
            $t->string('model')->default('');
            $t->text('api_key')->nullable();
            $t->boolean('enabled')->default(false);
            $t->string('endpoint')->default('http://127.0.0.1:11434');
            $t->boolean('strict_json')->default(false);
            $t->boolean('free_only')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->json('limits');
            $t->timestamps();
        });
        Schema::create('agent_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('brief_id')->constrained('content_briefs')->restrictOnDelete();
            $t->foreignUuid('active_brief_id')->nullable()->unique()->constrained('content_briefs')->restrictOnDelete();
            $t->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $t->uuid('idempotency_key');
            $t->string('status', 16)->default('QUEUED');
            $t->string('previous_brief_status', 24);
            $t->json('input_snapshot');
            $t->json('limits_snapshot');
            $t->json('provider_usage')->nullable();
            $t->text('provider_secret')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('cancel_requested_at')->nullable();
            $t->string('error_code')->nullable();
            $t->string('error_message', 500)->nullable();
            $t->timestamps();
            $t->unique(['requested_by', 'idempotency_key']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('agent_steps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('run_id')->constrained('agent_runs')->restrictOnDelete();
            $t->string('step_type', 16);
            $t->unsignedTinyInteger('pass_no')->default(0);
            $t->unsignedTinyInteger('attempt_no')->default(0);
            $t->string('status', 16)->default('PENDING');
            $t->json('artifacts')->nullable();
            $t->json('provider_usage')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->string('error_message', 500)->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'step_type', 'pass_no', 'attempt_no']);
        });
        Schema::create('content_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('brief_id')->constrained('content_briefs')->restrictOnDelete();
            $t->foreignUuid('source_run_id')->nullable()->unique()->constrained('agent_runs')->restrictOnDelete();
            $t->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version_no');
            $t->string('version_kind', 8)->default('AI');
            $t->string('title');
            $t->longText('body_markdown');
            $t->json('blocks');
            $t->json('format_metadata');
            $t->json('quality_findings');
            $t->timestamps();
            $t->unique(['brief_id', 'version_no']);
        });
        Schema::create('citations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('version_id')->constrained('content_versions')->restrictOnDelete();
            $t->foreignUuid('source_id')->constrained('sources')->restrictOnDelete();
            $t->string('claim_anchor', 60);
            $t->unsignedInteger('segment_ordinal');
            $t->string('locator');
            $t->text('supporting_excerpt');
            $t->unique(['version_id', 'claim_anchor', 'source_id', 'segment_ordinal'], 'citation_anchor_unique');
        });
        Schema::table('sources', function (Blueprint $t) {
            $t->foreignUuid('discovered_by_run_id')->nullable()->constrained('agent_runs')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::table('sources', fn (Blueprint $t) => $t->dropConstrainedForeignId('discovered_by_run_id'));
        Schema::dropIfExists('citations'); Schema::dropIfExists('content_versions');
        Schema::dropIfExists('agent_steps'); Schema::dropIfExists('agent_runs'); Schema::dropIfExists('ai_settings');
    }
};
