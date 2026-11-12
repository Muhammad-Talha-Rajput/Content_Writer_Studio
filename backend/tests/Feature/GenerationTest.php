<?php
namespace Tests\Feature;

use App\Jobs\GenerateContent;
use App\Models\{AgentRun, AgentStep, AiSetting, Citation, Client, ContentBrief, ContentVersion, Project, Source, User};
use App\Services\{AiGateway, AiProviderException, GenerationWorkflow, PublicSourceFetcher, SourceExtractor};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Cache, DB, Http, Queue, Storage};
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $writer;
    private User $reviewer;
    private User $outsider;
    private ContentBrief $brief;
    private Source $source;
    private string $fact='Grands Digital offers 8 social media captions.';

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Http::preventStrayRequests();
        $this->admin=User::factory()->create(['role'=>'ADMIN']);
        $this->writer=User::factory()->create(['role'=>'WRITER']);
        $this->reviewer=User::factory()->create(['role'=>'REVIEWER']);
        $this->outsider=User::factory()->create(['role'=>'WRITER']);
        $client=Client::create(['name'=>'Generation test brand']);
        foreach ([$this->writer,$this->reviewer] as $user) $client->users()->attach($user->id,['id'=>(string)Str::uuid(),'assigned_by'=>$this->admin->id]);
        $project=Project::create(['client_id'=>$client->id,'created_by'=>$this->writer->id,'name'=>'Generation campaign']);
        $this->brief=ContentBrief::create(['project_id'=>$project->id,'created_by'=>$this->writer->id,'topic'=>'Social content service','content_type'=>'SOCIAL',
            'instructions'=>['objective'=>'INFORM','audience'=>'Small business owners','language'=>'English','tone'=>'Friendly','social_platform'=>'LinkedIn','target_characters'=>100,'research_mode'=>'PROVIDED_ONLY','secondary_keywords'=>[],'reference_urls'=>[]], 'brand_snapshot'=>[],'status'=>'DRAFT'])->fresh();
        $this->source=Source::create(['brief_id'=>$this->brief->id,'created_by'=>$this->writer->id,'source_type'=>'FILE','title'=>'Approved reference.txt','parse_status'=>'READY','scan_status'=>'CLEAN',
            'content_hash'=>hash('sha256',$this->fact),'extracted_text'=>$this->fact,'segments'=>[['ordinal'=>1,'locator'=>'Text','page'=>null,'start'=>0,'end'=>mb_strlen($this->fact),'text'=>$this->fact]]])->fresh();
        $profile=AiSetting::current(); $profile->update(['enabled'=>true,'model'=>'example/model:free','api_key'=>'unit-test-provider-key','limits'=>[...config('generation.limits'),'revision_passes'=>0]]);
    }
    private function login(User $user): static { Auth::forgetGuards(); return $this->actingAs($user,'web')->withSession(['auth_version'=>$user->auth_version]); }
    private function payload(array $changes=[]): array
    {
        return [...['idempotency_key'=>(string)Str::uuid(),'brief_version'=>$this->brief->fresh()->version,'latest_version_id'=>ContentVersion::where('brief_id',$this->brief->id)->orderByDesc('version_no')->value('id'), 'provider'=>'openrouter','source_ids'=>[$this->source->id]],...$changes];
    }
    private function start(array $changes=[]): AgentRun
    {
        $id=$this->login($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/runs',$this->payload($changes))->assertStatus(202)->json('run.id');
        return AgentRun::findOrFail($id);
    }
    private function citation(): array { return ['source_id'=>$this->source->id,'segment_ordinal'=>1,'supporting_excerpt'=>$this->fact]; }
    private function stageOutput(string $stage): array
    {
        return match($stage) {
            'plan'=>['summary'=>'Use the approved service fact.','outline'=>['Service details'],'research_urls'=>[]],
            'research'=>['evidence_sufficient'=>true,'missing_information'=>[],'notes'=>[['fact'=>$this->fact,'sources'=>[$this->citation()]]]],
            'write'=>['title'=>'Plan your content','blocks'=>[['id'=>'p1','kind'=>'PARAGRAPH','text'=>$this->fact,'citations'=>[$this->citation()]]],'email_subject'=>'','preview_text'=>'','seo_title'=>'','meta_description'=>''],
            'quality'=>['needs_revision'=>false,'findings'=>[]],
            'revise'=>['edits'=>[],'metadata_edits'=>[]],
        };
    }
    private function workflow(?callable $callback=null,?PublicSourceFetcher $fetcher=null): GenerationWorkflow
    {
        $gateway=Mockery::mock(AiGateway::class);
        $gateway->shouldReceive('generate')->andReturnUsing(function($run,$stage) use ($callback) {
            if ($callback) { $override=$callback($run,$stage); if ($override!==null) return $override; }
            return ['output'=>$this->stageOutput($stage),'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]];
        });
        $fetcher??=Mockery::mock(PublicSourceFetcher::class)->shouldNotReceive('fetch')->getMock();
        return new GenerationWorkflow($gateway,$fetcher,new SourceExtractor);
    }

    public function test_run_start_snapshots_inputs_and_is_idempotent_without_exposing_secrets(): void
    {
        $payload=$this->payload(); $url='/api/briefs/'.$this->brief->id.'/runs';
        $response=$this->login($this->writer)->postJson($url,$payload)->assertStatus(202)->assertJsonMissingPath('run.provider_secret');
        $id=$response->json('run.id');
        $this->postJson($url,$payload)->assertStatus(202)->assertJsonPath('run.id',$id);
        $this->postJson($url,[...$payload,'provider'=>'ollama'])->assertStatus(409);
        $this->postJson($url,$this->payload())->assertStatus(409);
        $this->assertDatabaseCount('agent_runs',1); Queue::assertPushed(GenerateContent::class,1);
        $this->assertSame('IN_PROGRESS',$this->brief->fresh()->status);
        $run=AgentRun::find($id);
        $this->assertSame($this->fact,$run->input_snapshot['sources'][0]['segments'][0]['text']);
        $this->assertNotSame('unit-test-provider-key',DB::table('agent_runs')->where('id',$id)->value('provider_secret'));
        $this->getJson('/runs/'.$id)->assertNotFound();
        $this->getJson('/api/runs/'.$id)->assertOk()->assertJsonMissingPath('run.provider_secret');
    }

    public function test_all_stages_save_one_immutable_version_and_exact_citations_atomically(): void
    {
        $run=$this->start(); Auth::forgetGuards();
        $workflow=$this->workflow(); $workflow->execute($run); $workflow->execute($run->fresh());
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $this->assertNull($run->fresh()->active_brief_id);
        $this->assertSame('READY_FOR_REVIEW',$this->brief->fresh()->status);
        $this->assertDatabaseCount('content_versions',1); $this->assertDatabaseCount('citations',1);
        $this->assertSame(4,AgentStep::where('status','SUCCEEDED')->count());
        $version=ContentVersion::first();
        $this->assertSame($this->fact,$version->body_markdown); $this->assertSame('p1',Citation::first()->claim_anchor);
        $this->assertSame(120,$run->fresh()->provider_usage['total_tokens']);
    }

    public function test_read_write_permissions_and_source_scope_are_enforced(): void
    {
        $url='/api/briefs/'.$this->brief->id.'/runs';
        $this->login($this->reviewer)->postJson($url,$this->payload())->assertForbidden();
        $this->login($this->outsider)->postJson($url,$this->payload())->assertForbidden();
        $this->login($this->writer)->postJson($url,$this->payload(['source_ids'=>[(string)Str::uuid()]]))->assertUnprocessable();
        $run=$this->start(); $this->workflow()->execute($run); $version=ContentVersion::first();
        $this->login($this->outsider)->getJson('/api/runs/'.$run->id)->assertForbidden();
        $this->getJson('/api/versions/'.$version->id)->assertForbidden();
        $this->login($this->reviewer)->getJson('/api/versions/'.$version->id)->assertOk();
        $this->postJson('/api/runs/'.$run->id.'/cancel')->assertForbidden();
    }

    public function test_missing_sources_stale_versions_and_provider_setup_block_starts(): void
    {
        $url='/api/briefs/'.$this->brief->id.'/runs';
        $this->login($this->writer)->postJson($url,$this->payload(['source_ids'=>[]]))->assertUnprocessable();
        $this->postJson($url,$this->payload(['brief_version'=>99]))->assertStatus(409);
        $this->postJson($url,$this->payload(['latest_version_id'=>(string)Str::uuid()]))->assertStatus(409);
        AiSetting::current()->update(['enabled'=>false]);
        $this->postJson($url,$this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('agent_runs',0);
    }

    public function test_cancel_during_provider_request_records_usage_and_never_promotes_output(): void
    {
        $run=$this->start();
        $this->workflow(function($run,$stage) { if ($stage==='write') GenerationWorkflow::finish($run->id,'CANCELLED','CANCELLED','Cancelled in test'); return null; })->execute($run);
        $this->assertSame('CANCELLED',$run->fresh()->status); $this->assertSame('DRAFT',$this->brief->fresh()->status);
        $this->assertDatabaseCount('content_versions',0); $this->assertSame(90,$run->fresh()->provider_usage['total_tokens']);
        $this->assertSame(0,AgentStep::where('step_type','QUALITY')->where('status','SUCCEEDED')->count());
    }

    public function test_access_revocation_and_provider_failure_preserve_previous_versions(): void
    {
        $first=$this->start(); $this->workflow()->execute($first);
        $second=$this->start(); $this->writer->clients()->detach();
        $this->workflow()->execute($second);
        $this->assertSame('ACCESS_CHANGED',$second->fresh()->error_code);
        $this->assertSame('READY_FOR_REVIEW',$this->brief->fresh()->status);
        $this->assertDatabaseCount('content_versions',1);
    }

    public function test_transient_stage_retries_are_bounded_and_each_attempt_is_saved(): void
    {
        $run=$this->start(); $attempts=0;
        $this->workflow(function($run,$stage) use (&$attempts) { if ($stage==='plan') { $attempts++; throw new AiProviderException('RATE_LIMIT','Provider rate limit',true); } return null; })->execute($run);
        $this->assertSame(3,$attempts); $this->assertSame('FAILED',$run->fresh()->status);
        $this->assertSame(3,AgentStep::where('step_type','PLAN')->where('status','FAILED')->count());
        $this->assertDatabaseCount('content_versions',0);
    }

    public function test_quality_revisions_are_bounded_and_findings_remain_visible(): void
    {
        $p=AiSetting::current(); $p->update(['limits'=>[...$p->limits,'revision_passes'=>2]]);
        $run=$this->start();
        $this->workflow(function($run,$stage) { if ($stage==='quality') return ['output'=>['needs_revision'=>true,'findings'=>[['code'=>'TONE','is_violation'=>true,'severity'=>'HARD','message'=>'Review the tone.','block_id'=>'p1']]],'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]]; return null; })->execute($run);
        $this->assertSame('QUALITY_FAILED',$run->fresh()->error_code);
        $this->assertSame(1,AgentStep::where('step_type','WRITE')->where('status','SUCCEEDED')->count());
        $this->assertSame(2,AgentStep::where('step_type','REVISE')->where('status','SUCCEEDED')->count());
        $this->assertSame(3,AgentStep::where('step_type','QUALITY')->where('status','SUCCEEDED')->count());
        $this->assertDatabaseCount('content_versions',0);
        $this->assertSame('TONE',$run->steps()->where('step_type','QUALITY')->where('pass_no',2)->first()->artifacts['gate_findings'][0]['code']);
    }

    public function test_invented_citations_and_budget_exhaustion_do_not_save_a_version(): void
    {
        $run=$this->start();
        $this->workflow(function($run,$stage) { if ($stage==='write') { $output=$this->stageOutput('write'); $output['blocks'][0]['citations'][0]['source_id']=(string)Str::uuid(); return ['output'=>$output,'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]]; } return null; })->execute($run);
        $this->assertSame('CITATION_INVALID',$run->fresh()->error_code); $this->assertDatabaseCount('content_versions',0);
        $p=AiSetting::current(); $p->update(['limits'=>[...$p->limits,'max_tokens'=>2000]]);
        $limited=$this->start(); $this->workflow(fn($run,$stage)=>$stage==='plan'?['output'=>$this->stageOutput('plan'),'usage'=>['input_tokens'=>2000,'output_tokens'=>100,'total_tokens'=>2100]]:null)->execute($limited);
        $this->assertSame('USAGE_LIMIT',$limited->fresh()->error_code);
    }

    public function test_advisory_findings_do_not_force_extra_provider_calls(): void
    {
        $profile=AiSetting::current(); $profile->update(['limits'=>[...$profile->limits,'revision_passes'=>2]]);
        $run=$this->start();
        $this->workflow(fn($run,$stage)=>$stage==='quality'?['output'=>['needs_revision'=>false,'findings'=>[['code'=>'STYLE','is_violation'=>true,'severity'=>'ADVISORY','message'=>'Consider a warmer tone.','block_id'=>'p1']]],'usage'=>['total_tokens'=>30]]:null)->execute($run);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $this->assertSame(1,$run->steps()->where('step_type','WRITE')->count());
        $this->assertSame(1,$run->steps()->where('step_type','QUALITY')->count());
        $this->assertSame('STYLE',ContentVersion::first()->quality_findings[0]['code']);
    }

    public function test_an_unsupported_needs_revision_flag_does_not_damage_a_draft_that_passed_all_checks(): void
    {
        $p=AiSetting::current();$p->update(['limits'=>[...$p->limits,'revision_passes'=>2]]);$run=$this->start();$calls=[];
        $this->workflow(function($run,$stage)use(&$calls){$calls[]=$stage;if($stage==='quality')return ['output'=>['needs_revision'=>true,'findings'=>[['code'=>'TALKING_POINT_COVERAGE','is_violation'=>false,'severity'=>'HARD','message'=>'All required talking points are covered.','block_id'=>'p1']]],'usage'=>['total_tokens'=>30]];return null;})->execute($run);
        $this->assertSame(['plan','research','write','quality'],$calls);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);$this->assertSame([],$run->output->quality_findings);
    }

    public function test_hard_quality_failure_never_promotes_a_successful_version_even_if_ai_says_pass(): void
    {
        $this->brief->update(['instructions'=>[...$this->brief->instructions,'primary_keyword'=>'required phrase']]);
        $run=$this->start();$this->workflow()->execute($run);
        $this->assertSame('QUALITY_FAILED',$run->fresh()->error_code);
        $this->assertDatabaseCount('content_versions',0);
        $quality=$run->steps()->where('step_type','QUALITY')->first()->artifacts;
        $this->assertSame('PRIMARY_KEYWORD',$quality['gate_findings'][0]['code']);
        $this->assertArrayHasKey('candidate',$quality);
    }

    public function test_quality_repairs_use_targeted_edits_and_do_not_regenerate_the_draft(): void
    {
        $p=AiSetting::current();$p->update(['limits'=>[...$p->limits,'revision_passes'=>1]]);
        $this->brief->update(['instructions'=>[...$this->brief->instructions,'target_characters'=>200,'additional_instructions'=>'Start with: SAMPLE TEST COPY.']]);
        $run=$this->start();$calls=[];
        $this->workflow(function($run,$stage)use(&$calls){
            $calls[]=$stage;
            if($stage==='revise')return ['output'=>['edits'=>[['block_id'=>'p1','operation'=>'PREPEND_TEXT','find'=>'','text'=>'SAMPLE TEST COPY. ','citations'=>[]]],'metadata_edits'=>[]],'usage'=>['total_tokens'=>30]];
            return null;
        })->execute($run);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $this->assertSame(['plan','research','write','quality','revise','quality'],$calls);
        $this->assertSame('SAMPLE TEST COPY. '.$this->fact,ContentVersion::first()->body_markdown);
        $this->assertSame(1,$run->steps()->where('step_type','WRITE')->count());
        $this->assertSame(1,$run->steps()->where('step_type','REVISE')->count());
    }

    public function test_passing_ai_checks_and_incorrect_model_counts_do_not_create_false_hard_failures(): void
    {
        $brief=$this->brief->only(['content_type','instructions','brand_snapshot']);$brief['instructions']['additional_instructions']='Limit of citations is maximum 5.';
        $draft=$this->stageOutput('write');$draft['blocks'][0]['citations']=array_fill(0,5,$this->citation());
        $findings=\App\Services\GenerationQuality::findings($brief,$draft,['findings'=>[
            ['code'=>'CITATION_COUNT','is_violation'=>true,'severity'=>'HARD','message'=>'There are six citations.','block_id'=>'p1'],
            ['code'=>'TALKING_POINT_COVERAGE','is_violation'=>false,'severity'=>'HARD','message'=>'All required facts are covered.','block_id'=>'p1'],
            ['code'=>'FACT_SUPPORT','is_violation'=>false,'severity'=>'HARD','message'=>'Optional warranty is not required.','block_id'=>'p1'],
        ]],[]);
        $this->assertSame([],$findings);
        $this->assertSame(5,\App\Services\GenerationQuality::measurements($draft)['citations']);
    }

    public function test_explicit_minimum_and_maximum_character_counts_are_enforced_exactly(): void
    {
        $brief=$this->brief->only(['content_type','instructions','brand_snapshot']);$brief['instructions']['target_characters']=900;$brief['instructions']['additional_instructions']='Write one caption between 700 and 900 characters.';
        foreach([699=>true,700=>false,900=>false,901=>true] as $length=>$blocked){
            $draft=$this->stageOutput('write');$draft['blocks'][0]['text']=str_repeat('a',$length);
            $findings=\App\Services\GenerationQuality::findings($brief,$draft,['findings'=>[]],[]);
            $this->assertSame($blocked,collect($findings)->contains(fn($finding)=>$finding['code']==='CHARACTER_RANGE' && $finding['severity']==='HARD'));
        }
    }

    public function test_conflicting_length_instructions_are_rejected_before_a_run_or_provider_call(): void
    {
        $this->brief->update(['instructions'=>[...$this->brief->instructions,'target_characters'=>600,'additional_instructions'=>'Write one caption between 700 and 900 characters.']]);
        $this->login($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/runs',$this->payload())->assertUnprocessable()->assertJsonPath('message','The requested minimum character length exceeds the target/platform maximum. Correct the brief before generating.');
        $this->assertDatabaseCount('agent_runs',0);Queue::assertNotPushed(GenerateContent::class);Http::assertNothingSent();
    }

    public function test_targeted_patch_preserves_unedited_blocks_and_rejects_ambiguous_text(): void
    {
        $draft=$this->stageOutput('write');$draft['blocks'][]=['id'=>'p2','kind'=>'PARAGRAPH','text'=>'Keep this paragraph exactly.','citations'=>[]];
        $patch=['edits'=>[['block_id'=>'p1','operation'=>'PREPEND_TEXT','find'=>'','text'=>'Sample: ','citations'=>[]]],'metadata_edits'=>[]];
        $fixed=\App\Services\DraftRepair::apply($draft,$patch);
        $this->assertSame($draft['blocks'][1],$fixed['blocks'][1]);
        $this->assertSame($this->fact,$draft['blocks'][0]['text']);
        $this->assertSame('Sample: '.$this->fact,$fixed['blocks'][0]['text']);
        $patch['edits'][0]=['block_id'=>'p2','operation'=>'REPLACE_TEXT','find'=>'absent text','text'=>'replacement','citations'=>[]];
        try{\App\Services\DraftRepair::apply($draft,$patch);$this->fail('Invalid replacement accepted');}
        catch(AiProviderException $error){$this->assertSame('REPAIR_INVALID',$error->reason);}
    }

    public function test_saved_draft_quality_correction_reuses_plan_and_research_and_keeps_the_original_version(): void
    {
        $parent=$this->start();$this->workflow()->execute($parent);$original=ContentVersion::first();
        $id=$this->login($this->writer)->postJson('/api/runs/'.$parent->id.'/revise',['idempotency_key'=>(string)Str::uuid()])->assertStatus(202)->json('run.id');
        $child=AgentRun::findOrFail($id);$calls=[];
        $this->workflow(function($run,$stage)use(&$calls){$calls[]=$stage;return null;})->execute($child);
        $this->assertSame(['quality'],$calls);
        $this->assertSame('SUCCEEDED',$child->fresh()->status);
        $this->assertSame($this->fact,$original->fresh()->body_markdown);
        $this->assertDatabaseCount('content_versions',2);
        $this->assertSame(0,$child->steps()->where('step_type','WRITE')->where('status','SUCCEEDED')->count());
    }

    public function test_continue_preserves_an_already_verified_candidate_without_more_model_edits(): void
    {
        $parent=$this->start();
        foreach(['PLAN','RESEARCH'] as $type)$parent->steps()->where('step_type',$type)->update(['status'=>'SUCCEEDED','artifacts'=>json_encode($this->stageOutput(strtolower($type)))]);
        $draft=$this->stageOutput('write');
        $parent->steps()->where('step_type','QUALITY')->update(['status'=>'SUCCEEDED','artifacts'=>json_encode(['needs_revision'=>true,'findings'=>[],'gate_findings'=>[],'candidate'=>$draft,'measurements'=>\App\Services\GenerationQuality::measurements($draft)])]);
        GenerationWorkflow::finish($parent->id,'FAILED','QUALITY_FAILED','A legacy extra correction damaged a later candidate.');
        $id=$this->login($this->writer)->postJson('/api/runs/'.$parent->id.'/continue',['idempotency_key'=>(string)Str::uuid()])->assertStatus(202)->json('run.id');
        $gateway=Mockery::mock(AiGateway::class)->shouldNotReceive('generate')->getMock();$fetcher=Mockery::mock(PublicSourceFetcher::class)->shouldNotReceive('fetch')->getMock();
        $child=AgentRun::findOrFail($id);(new GenerationWorkflow($gateway,$fetcher,new SourceExtractor))->execute($child);
        $this->assertSame('SUCCEEDED',$child->fresh()->status);$this->assertSame($this->fact,ContentVersion::first()->body_markdown);
        $this->assertSame(0,$child->steps()->where('step_type','REVISE')->count());$this->assertSame('FAILED',$parent->fresh()->status);
    }

    public function test_thinking_budget_recovery_rotates_only_the_failed_stage_and_counts_failed_usage(): void
    {
        $profile=AiSetting::current();$profile->update(['model'=>'openrouter/free','strict_json'=>true]);
        $catalog=[];foreach(['safe/a:free','safe/b:free'] as $id)$catalog[]=['id'=>$id,'pricing'=>['prompt'=>'0','completion'=>'0'],'reasoning'=>['mandatory'=>false],'supported_parameters'=>['reasoning','response_format','structured_outputs']];
        Cache::put(\App\Services\OpenRouterThinking::CATALOG_KEY,$catalog,300);
        $run=$this->start();$routes=[];$stages=[];
        Http::fake(function($request)use(&$routes,&$stages){
            preg_match('/You are the (\w+) stage/',$request['messages'][0]['content'],$match);$stage=$match[1];$routes[]=$request['model'];$stages[]=$stage;
            if(count($routes)===1)return Http::response(['model'=>'safe/a:free','choices'=>[['message'=>['content'=>''],'finish_reason'=>'length']],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>2000,'total_tokens'=>2100,'completion_tokens_details'=>['reasoning_tokens'=>2000]]]);
            $output=$this->stageOutput($stage);
            if($stage==='research')$output['notes'][0]['sources']=[['passage_id'=>'q1']];
            if($stage==='write')$output['blocks'][0]['citations']=[['passage_id'=>'q1']];
            return Http::response(['model'=>'safe/b:free','choices'=>[['message'=>['content'=>json_encode($output)],'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>20,'completion_tokens'=>10,'total_tokens'=>30,'completion_tokens_details'=>['reasoning_tokens'=>0]]]);
        });
        $fetcher=Mockery::mock(PublicSourceFetcher::class)->shouldNotReceive('fetch')->getMock();
        (new GenerationWorkflow(new AiGateway,$fetcher,new SourceExtractor))->execute($run);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $this->assertSame(['plan','plan','research','write','quality'],$stages);
        $this->assertSame(['safe/a:free','safe/b:free','safe/b:free','safe/b:free','safe/b:free'],$routes);
        $this->assertSame(2220,$run->fresh()->provider_usage['total_tokens']);
        $this->assertSame(2000,$run->fresh()->provider_usage['reasoning_tokens']);
        $this->assertDatabaseCount('content_versions',1);
    }

    public function test_thinking_recovery_increases_answer_space_and_time_without_restarting_completed_stages(): void
    {
        $profile=AiSetting::current();$profile->update(['limits'=>[...$profile->limits,'max_output_tokens'=>16000,'max_tokens'=>100000,'duration_seconds'=>1800]]);
        $run=$this->start();$planAttempts=0;$caps=[];$timeouts=[];
        $gateway=Mockery::mock(AiGateway::class);
        $gateway->shouldReceive('generate')->andReturnUsing(function($run,$stage,$schema,$context,$timeout,$maxOutput)use(&$planAttempts,&$caps,&$timeouts){
            if($stage==='plan'){$caps[]=$maxOutput;$timeouts[]=$timeout;if(++$planAttempts<3)throw new AiProviderException('THINKING_LIMIT','Thinking used the answer allowance.',true,['output_tokens'=>$maxOutput,'reasoning_tokens'=>$maxOutput,'total_tokens'=>$maxOutput,'thinking_incomplete'=>true]);}
            return ['output'=>$this->stageOutput($stage),'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]];
        });
        $fetcher=Mockery::mock(PublicSourceFetcher::class)->shouldNotReceive('fetch')->getMock();
        (new GenerationWorkflow($gateway,$fetcher,new SourceExtractor))->execute($run);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $this->assertSame([2000,8192,16000],$caps);$this->assertSame([240,360,360],$timeouts);
        $this->assertSame(1,$run->steps()->where('step_type','RESEARCH')->count());
        $this->assertSame(10312,$run->fresh()->provider_usage['total_tokens']);
    }

    public function test_continue_after_thinking_exhaustion_reuses_research_and_starts_with_a_larger_write_allowance(): void
    {
        $parent=$this->start();
        $this->workflow(function($run,$stage){if($stage==='write')throw new AiProviderException('OUTPUT_LIMIT','Thinking used the answer allowance.',false,['output_tokens'=>8000,'reasoning_tokens'=>8000,'total_tokens'=>8000]);return null;})->execute($parent);
        $profile=AiSetting::current();$profile->update(['limits'=>[...$profile->limits,'max_output_tokens'=>16000,'duration_seconds'=>1800]]);
        $id=$this->login($this->writer)->postJson('/api/runs/'.$parent->id.'/continue',['idempotency_key'=>(string)Str::uuid()])->assertStatus(202)->json('run.id');
        $continued=AgentRun::findOrFail($id);$this->assertSame(16000,$continued->input_snapshot['recovery_output']['WRITE:0']);
        $calls=[];$gateway=Mockery::mock(AiGateway::class);
        $gateway->shouldReceive('generate')->andReturnUsing(function($run,$stage,$schema,$context,$timeout,$maxOutput)use(&$calls){$calls[]=$stage;if($stage==='write'){$this->assertSame(16000,$maxOutput);$this->assertSame(360,$timeout);}return ['output'=>$this->stageOutput($stage),'usage'=>['total_tokens'=>30]];});
        $fetcher=Mockery::mock(PublicSourceFetcher::class)->shouldNotReceive('fetch')->getMock();
        (new GenerationWorkflow($gateway,$fetcher,new SourceExtractor))->execute($continued);
        $this->assertSame(['write','quality'],$calls);$this->assertSame('SUCCEEDED',$continued->fresh()->status);
        $this->assertDatabaseCount('content_versions',1);
    }

    public function test_continuation_recovers_overwritten_ancestor_checkpoints_and_only_calls_quality(): void
    {
        $parent=$this->start();
        $this->workflow(function($run,$stage){if($stage==='quality') throw new AiProviderException('CONNECTION','Provider disconnected.');return null;})->execute($parent);
        $body=['idempotency_key'=>(string)Str::uuid()];
        $url='/api/runs/'.$parent->id.'/continue';
        $this->login($this->reviewer)->postJson($url,$body)->assertForbidden();
        $response=$this->login($this->writer)->postJson($url,$body)->assertStatus(202);
        $child=AgentRun::findOrFail($response->json('run.id'));
        $this->postJson($url,$body)->assertStatus(202)->assertJsonPath('run.id',$child->id);
        $child->steps()->where('step_type','RESEARCH')->update(['status'=>'FAILED','artifacts'=>null]);
        GenerationWorkflow::finish($child->id,'FAILED','CONNECTION','Simulated old worker overwrote research.');
        $id=$this->postJson('/api/runs/'.$child->id.'/continue',['idempotency_key'=>(string)Str::uuid()])->assertStatus(202)->json('run.id');
        $continued=AgentRun::findOrFail($id);
        $this->assertSame('REUSED',$continued->steps()->where('step_type','RESEARCH')->first()->status);
        $gateway=Mockery::mock(AiGateway::class);
        $gateway->shouldReceive('generate')->once()->withArgs(function($run,$stage,$schema,$context){
            $this->assertSame('quality',$stage);
            $this->assertArrayNotHasKey('source_snapshots',$context['research']);
            $this->assertArrayNotHasKey('retrieval_warnings',$context['research']);
            return true;
        })->andReturn(['output'=>$this->stageOutput('quality'),'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]]);
        $fetcher=Mockery::mock(PublicSourceFetcher::class)->shouldNotReceive('fetch')->getMock();
        (new GenerationWorkflow($gateway,$fetcher,new SourceExtractor))->execute($continued);
        $this->assertSame('SUCCEEDED',$continued->fresh()->status);
        $this->assertSame('FAILED',$parent->fresh()->status);
        $this->assertSame(30,$continued->fresh()->provider_usage['total_tokens']);
        $this->assertDatabaseCount('content_versions',1);
        $this->assertSame(3,$continued->steps()->where('status','REUSED')->count());
    }

    public function test_continuation_finishes_the_latest_written_revision_instead_of_saving_an_older_draft(): void
    {
        $profile=AiSetting::current();$profile->update(['limits'=>[...$profile->limits,'revision_passes'=>1]]);
        $parent=$this->start();$writes=0;$checks=0;
        $this->workflow(function($run,$stage)use(&$writes,&$checks){
            if($stage==='write'){$draft=$this->stageOutput('write');$draft['title']='Draft '.(++$writes);return ['output'=>$draft,'usage'=>['total_tokens'=>30]];}
            if($stage==='revise')return ['output'=>['edits'=>[],'metadata_edits'=>[['field'=>'title','value'=>'Draft 2']]],'usage'=>['total_tokens'=>30]];
            if($stage==='quality'){if(++$checks===2)throw new AiProviderException('CONNECTION','Disconnected before final review.');return ['output'=>['needs_revision'=>true,'findings'=>[['code'=>'TONE','is_violation'=>true,'severity'=>'HARD','message'=>'Tone requires correction.','block_id'=>'p1']]],'usage'=>['total_tokens'=>30]];}
            return null;
        })->execute($parent);
        $oldQuality=$parent->steps()->where('step_type','QUALITY')->where('pass_no',0)->first();
        $oldQuality->update(['artifacts'=>['needs_revision'=>false,'findings'=>[]]]);
        $id=$this->login($this->writer)->postJson('/api/runs/'.$parent->id.'/continue',['idempotency_key'=>(string)Str::uuid()])->assertStatus(202)->json('run.id');
        $called=[];
        $this->workflow(function($run,$stage)use(&$called){$called[]=$stage;return null;})->execute(AgentRun::findOrFail($id));
        $this->assertSame(['quality'],$called);
        $this->assertSame('Draft 2',ContentVersion::first()->title);
    }

    public function test_heading_only_blog_draft_is_rejected_instead_of_promoted(): void
    {
        $this->brief->update(['content_type'=>'BLOG','instructions'=>[...$this->brief->instructions,'target_words'=>300]]);
        $run=$this->start();
        $this->workflow(function($run,$stage) {
            if ($stage==='write') {
                $output=$this->stageOutput('write');
                $output['blocks']=[['id'=>'h1','kind'=>'HEADING','text'=>'Introduction','citations'=>[]]];
                return ['output'=>$output,'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]];
            }
            return null;
        })->execute($run);
        $this->assertSame('OUTPUT_INVALID',$run->fresh()->error_code);
        $this->assertStringContainsString('outline',$run->fresh()->error_message);
        $this->assertDatabaseCount('content_versions',0);
    }

    public function test_expired_worker_runs_and_quotas_are_recoverable(): void
    {
        $p=AiSetting::current(); $p->update(['limits'=>[...$p->limits,'user_daily_runs'=>1]]);
        $run=$this->start(); $run->forceFill(['status'=>'RUNNING','started_at'=>now()->subMinutes(11),'created_at'=>now()->subMinutes(11)])->save();
        GenerationWorkflow::recoverExpired();
        $this->assertSame('TIME_LIMIT',$run->fresh()->error_code); $this->assertNull($run->fresh()->active_brief_id);
        $this->login($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/runs',$this->payload())->assertStatus(429);
    }

    public function test_waiting_for_a_worker_does_not_consume_the_generation_execution_budget(): void
    {
        $run=$this->start();$run->forceFill(['created_at'=>now()->subHours(2)])->save();
        GenerationWorkflow::recoverExpired();
        $this->assertSame('QUEUED',$run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
        $this->workflow()->execute($run);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $this->assertTrue($run->fresh()->started_at->greaterThan(now()->subMinute()));
        $this->assertDatabaseCount('content_versions',1);
    }

    public function test_generation_reports_the_selected_provider_and_exhausted_app_allowance(): void
    {
        $profile=AiSetting::current();$profile->update(['limits'=>[...$profile->limits,'user_daily_runs'=>1]]);
        $run=$this->start();
        $this->workflow(function($run,$stage){throw new AiProviderException('PROVIDER_TIMEOUT','Timed out.');})->execute($run);
        $this->login($this->writer)->getJson('/api/briefs/'.$this->brief->id.'/generation')->assertOk()
            ->assertJsonPath('default_provider','openrouter')->assertJsonPath('profiles.0.daily_runs_used',1)->assertJsonPath('profiles.0.daily_run_limit',1);
        $this->postJson('/api/briefs/'.$this->brief->id.'/runs',$this->payload())->assertStatus(429)->assertJsonPath('message','Your daily generation limit has been reached.');
        $this->assertDatabaseCount('agent_runs',1);
    }

    public function test_admin_provider_keys_are_encrypted_hidden_and_separate(): void
    {
        $url='/api/admin/ai-settings/gemini'; $p=AiSetting::current('gemini');
        $payload=['version'=>$p->version,'model'=>'test-gemini','api_key'=>'private-test-gemini-key','enabled'=>true,'endpoint'=>'http://127.0.0.1:11434','strict_json'=>false,'free_only'=>true,'limits'=>config('generation.limits')];
        $this->login($this->writer)->putJson($url,$payload)->assertForbidden();
        $this->login($this->admin)->putJson($url,$payload)->assertOk()->assertJsonMissingPath('profile.api_key');
        $this->getJson('/api/admin/ai-settings')->assertOk()->assertJsonMissingPath('profiles.0.api_key')->assertJsonMissingPath('profiles.1.api_key');
        $this->assertNotSame('private-test-gemini-key',DB::table('ai_settings')->where('id',$p->id)->value('api_key'));
        $this->assertSame('unit-test-provider-key',AiSetting::current()->key());
        $this->assertSame('private-test-gemini-key',AiSetting::current('gemini')->key());
        $this->putJson($url,$payload)->assertStatus(409);
    }

    public function test_key_can_be_saved_without_model_and_unsaved_key_can_load_models(): void
    {
        $p=AiSetting::current('gemini');
        $payload=['version'=>$p->version,'model'=>'','api_key'=>'key-before-model','enabled'=>true,'endpoint'=>'http://127.0.0.1:11434','strict_json'=>false,'free_only'=>true,'limits'=>config('generation.limits')];
        $this->login($this->admin)->putJson('/api/admin/ai-settings/gemini',$payload)->assertOk()->assertJsonPath('profile.model','');
        $this->assertSame('key-before-model',AiSetting::current('gemini')->key());
        Http::fake(['generativelanguage.googleapis.com/v1beta/models*'=>Http::response(['models'=>[['name'=>'models/test-gemini','displayName'=>'Test Gemini','supportedGenerationMethods'=>['generateContent']]]])]);
        $this->postJson('/api/admin/ai-settings/gemini/models',['api_key'=>'unsaved-lookup-key'])->assertOk()->assertJsonPath('models.0.id','test-gemini');
        Http::assertSent(fn($r)=>$r->hasHeader('x-goog-api-key','unsaved-lookup-key'));
        $this->assertSame('key-before-model',AiSetting::current('gemini')->key());
        $this->login($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/runs',$this->payload(['provider'=>'gemini']))->assertUnprocessable();
    }

    public function test_huggingface_profile_keeps_encrypted_token_and_forces_free_only_mode(): void
    {
        $profile=AiSetting::current('huggingface');
        $payload=['version'=>$profile->version,'model'=>'org/free-model:free-host','api_key'=>'hf-private-test-token','enabled'=>true,
            'endpoint'=>'http://127.0.0.1:11434','strict_json'=>true,'free_only'=>false,'limits'=>config('generation.limits')];
        $this->login($this->admin)->putJson('/api/admin/ai-settings/huggingface',$payload)->assertUnprocessable();
        $payload['free_only']=true;
        $this->putJson('/api/admin/ai-settings/huggingface',$payload)->assertOk()->assertJsonMissingPath('profile.api_key')->assertJsonPath('profile.free_only',true)->assertJsonPath('profile.strict_json',false);
        $this->assertNotSame('hf-private-test-token',DB::table('ai_settings')->where('id',$profile->id)->value('api_key'));
        $this->assertSame('hf-private-test-token',AiSetting::current('huggingface')->key());
        $this->getJson('/api/admin/ai-settings')->assertOk()->assertJsonMissingPath('profiles.3.api_key');
        $this->login($this->writer)->postJson('/api/briefs/'.$this->brief->id.'/runs',$this->payload(['provider'=>'huggingface']))->assertStatus(202);
        Queue::assertPushed(GenerateContent::class,1);
    }

    public function test_public_web_mode_retrieves_and_snapshots_only_validated_pages(): void
    {
        Storage::fake('sources');
        $this->brief->update(['instructions'=>[...$this->brief->instructions,'research_mode'=>'PUBLIC_WEB']]);
        $fetcher=Mockery::mock(PublicSourceFetcher::class)->shouldReceive('fetch')->once()->with('https://example.com/reference')->andReturn(['url'=>'https://example.com/reference','mime'=>'text/plain','body'=>$this->fact])->getMock();
        $run=$this->start();
        $this->workflow(function($run,$stage) { if ($stage==='plan') return ['output'=>['summary'=>'Use references','outline'=>['Service details'],'research_urls'=>['https://example.com/reference']],'usage'=>['input_tokens'=>20,'output_tokens'=>10,'total_tokens'=>30]]; return null; },$fetcher)->execute($run);
        $this->assertSame('SUCCEEDED',$run->fresh()->status);
        $source=Source::where('discovered_by_run_id',$run->id)->firstOrFail();
        $this->assertSame('READY',$source->parse_status); $this->assertSame($run->brief_id,$source->brief_id);
        $this->assertSame(hash('sha256',$this->fact),$source->content_hash);
        Storage::disk('sources')->assertExists($source->storage_key);
        $research=AgentStep::where('run_id',$run->id)->where('step_type','RESEARCH')->first();
        $this->assertCount(2,$research->artifacts['source_snapshots']);
    }
}
