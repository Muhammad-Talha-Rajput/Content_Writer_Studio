<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityMigrationTest extends TestCase
{
    public function test_uuid_migration_preserves_legacy_accounts_and_invalidates_old_sessions(): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.legacy_identity_check' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('legacy_identity_check');
        Schema::clearResolvedInstance('db.schema');
        try {
            $initial = require database_path('migrations/0001_01_01_000000_create_users_table.php');
            $initial->up();
            DB::table('users')->insert(['id' => 7, 'name' => 'Existing Writer', 'email' => 'legacy@example.test',
                'password' => 'existing-hash-preserved', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sessions')->insert(['id' => 'legacy-session', 'user_id' => 7, 'payload' => '', 'last_activity' => time()]);
            $migration = require database_path('migrations/2026_11_10_100000_add_identity_and_client_access.php');
            $migration->up();
            $user = DB::table('users')->sole();
            $this->assertTrue(Str::isUuid($user->id));
            $this->assertSame('Existing Writer', $user->name);
            $this->assertSame('legacy@example.test', $user->email);
            $this->assertSame('existing-hash-preserved', $user->password);
            $this->assertSame('local:'.$user->id, $user->auth_subject);
            $this->assertSame('WRITER', $user->role);
            $this->assertSame(0, DB::table('sessions')->count());
        } finally {
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('legacy_identity_check');
        }
    }
}
