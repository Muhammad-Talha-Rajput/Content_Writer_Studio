<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $identifiers = DB::table('users')->pluck('id')->mapWithKeys(
            fn ($id) => [(string) $id => Str::isUuid((string) $id) ? (string) $id : (string) Str::uuid()],
        );
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('id')->change();
            $table->enum('role', ['ADMIN', 'WRITER', 'REVIEWER'])->default('WRITER');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('auth_version')->default(0);
            $table->string('auth_subject')->nullable()->unique();
        });
        Schema::table('sessions', fn (Blueprint $table) => $table->uuid('user_id')->nullable()->change());
        foreach ($identifiers as $previous => $uuid) {
            DB::table('users')->where('id', $previous)->update(['id' => $uuid, 'auth_subject' => 'local:'.$uuid]);
        }
        // Existing authentication payloads contain the old identifier and must be invalidated.
        DB::table('sessions')->delete();

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('industry', 200)->nullable();
            $table->string('website', 2048)->nullable();
            $table->timestamps();
        });
        Schema::create('client_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'user_id']);
        });
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->enum('role', ['ADMIN', 'WRITER', 'REVIEWER']);
            $table->foreignUuid('invited_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->json('client_ids');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 100);
            $table->string('target_type', 50);
            $table->uuid('target_id')->nullable();
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->index(['target_type', 'target_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('UUID identities cannot be rolled back while accounts exist. Restore a reviewed backup instead.');
        }
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('client_access');
        Schema::dropIfExists('clients');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['auth_subject']);
            $table->dropColumn(['role', 'is_active', 'auth_subject', 'auth_version']);
            $table->unsignedBigInteger('id')->autoIncrement()->change();
        });
        Schema::table('sessions', fn (Blueprint $table) => $table->unsignedBigInteger('user_id')->nullable()->change());
    }
};
