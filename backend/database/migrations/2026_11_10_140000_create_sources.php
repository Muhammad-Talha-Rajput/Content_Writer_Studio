<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source_type', 8);
            $table->string('title');
            $table->text('requested_url')->nullable();
            $table->text('final_url')->nullable();
            $table->string('storage_key')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('byte_size')->default(0);
            $table->string('parse_status', 12)->default('PENDING');
            $table->string('scan_status', 20)->default('PENDING');
            $table->string('error_message', 500)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('segments')->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['brief_id', 'parse_status', 'is_archived']);
        });
    }
    public function down(): void { Schema::dropIfExists('sources'); }
};
