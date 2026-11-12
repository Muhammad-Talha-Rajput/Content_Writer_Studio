<?php

namespace Tests\Feature;

use App\Models\{AuditEvent, Client, ContentBrief, ContentVersion, Project, ReviewSubmission, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $writer;
    private User $reviewer;
    private User $otherReviewer;
    private Client $client;
    private ContentBrief $brief;
    private ContentVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'ADMIN']);
        $this->writer = User::factory()->create(['role' => 'WRITER']);
        $this->reviewer = User::factory()->create(['role' => 'REVIEWER']);
        $this->otherReviewer = User::factory()->create(['role' => 'REVIEWER']);
        $this->client = Client::create(['name' => 'Review test client']);
        foreach ([$this->writer, $this->reviewer, $this->otherReviewer] as $user) {
            $user->clients()->attach($this->client->id, ['id' => (string) Str::uuid(), 'assigned_by' => $this->admin->id]);
        }
        $project = Project::create(['name' => 'Review test project', 'client_id' => $this->client->id, 'created_by' => $this->writer->id]);
        $instructions = ['objective' => 'INFORM', 'audience' => 'Test readers', 'language' => 'English', 'tone' => 'Clear',
            'reviewer_id' => $this->reviewer->id, 'call_to_action' => '', 'primary_keyword' => ''];
        $this->brief = ContentBrief::create(['project_id' => $project->id, 'created_by' => $this->writer->id,
            'topic' => 'A safe review workflow', 'content_type' => 'EMAIL', 'instructions' => $instructions,
            'brand_snapshot' => [], 'status' => 'DRAFT']);
        $this->version = ContentVersion::create(['brief_id' => $this->brief->id, 'author_id' => $this->writer->id,
            'version_no' => 1, 'version_kind' => 'AI', 'title' => 'A reviewable message',
            'body_markdown' => 'A careful draft with <script>alert(1)</script>.',
            'blocks' => [['id' => 'p1', 'kind' => 'PARAGRAPH', 'text' => 'A careful draft with <script>alert(1)</script>.', 'citations' => []]],
            'format_metadata' => ['email_subject' => 'A test subject'], 'brief_snapshot' => $this->snapshot(), 'quality_findings' => []]);
    }

    private function snapshot(): array
    {
        return $this->brief->only(['id', 'topic', 'content_type', 'instructions', 'brand_snapshot']);
    }

    private function signIn(User $user): static
    {
        Auth::forgetGuards();
        return $this->actingAs($user, 'web')->withSession(['auth_version' => $user->auth_version]);
    }

    private function submitVersion(): string
    {
        return $this->signIn($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/submit-review', ['version_id' => $this->version->id])
            ->assertCreated()->json('submission.id');
    }

    public function test_writer_reviewer_and_export_follow_latest_approved_version(): void
    {
        $submissionId = $this->submitVersion();
        $this->assertDatabaseHas('content_briefs', ['id' => $this->brief->id, 'status' => 'IN_REVIEW']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'review.submitted', 'target_id' => $submissionId]);

        $this->signIn($this->reviewer)->postJson('/api/reviews/'.$submissionId.'/decision', ['decision' => 'APPROVED', 'comment' => 'Looks good.'])
            ->assertOk()->assertJsonPath('submission.status', 'APPROVED');
        $this->assertDatabaseHas('content_briefs', ['id' => $this->brief->id, 'status' => 'APPROVED']);

        $export = $this->signIn($this->writer)->get('/api/versions/'.$this->version->id.'/export?format=html')
            ->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $export->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'content.version_exported', 'target_id' => $this->version->id]);

        $this->signIn($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/versions', [
            'latest_version_id' => $this->version->id, 'title' => 'Edited message',
            'blocks' => [['id' => 'p1', 'kind' => 'PARAGRAPH', 'text' => 'A revised careful draft.', 'citations' => []]],
        ])->assertCreated()->assertJsonPath('version.version_no', 2);
        $this->get('/api/versions/'.$this->version->id.'/export?format=html')->assertForbidden();
        $this->assertDatabaseHas('review_submissions', ['id' => $submissionId, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('content_briefs', ['id' => $this->brief->id, 'status' => 'DRAFT']);
    }

    public function test_only_latest_clean_versions_can_be_submitted_and_review_is_independent(): void
    {
        $this->signIn($this->reviewer)->postJson('/api/briefs/'.$this->brief->id.'/submit-review', ['version_id' => $this->version->id])->assertForbidden();

        $newer = ContentVersion::create(['brief_id' => $this->brief->id, 'author_id' => $this->writer->id,
            'version_no' => 2, 'version_kind' => 'MANUAL', 'title' => 'Newer message', 'body_markdown' => 'New text.',
            'blocks' => [['id' => 'p1', 'kind' => 'PARAGRAPH', 'text' => 'New text.', 'citations' => []]],
            'format_metadata' => ['email_subject' => 'A test subject'], 'brief_snapshot' => $this->snapshot(), 'quality_findings' => []]);
        $this->signIn($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/submit-review', ['version_id' => $this->version->id])->assertStatus(409);

        $newer->update(['quality_findings' => [['severity' => 'HARD', 'message' => 'Blocking issue.']]]);
        $this->postJson('/api/briefs/'.$this->brief->id.'/submit-review', ['version_id' => $newer->id])->assertUnprocessable();
        $this->assertDatabaseCount('review_submissions', 0);
    }

    public function test_only_assigned_reviewer_can_decide_and_change_requests_need_a_note(): void
    {
        $submissionId = $this->submitVersion();
        $this->signIn($this->writer)->postJson('/api/reviews/'.$submissionId.'/decision', ['decision' => 'APPROVED'])->assertForbidden();
        $this->signIn($this->otherReviewer)->postJson('/api/reviews/'.$submissionId.'/decision', ['decision' => 'APPROVED'])->assertForbidden();
        $this->signIn($this->reviewer)->postJson('/api/reviews/'.$submissionId.'/decision', ['decision' => 'CHANGES_REQUESTED', 'comment' => 'No'])->assertUnprocessable();
        $this->assertDatabaseHas('review_submissions', ['id' => $submissionId, 'status' => 'SUBMITTED']);

        $this->postJson('/api/reviews/'.$submissionId.'/decision', ['decision' => 'CHANGES_REQUESTED', 'comment' => 'Please clarify this claim.'])
            ->assertOk()->assertJsonPath('submission.status', 'CHANGES_REQUESTED')
            ->assertJsonPath('submission.decision_comment', 'Please clarify this claim.');
        $this->assertDatabaseHas('content_briefs', ['id' => $this->brief->id, 'status' => 'CHANGES_REQUESTED']);
        $this->assertTrue(AuditEvent::where('event_type', 'review.changes_requested')->where('target_id', $submissionId)->exists());
    }
}
