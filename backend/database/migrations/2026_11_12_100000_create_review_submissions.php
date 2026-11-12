<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('review_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->foreignUuid('version_id')->constrained('content_versions')->restrictOnDelete();
            $table->foreignUuid('submitted_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('SUBMITTED');
            $table->text('decision_comment')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['brief_id', 'created_at']);
            $table->index(['reviewer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_submissions');
    }
};
