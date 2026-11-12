<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('brand_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->unique()->constrained()->restrictOnDelete();
            $table->text('default_audience')->nullable();
            $table->string('default_tone', 300)->nullable();
            $table->json('guidelines');
            $table->foreignUuid('updated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['client_id', 'updated_at']);
        });
        Schema::create('content_briefs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('topic', 200);
            $table->string('content_type', 30);
            $table->json('instructions');
            $table->json('brand_snapshot');
            $table->string('status', 30)->default('DRAFT');
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['project_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_briefs');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('brand_profiles');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['is_archived', 'version']));
    }
};
