<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BrandProfile;
use App\Models\Client;
use App\Models\ContentBrief;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $writer;

    private User $reviewer;

    private Client $client;

    private Client $other;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'ADMIN']);
        $this->writer = User::factory()->create(['role' => 'WRITER']);
        $this->reviewer = User::factory()->create(['role' => 'REVIEWER']);
        $this->client = Client::create(['name' => 'Assigned brand'])->fresh();
        $this->other = Client::create(['name' => 'Private brand'])->fresh();
        foreach ([$this->writer, $this->reviewer] as $user) {
            $user->clients()->attach($this->client->id, ['id' => (string) Str::uuid(), 'assigned_by' => $this->admin->id]);
        }
        $this->project = Project::create(['name' => 'Assigned campaign', 'client_id' => $this->client->id, 'created_by' => $this->writer->id])->fresh();
    }

    private function signIn(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web')->withSession(['auth_version' => $user->auth_version]);
    }

    private function brand(array $changes = []): array
    {
        return [...['version' => 0, 'default_audience' => 'Small business owners', 'default_tone' => 'Friendly',
            'preferred_terms' => ['Practical'], 'prohibited_terms' => ['Guaranteed'], 'approved_facts' => 'Product facts',
            'claims_requiring_evidence' => 'Performance claims', 'sample_copy' => 'An approved writing example.'], ...$changes];
    }

    private function brief(array $changes = []): array
    {
        return [...['topic' => 'An effective content marketing plan', 'content_type' => 'BLOG', 'objective' => 'INFORM',
            'audience' => 'Small business owners', 'language' => 'English', 'tone' => 'Friendly', 'target_words' => 1000,
            'secondary_keywords' => ['Marketing', 'Planning'], 'reference_urls' => ['https://example.com/guide'],
            'research_mode' => 'PROVIDED_ONLY', 'reviewer_id' => $this->reviewer->id], ...$changes];
    }

    private function createBrief(): array
    {
        return $this->signIn($this->writer)->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief())->assertCreated()->json('brief');
    }

    public function test_administrators_manage_clients_with_conflict_and_archive_protection(): void
    {
        $this->signIn($this->writer)->patchJson('/api/clients/'.$this->client->id, ['version' => 1, 'name' => 'Wrong'])->assertForbidden();
        $this->signIn($this->admin)->patchJson('/api/clients/'.$this->client->id, ['version' => 1, 'name' => 'Updated brand', 'website' => 'https://example.com'])->assertOk()->assertJsonPath('client.version', 2);
        $this->patchJson('/api/clients/'.$this->client->id, ['version' => 1, 'name' => 'Stale'])->assertStatus(409);
        $this->patchJson('/api/clients/'.$this->client->id, ['version' => 2, 'is_archived' => true])->assertOk();
        $this->patchJson('/api/clients/'.$this->client->id, ['version' => 3, 'name' => 'Blocked'])->assertStatus(409);
        $this->patchJson('/api/clients/'.$this->client->id, ['version' => 3, 'is_archived' => false])->assertOk();
        $this->deleteJson('/api/clients/'.$this->client->id)->assertStatus(405);
    }

    public function test_brand_profiles_are_unique_scoped_and_conflict_checked(): void
    {
        $url = '/api/clients/'.$this->client->id.'/brand-profile';
        $this->signIn($this->reviewer)->putJson($url, $this->brand())->assertForbidden();
        $this->signIn($this->writer)->putJson('/api/clients/'.$this->other->id.'/brand-profile', $this->brand())->assertForbidden();
        $this->putJson($url, $this->brand())->assertOk()->assertJsonPath('brand_profile.version', 1);
        $this->putJson($url, $this->brand())->assertStatus(409);
        $this->putJson($url, $this->brand(['version' => 1, 'default_tone' => 'Formal']))->assertOk();
        $this->assertSame(1, BrandProfile::where('client_id', $this->client->id)->count());
        $this->signIn($this->reviewer)->getJson('/api/clients/'.$this->client->id)->assertOk()->assertJsonPath('client.brand_profile.default_tone', 'Formal');
    }

    public function test_projects_enforce_role_client_boundary_and_immutable_parent(): void
    {
        $this->signIn($this->reviewer)->postJson('/api/clients/'.$this->client->id.'/projects', ['name' => 'Blocked'])->assertForbidden();
        $this->signIn($this->writer)->postJson('/api/clients/'.$this->other->id.'/projects', ['name' => 'Blocked'])->assertForbidden();
        $this->postJson('/api/clients/'.$this->client->id.'/projects', ['name' => 'A new campaign'])->assertCreated();
        $this->patchJson('/api/projects/'.$this->project->id, ['version' => 1, 'client_id' => $this->other->id])->assertUnprocessable();
        $private = Project::create(['name' => 'Hidden campaign', 'client_id' => $this->other->id, 'created_by' => $this->admin->id]);
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(2, 'projects')->assertDontSee('Hidden campaign');
        $this->getJson('/api/projects/'.$private->id)->assertForbidden();
        $this->getJson('/api/projects?client_id='.$this->other->id)->assertForbidden();
    }

    public function test_all_content_formats_and_social_limits_are_validated(): void
    {
        $this->signIn($this->writer);
        foreach (['BLOG', 'WEBSITE', 'EMAIL', 'PRODUCT', 'SOCIAL'] as $type) {
            $data = $this->brief(['content_type' => $type]);
            if ($type === 'SOCIAL') {
                $data = [...$data, 'social_platform' => 'X', 'target_characters' => 280];
            }
            $response = $this->postJson('/api/projects/'.$this->project->id.'/briefs', $data)->assertCreated()->assertJsonPath('brief.status', 'DRAFT');
            $this->assertTrue(Str::isUuid($response->json('brief.id')));
            if ($type === 'SOCIAL') {
                $response->assertJsonMissingPath('brief.instructions.target_words');
            }
        }
        foreach ([['target_words' => 299], ['target_words' => 2501], ['audience' => 'Short'],
            ['language' => 'Other'], ['secondary_keywords' => ['Same', 'same']], ['status' => 'APPROVED'],
            ['content_type' => 'SOCIAL', 'social_platform' => 'X', 'target_characters' => 281]] as $invalid) {
            $this->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief($invalid))->assertUnprocessable();
        }
    }

    public function test_public_reference_url_rules_reject_internal_and_non_web_addresses(): void
    {
        $this->signIn($this->writer);
        foreach (['file:///etc/passwd', 'http://127.0.0.1/a', 'http://localhost/a', 'http://10.0.0.1/a', 'https://name:secret@example.com/a'] as $url) {
            $this->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief(['reference_urls' => [$url]]))->assertUnprocessable();
        }
        $this->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief(['reference_urls' => []]))->assertCreated();
    }

    public function test_brand_snapshots_stay_stable_until_explicitly_refreshed(): void
    {
        $this->signIn($this->writer)->putJson('/api/clients/'.$this->client->id.'/brand-profile', $this->brand())->assertOk();
        $brief = $this->createBrief();
        $this->assertSame('Friendly', $brief['brand_snapshot']['profile']['default_tone']);
        $this->putJson('/api/clients/'.$this->client->id.'/brand-profile', $this->brand(['version' => 1, 'default_tone' => 'Formal']))->assertOk();
        $this->putJson('/api/briefs/'.$brief['id'], $this->brief(['version' => 1, 'target_words' => '1200']))->assertOk()
            ->assertJsonPath('brief.brand_snapshot.profile.default_tone', 'Friendly')->assertJsonPath('brief.instructions.target_words', 1200);
        $this->putJson('/api/briefs/'.$brief['id'], $this->brief(['version' => 1]))->assertStatus(409);
        $this->putJson('/api/briefs/'.$brief['id'], $this->brief(['version' => 2, 'refresh_brand' => true]))->assertOk()
            ->assertJsonPath('brief.brand_snapshot.profile.default_tone', 'Formal');
    }

    public function test_reviewers_must_be_active_independent_and_assigned_to_the_client(): void
    {
        $outsider = User::factory()->create(['role' => 'REVIEWER']);
        $inactive = User::factory()->create(['role' => 'REVIEWER', 'is_active' => false]);
        $this->signIn($this->writer);
        foreach ([$outsider->id, $inactive->id, $this->writer->id] as $id) {
            $this->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief(['reviewer_id' => $id]))->assertUnprocessable();
        }
        $this->signIn($this->admin)->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief(['reviewer_id' => $this->admin->id]))->assertUnprocessable();
        $this->signIn($this->writer)->getJson('/api/clients/'.$this->client->id.'/reviewers')->assertOk()->assertJsonCount(2, 'reviewers');
    }

    public function test_briefs_are_scoped_and_reviewers_have_read_only_access(): void
    {
        $brief = $this->createBrief();
        $privateProject = Project::create(['name' => 'Other', 'client_id' => $this->other->id, 'created_by' => $this->admin->id]);
        $privateBrief = ContentBrief::create(['project_id' => $privateProject->id, 'created_by' => $this->admin->id, 'topic' => 'Hidden topic', 'content_type' => 'BLOG', 'instructions' => [], 'brand_snapshot' => []]);
        $this->getJson('/api/briefs')->assertOk()->assertJsonCount(1, 'briefs')->assertDontSee('Hidden topic');
        $this->getJson('/api/briefs/'.$privateBrief->id)->assertForbidden();
        $this->putJson('/api/briefs/'.$privateBrief->id, $this->brief(['version' => 1]))->assertForbidden();
        $this->signIn($this->reviewer)->getJson('/api/briefs/'.$brief['id'])->assertOk();
        $this->putJson('/api/briefs/'.$brief['id'], $this->brief(['version' => 1]))->assertForbidden();
        $this->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief())->assertForbidden();
        $this->patchJson('/api/briefs/'.$brief['id'].'/archive', ['version' => 1, 'is_archived' => true])->assertForbidden();
    }

    public function test_archived_parents_and_briefs_are_read_only_and_restorable(): void
    {
        $brief = $this->createBrief();
        $briefUrl = '/api/briefs/'.$brief['id'];
        $this->patchJson($briefUrl.'/archive', ['version' => 1, 'is_archived' => true])->assertOk();
        $this->putJson($briefUrl, $this->brief(['version' => 2]))->assertStatus(409);
        $this->patchJson($briefUrl.'/archive', ['version' => 2, 'is_archived' => false])->assertOk();
        $this->patchJson('/api/projects/'.$this->project->id, ['version' => 1, 'is_archived' => true])->assertOk();
        $this->putJson($briefUrl, $this->brief(['version' => 3]))->assertStatus(409);
        $this->postJson('/api/projects/'.$this->project->id.'/briefs', $this->brief())->assertStatus(409);
        $this->patchJson('/api/projects/'.$this->project->id, ['version' => 2, 'is_archived' => false])->assertOk();
        $this->signIn($this->admin)->patchJson('/api/clients/'.$this->client->id, ['version' => 1, 'is_archived' => true])->assertOk();
        $this->signIn($this->writer)->putJson('/api/clients/'.$this->client->id.'/brand-profile', $this->brand())->assertStatus(409);
        $this->putJson($briefUrl, $this->brief(['version' => 3]))->assertStatus(409);
        $this->getJson($briefUrl)->assertOk();
        $this->assertDatabaseCount('content_briefs', 1);
        $this->assertTrue(AuditEvent::where('event_type', 'brief.created')->exists());
    }
}
