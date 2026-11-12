<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'A useful test password 26';

    private function signIn(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web')->withSession(['auth_version' => $user->auth_version]);
    }

    public function test_first_run_creates_one_uuid_administrator_without_a_default_password(): void
    {
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('setup_available', true);
        $response = $this->postJson('/api/auth/bootstrap', ['name' => 'Studio Admin', 'email' => 'ADMIN@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $response->assertCreated()->assertJsonPath('user.role', 'ADMIN')->assertJsonMissingPath('user.password');
        $admin = User::firstOrFail();
        $this->assertTrue(Str::isUuid($admin->id));
        $this->assertSame('admin@example.test', $admin->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/auth/bootstrap', ['name' => 'Another', 'email' => 'other@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_first_run_is_not_available_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->getJson('/api/auth/session')->assertOk()->assertJsonPath('setup_available', false);
        $this->postJson('/api/auth/bootstrap', ['name' => 'Admin', 'email' => 'a@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus(419);
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf)
            ->postJson('/api/auth/bootstrap', ['name' => 'Admin', 'email' => 'a@example.test',
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertStatus(409);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_normalizes_email_and_logout_ends_the_session(): void
    {
        User::factory()->create(['email' => 'writer@example.test', 'password' => self::PASSWORD]);
        $this->postJson('/api/auth/login', ['email' => 'WRITER@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'WRITER@example.test', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('user.role', 'WRITER')->assertJsonMissingPath('user.auth_version');
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_guests_and_bearer_tokens_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/clients')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer 1|not-a-real-token')->getJson('/api/me')->assertUnauthorized();
    }

    public function test_writers_and_reviewers_cannot_administer_users_or_clients(): void
    {
        foreach (['WRITER', 'REVIEWER'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->signIn($user)->getJson('/api/admin/users')->assertForbidden();
            $this->postJson('/api/admin/clients', ['name' => 'Not allowed'])->assertForbidden();
            $this->postJson('/api/admin/invitations', ['email' => 'other@example.test', 'role' => 'ADMIN', 'client_ids' => []])->assertForbidden();
            $this->getJson('/api/admin/audit-events')->assertForbidden();
        }
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_client_access_is_scoped_and_revocation_takes_effect_immediately(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $writer = User::factory()->create();
        $allowed = Client::create(['name' => 'Assigned client']);
        $other = Client::create(['name' => 'Other client']);
        $this->signIn($admin)->putJson('/api/admin/users/'.$writer->id.'/clients', ['client_ids' => [$allowed->id]])->assertOk();
        $this->signIn($writer)->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $allowed->id);
        $this->getJson('/api/clients/'.$other->id)->assertForbidden()->assertDontSee('Other client');
        $this->getJson('/api/clients/'.$allowed->id)->assertOk();
        $this->putJson('/api/admin/users/'.$writer->id.'/clients', ['client_ids' => [$other->id]])->assertForbidden();
        $this->signIn($admin)->getJson('/api/clients')->assertJsonCount(2, 'clients');
        $this->putJson('/api/admin/users/'.$writer->id.'/clients', ['client_ids' => []])->assertOk();
        $this->signIn($writer)->getJson('/api/clients/'.$allowed->id)->assertForbidden();
    }

    public function test_account_changes_revoke_sessions_and_prevent_self_lockout(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $writer = User::factory()->create(['password' => self::PASSWORD]);
        DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $writer->id,
            'payload' => '', 'last_activity' => time()]);
        $this->signIn($admin)->patchJson('/api/admin/users/'.$admin->id, ['role' => 'WRITER'])->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$writer->id, ['is_active' => false])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
        $this->assertSame(1, $writer->fresh()->auth_version);
        $this->signIn($writer->fresh())->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $writer->email, 'password' => self::PASSWORD])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_old_session_versions_remain_invalid_after_reactivation(): void
    {
        $user = User::factory()->create(['auth_version' => 2]);
        $this->actingAs($user, 'web')->withSession(['auth_version' => 0])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_invitation_is_hashed_single_use_and_assigns_only_its_stored_role_and_clients(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $client = Client::create(['name' => 'Invited client']);
        $response = $this->signIn($admin)->postJson('/api/admin/invitations', ['email' => 'invite@example.test',
            'role' => 'REVIEWER', 'client_ids' => [$client->id]])->assertCreated()->assertJsonMissingPath('invitation.token_hash');
        parse_str(parse_url($response->json('invitation_url'), PHP_URL_QUERY), $query);
        $token = $query['token'];
        $this->assertSame(hash('sha256', $token), Invitation::firstOrFail()->getRawOriginal('token_hash'));
        $this->getJson('/api/admin/invitations')->assertDontSee($token);
        $this->postJson('/api/auth/logout')->assertOk();
        $data = ['token' => $token, 'name' => 'Invited Reviewer', 'role' => 'ADMIN',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->postJson('/api/invitations/accept', $data)->assertCreated()->assertJsonPath('user.role', 'REVIEWER');
        $this->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'clients');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/invitations/accept', $data)->assertUnprocessable();
    }

    public function test_expired_revoked_and_demoted_inviter_links_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        foreach (['expired', 'revoked', 'demoted'] as $case) {
            $token = Str::random(64);
            $invite = Invitation::create(['email' => $case.'@example.test', 'role' => 'WRITER',
                'invited_by' => $admin->id, 'token_hash' => hash('sha256', $token), 'client_ids' => [],
                'expires_at' => $case === 'expired' ? now()->subMinute() : now()->addDay(),
                'revoked_at' => $case === 'revoked' ? now() : null]);
            if ($case === 'demoted') {
                $admin->update(['role' => 'WRITER']);
            }
            $this->postJson('/api/invitations/inspect', ['token' => $token])->assertUnprocessable();
            $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Invalid invite',
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_recovery_does_not_enumerate_accounts_or_notify_inactive_users(): void
    {
        Notification::fake();
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $known = $this->postJson('/api/auth/forgot-password', ['email' => $active->email])->assertOk()->json();
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->json();
        $this->postJson('/api/auth/forgot-password', ['email' => $inactive->email])->assertOk();
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_password_reset_is_single_use_and_invalidates_prior_sessions(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token,
            'password' => 'New secure password 2026', 'password_confirmation' => 'New secure password 2026'];
        $this->postJson('/api/auth/reset-password', $data)->assertOk();
        $this->assertSame(1, $user->fresh()->auth_version);
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->postJson('/api/auth/reset-password', $data)->assertUnprocessable();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $data['password']])->assertOk();
    }

    public function test_password_change_requires_current_password_and_ends_the_session(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $data = ['current_password' => 'incorrect', 'password' => 'Changed secure password', 'password_confirmation' => 'Changed secure password'];
        $this->signIn($user)->postJson('/api/auth/change-password', $data)->assertUnprocessable();
        $this->postJson('/api/auth/change-password', [...$data, 'current_password' => self::PASSWORD])->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited_and_csrf_is_required_outside_test_bypass(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'wrong@example.test', 'password' => 'incorrect'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/login', ['email' => 'wrong@example.test', 'password' => 'incorrect'])->assertStatus(429);
        $this->app['env'] = 'local';
        $this->postJson('/api/auth/login', ['email' => 'another@example.test', 'password' => 'incorrect'])->assertStatus(419);
    }

    public function test_audit_records_do_not_contain_invitation_tokens_or_passwords(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $response = $this->signIn($admin)->postJson('/api/admin/invitations', ['email' => 'safe@example.test',
            'role' => 'WRITER', 'client_ids' => []])->assertCreated();
        parse_str(parse_url($response->json('invitation_url'), PHP_URL_QUERY), $query);
        $this->getJson('/api/admin/audit-events')->assertOk()->assertDontSee($query['token'])->assertDontSee(self::PASSWORD);
        $this->assertTrue(AuditEvent::where('event_type', 'invitation.created')->exists());
    }

    public function test_cli_can_provision_an_administrator_without_a_password_argument(): void
    {
        $this->artisan('studio:create-admin', ['email' => 'console@example.test'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();
        $admin = User::where('email', 'console@example.test')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_malformed_identity_fields_are_validation_errors_not_server_errors(): void
    {
        $this->postJson('/api/auth/login', ['email' => ['invalid'], 'password' => ['invalid']])->assertUnprocessable();
        $this->postJson('/api/auth/bootstrap', ['name' => 'Admin', 'email' => 'admin@example.test',
            'password' => ['invalid'], 'password_confirmation' => ['invalid']])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->signIn($admin)->postJson('/api/admin/invitations', ['email' => ['invalid'], 'role' => 'WRITER', 'client_ids' => []])->assertUnprocessable();
        $this->postJson('/api/auth/change-password', ['current_password' => ['invalid'],
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable();
    }
}
