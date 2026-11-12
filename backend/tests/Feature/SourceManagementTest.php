<?php

namespace Tests\Feature;

use App\Jobs\ProcessSource;
use App\Models\Client;
use App\Models\ContentBrief;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Services\PublicSourceFetcher;
use App\Services\SourceExtractor;
use App\Services\SourceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SourceManagementTest extends TestCase
{
    use RefreshDatabase;
    private User $writer;
    private User $reviewer;
    private User $outsider;
    private ContentBrief $brief;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('sources');
        Queue::fake();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->writer = User::factory()->create(['role' => 'WRITER']);
        $this->reviewer = User::factory()->create(['role' => 'REVIEWER']);
        $this->outsider = User::factory()->create(['role' => 'WRITER']);
        $client = Client::create(['name' => 'Source tests']);
        foreach ([$this->writer, $this->reviewer] as $user) $user->clients()->attach($client->id, ['id' => (string) Str::uuid(), 'assigned_by' => $admin->id]);
        $project = Project::create(['client_id' => $client->id, 'name' => 'Source campaign', 'created_by' => $this->writer->id]);
        $this->brief = ContentBrief::create(['project_id' => $project->id, 'created_by' => $this->writer->id, 'topic' => 'Reference extraction', 'content_type' => 'BLOG', 'instructions' => [], 'brand_snapshot' => [], 'status' => 'DRAFT'])->fresh();
    }

    private function signIn(User $user): static
    {
        Auth::forgetGuards();
        return $this->actingAs($user, 'web')->withSession(['auth_version' => $user->auth_version]);
    }

    private function attach(string $text = 'Verified reference material for writing.'): Source
    {
        $id = $this->signIn($this->writer)->post('/api/briefs/'.$this->brief->id.'/sources', ['source_type' => 'FILE', 'file' => UploadedFile::fake()->createWithContent('reference.txt', $text)], ['Accept' => 'application/json'])->assertStatus(202)->json('source.id');
        return Source::findOrFail($id);
    }

    private function process(Source $source, ?SourceScanner $scanner = null): void
    {
        $scanner ??= Mockery::mock(SourceScanner::class)->shouldReceive('scan')->once()->getMock();
        (new ProcessSource($source->id))->handle($scanner, new SourceExtractor, new PublicSourceFetcher);
    }

    public function test_private_upload_is_queued_then_ready_with_immutable_hash_and_locators(): void
    {
        $source = $this->attach();
        Queue::assertPushedOn('sources', ProcessSource::class);
        $this->assertSame('PENDING', $source->parse_status);
        $this->getJson('/api/sources/'.$source->id.'/download')->assertStatus(409);
        Auth::guard('web')->logout();
        Auth::forgetGuards(); // Queue workers have no signed-in web user.
        $this->process($source);
        $source->refresh();
        $this->assertSame('READY', $source->parse_status);
        $this->assertSame('CLEAN', $source->scan_status);
        $this->assertSame(hash('sha256', 'Verified reference material for writing.'), $source->content_hash);
        $this->assertSame('Text', $source->segments[0]['locator']);
        $this->signIn($this->writer);
        $this->getJson('/api/briefs/'.$this->brief->id.'/sources')->assertJsonPath('ready_count', 1)->assertJsonMissingPath('sources.0.extracted_text');
        $this->getJson('/api/sources/'.$source->id)->assertOk()->assertJsonMissingPath('source.storage_key');
        $this->get('/api/sources/'.$source->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $neverScan = Mockery::mock(SourceScanner::class)->shouldNotReceive('scan')->getMock();
        $this->process($source, $neverScan);
        $this->assertSame($source->content_hash, $source->fresh()->content_hash);
        $this->putJson('/api/sources/'.$source->id, ['extracted_text' => 'Tampered'])->assertStatus(405);
    }

    public function test_scope_and_reviewer_permissions_protect_list_text_original_and_mutations(): void
    {
        $source = $this->attach(); $this->process($source);
        $this->signIn($this->outsider)->getJson('/api/briefs/'.$this->brief->id.'/sources')->assertForbidden();
        $this->getJson('/api/sources/'.$source->id)->assertForbidden();
        $this->getJson('/api/sources/'.$source->id.'/download')->assertForbidden();
        $this->signIn($this->reviewer)->getJson('/api/sources/'.$source->id)->assertOk();
        $this->postJson('/api/briefs/'.$this->brief->id.'/sources', ['source_type' => 'URL', 'url' => 'https://example.com'])->assertForbidden();
        $this->patchJson('/api/sources/'.$source->id.'/archive', ['version' => 1, 'is_archived' => true])->assertForbidden();
    }

    public function test_scan_failure_and_unreadable_file_are_rejected_and_removed(): void
    {
        $source = $this->attach(); $key = $source->storage_key;
        $scanner = Mockery::mock(SourceScanner::class)->shouldReceive('scan')->once()->andThrow(new \RuntimeException('Scan failed'))->getMock();
        $this->process($source, $scanner);
        $this->assertSame('REJECTED', $source->fresh()->parse_status);
        Storage::disk('sources')->assertMissing($key);
        $this->getJson('/api/briefs/'.$this->brief->id.'/sources')->assertJsonPath('ready_count', 0);
        $binary = $this->attach("MZ\0\0\0Executable pretending to be text");
        $this->process($binary);
        $this->assertSame('REJECTED', $binary->fresh()->parse_status);
        $this->assertNull($binary->fresh()->storage_key);
    }

    public function test_archives_versions_quota_and_parent_archives_are_enforced(): void
    {
        $first = null;
        for ($i = 0; $i < 5; $i++) { $source = $this->attach(); $first ??= $source; }
        $url = '/api/briefs/'.$this->brief->id.'/sources';
        $this->post($url, ['source_type' => 'FILE', 'file' => UploadedFile::fake()->createWithContent('six.txt', 'Sixth reference document')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->patchJson('/api/sources/'.$first->id.'/archive', ['version' => 1, 'is_archived' => true])->assertOk()->assertJsonPath('source.version', 2);
        $this->patchJson('/api/sources/'.$first->id.'/archive', ['version' => 1, 'is_archived' => false])->assertStatus(409);
        $this->process($first, Mockery::mock(SourceScanner::class)->shouldNotReceive('scan')->getMock());
        $this->assertSame('REJECTED', $first->fresh()->parse_status);
        $this->brief->update(['is_archived' => true]);
        $this->postJson($url, ['source_type' => 'URL', 'url' => 'https://example.com'])->assertStatus(409);
    }

    public function test_job_rechecks_creator_access_and_worker_failures_do_not_leave_usable_sources(): void
    {
        $source = $this->attach();
        $this->writer->clients()->detach();
        $scanner = Mockery::mock(SourceScanner::class)->shouldNotReceive('scan')->getMock();
        $this->process($source, $scanner);
        $this->assertSame('REJECTED', $source->fresh()->parse_status);
        $this->assertNull($source->fresh()->extracted_text);
        $this->brief->project->client->users()->attach($this->writer->id, ['id' => (string) Str::uuid(), 'assigned_by' => $this->reviewer->id]);
        $interrupted = $this->attach();
        (new ProcessSource($interrupted->id))->failed(new \RuntimeException('Stopped'));
        $this->assertSame('REJECTED', $interrupted->fresh()->parse_status);
        Storage::disk('sources')->assertMissing($interrupted->storage_key);
    }

    public function test_url_snapshots_strip_active_html_and_duplicates_require_archive(): void
    {
        $url = '/api/briefs/'.$this->brief->id.'/sources';
        $id = $this->signIn($this->writer)->postJson($url, ['source_type' => 'URL', 'url' => 'https://example.com/article'])->assertStatus(202)->json('source.id');
        $this->postJson($url, ['source_type' => 'URL', 'url' => 'https://example.com/article'])->assertUnprocessable();
        $this->postJson($url, ['source_type' => 'URL', 'url' => 'http://127.0.0.1/secret'])->assertUnprocessable();
        $fetcher = Mockery::mock(PublicSourceFetcher::class)->shouldReceive('fetch')->once()->andReturn(['url' => 'https://example.com/article', 'mime' => 'text/html', 'body' => '<html><title>Reference</title><body><nav>Menu secrets</nav><article><h1>Our service</h1><p>Supported facts for the content brief.</p><script>executeEvil()</script></article></body></html>'])->getMock();
        (new ProcessSource($id))->handle(new SourceScanner, new SourceExtractor, $fetcher);
        $source = Source::findOrFail($id);
        $this->assertSame('READY', $source->parse_status);
        $this->assertSame('NOT_REQUIRED', $source->scan_status);
        $this->assertStringNotContainsString('executeEvil', $source->extracted_text);
        $this->assertStringNotContainsString('Menu secrets', $source->extracted_text);
        $this->get('/api/sources/'.$id.'/download')->assertOk()->assertDownload('source-'.$id.'.txt');
        $this->patchJson('/api/sources/'.$id.'/archive', ['version' => 1, 'is_archived' => true])->assertOk();
        $this->postJson($url, ['source_type' => 'URL', 'url' => 'https://example.com/article'])->assertStatus(202);
        Storage::disk('sources')->assertExists($source->storage_key);
    }

    public function test_oversized_and_unsupported_uploads_are_refused_before_queueing(): void
    {
        $url = '/api/briefs/'.$this->brief->id.'/sources';
        $this->signIn($this->writer)->post($url, ['source_type' => 'FILE', 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($url, ['source_type' => 'FILE', 'file' => UploadedFile::fake()->createWithContent('run.exe', 'Executable')], ['Accept' => 'application/json'])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_pdf_preview_requires_scanned_ready_pdf_and_current_client_access(): void
    {
        $source = $this->attach();
        $url = '/api/sources/'.$source->id.'/preview';
        $this->getJson($url)->assertStatus(409);
        $this->getJson('/api/sources/'.$source->id.'/pages/1')->assertStatus(409);
        $this->process($source);
        $this->getJson($url)->assertStatus(415);
        $this->getJson('/api/sources/'.$source->id.'/pages/1')->assertStatus(415);
        // Simulate validated PDF metadata; real PDF parsing has separate coverage.
        $source->refresh()->update(['mime_type' => 'application/pdf']);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="source.pdf"')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->signIn($this->reviewer)->get($url)->assertOk();
        $this->signIn($this->outsider)->getJson($url)->assertForbidden();
        $this->getJson('/api/sources/'.$source->id.'/pages/1')->assertForbidden();
        $source->update(['scan_status' => 'FAILED']);
        $this->signIn($this->writer)->getJson($url)->assertStatus(409);
        $source->update(['scan_status' => 'CLEAN']);
        Storage::disk('sources')->delete($source->storage_key);
        $this->getJson($url)->assertNotFound();
    }

    public function test_previously_queued_source_can_finish_during_generation_but_new_intake_is_locked(): void
    {
        $source=$this->attach();
        $this->brief->update(['status'=>'IN_PROGRESS']);
        $this->postJson('/api/briefs/'.$this->brief->id.'/sources',['source_type'=>'URL','url'=>'https://example.com'])->assertStatus(409);
        $this->process($source);
        $this->assertSame('READY',$source->fresh()->parse_status);
    }
}
