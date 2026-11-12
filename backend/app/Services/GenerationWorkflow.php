<?php
namespace App\Services;

use App\Models\{AgentRun, AgentStep, AuditEvent, Citation, Client, ContentBrief, ContentVersion, Project, Source};
use App\Support\WorkspaceAccess;
use Illuminate\Support\Facades\{DB, Storage};

class GenerationWorkflow
{
    public function __construct(private AiGateway $gateway, private PublicSourceFetcher $fetcher, private SourceExtractor $extractor) {}

    public static function recoverExpired(): void
    {
        foreach (AgentRun::where('status','RUNNING')->get() as $run) {
            if (now()->timestamp-($run->started_at??$run->created_at)->timestamp >= $run->limits_snapshot['duration_seconds']) self::finish($run->id,'FAILED','TIME_LIMIT','The run exceeded its time allowance. Continue this run to reuse completed stages.');
        }
    }

    private static function locks(AgentRun $run): array
    {
        $brief=ContentBrief::findOrFail($run->brief_id);
        $project=Project::findOrFail($brief->project_id);
        $client=Client::lockForUpdate()->findOrFail($project->client_id);
        $project=Project::lockForUpdate()->findOrFail($project->id);
        $brief=ContentBrief::lockForUpdate()->findOrFail($brief->id);
        $run=AgentRun::lockForUpdate()->findOrFail($run->id);
        return [$run,$brief,$project,$client];
    }

    public static function finish(string $id, string $status, string $code, string $message): void
    {
        $original=AgentRun::find($id); if (!$original) return;
        DB::transaction(function () use ($original,$status,$code,$message) {
            [$run,$brief]=self::locks($original);
            if (!$run->active()) return;
            $run->update(['status'=>$status,'active_brief_id'=>null,'error_code'=>$code,'error_message'=>$message,'finished_at'=>now(),
                ...($status==='CANCELLED'?['cancel_requested_at'=>now()]:[])]);
            AgentStep::where('run_id',$run->id)->where('status','PENDING')->update(['status'=>'SKIPPED','finished_at'=>now()]);
            if ($status==='FAILED') AgentStep::where('run_id',$run->id)->where('status','RUNNING')->update(['status'=>'FAILED','finished_at'=>now(),'error_message'=>$message]);
            if ($brief->status==='IN_PROGRESS') $brief->update(['status'=>$run->previous_brief_status,'version'=>$brief->version+1]);
            AuditEvent::record($run->requester,'run.'.strtolower($status),'RUN',$run->id,['brief_id'=>$brief->id,'code'=>$code]);
        });
    }

    private function check(AgentRun $original): AgentRun
    {
        return DB::transaction(function () use ($original) {
            [$run,$brief,$project,$client]=self::locks($original);
            if (!$run->active() || $run->cancel_requested_at) throw new AiProviderException('CANCELLED','This run has ended or was cancelled.');
            if (!WorkspaceAccess::write($run->requester,$client) || $client->is_archived || $project->is_archived || $brief->is_archived) throw new AiProviderException('ACCESS_CHANGED','The requester lost edit access or the workspace was archived. Restore access and start a new run.');
            if (now()->timestamp-($run->started_at??$run->created_at)->timestamp >= $run->limits_snapshot['duration_seconds']) throw new AiProviderException('TIME_LIMIT','The run exceeded its time allowance. Continue this run to reuse completed stages.');
            return $run;
        });
    }

    public function execute(AgentRun $original): void
    {
        try {
            $claimed=DB::transaction(function () use ($original) {
                [$run]=self::locks($original);
                if ($run->status!=='QUEUED') return false;
                $run->update(['status'=>'RUNNING','started_at'=>now()]); return true;
            });
            if (!$claimed) return;
            $run=$this->check($original);
            $brief=$run->input_snapshot['brief'];
            $requirements=GenerationQuality::rules($brief);
            $plan=$this->checkpoint($run,'PLAN',0)??$this->stage($run,'PLAN',0,['brief'=>$brief,'task'=>'Create a concise outline. In PUBLIC_WEB mode propose at most 3 useful public page URLs; these are suggestions, not verified sources. In PROVIDED_ONLY mode research_urls must be empty.']);
            $sources=$run->input_snapshot['sources'];
            $warnings=[];
            $researchCheckpoint=$this->checkpoint($run,'RESEARCH',0);
            if (!$researchCheckpoint && ($brief['instructions']['research_mode']??'PROVIDED_ONLY')==='PUBLIC_WEB') {
                [$discovered,$warnings]=$this->discover($run,$plan['research_urls']);
                $sourceIds=array_merge(array_column($sources,'id'),array_map(fn($source)=>$source->id,$discovered));
                $sources=GenerationInput::sources(Source::where('brief_id',$run->brief_id)->whereIn('id',$sourceIds)->get(),$run->limits_snapshot['context_characters']);
            }
            if ($researchCheckpoint && !empty($researchCheckpoint['source_snapshots'])) $sources=$researchCheckpoint['source_snapshots'];
            if (!collect($sources)->contains(fn($s)=>count($s['segments'])>0)) throw new AiProviderException('MISSING_EVIDENCE','No usable evidence is available. Attach a ready reference or provide a working public source.');
            $research=$researchCheckpoint??$this->stage($run,'RESEARCH',0,['brief'=>$brief,'outline'=>$plan,'sources'=>$sources,'retrieval_warnings'=>$warnings,
                'task'=>'Extract at most 12 concise facts needed for this brief, with one or two citations per fact. Do not repeat facts or list every detail in the reference. Set evidence_sufficient to true when those facts support the essential requested topic. Set it to false only for a specific missing fact needed by the brief. missing_information must list actual missing facts in plain language, never JSON field names. If sufficient, missing_information must be an empty array. A single directly relevant source can be sufficient.']);
            if (!empty($research['source_snapshots'])) $sources=$research['source_snapshots'];
            $this->validateEvidence($research['notes'],$sources);
            if (!$research['evidence_sufficient'] || !$research['notes']) throw new AiProviderException('MISSING_EVIDENCE','More reference information is needed: '.mb_substr(implode('; ',$research['missing_information']),0,350));
            if (!$researchCheckpoint) $this->addResearchSources($run,$sources,$warnings);
            // Stored reference snapshots belong to history, not subsequent model prompts.
            unset($research['source_snapshots'],$research['retrieval_warnings']);
            $revision=$run->input_snapshot['quality_retry']['findings']??[];
            $previousDraft=$run->input_snapshot['quality_retry']['draft']??null;
            for ($pass=0;$pass<=$run->limits_snapshot['revision_passes'];$pass++) {
                $draft=$this->checkpoint($run,'WRITE',$pass);
                $savedPatch=$this->checkpoint($run,'REVISE',$pass);
                if (!$draft && !$savedPatch && $previousDraft!==null && !collect($revision)->contains(fn($finding)=>$finding['severity']==='HARD')) $draft=$previousDraft;
                if (!$draft && $previousDraft!==null) {
                    $patch=$savedPatch??$this->stage($run,'REVISE',$pass,['brief'=>$brief,'draft'=>$previousDraft,'research'=>$research,'requirements'=>$requirements,'measurements'=>GenerationQuality::measurements($previousDraft),'revision_findings'=>$revision,
                        'task'=>'Correct the supplied draft using only small targeted edits for the reported violations. Do not return a replacement draft or rewrite unaffected paragraphs. Preserve existing block IDs, order, facts and wording unless an edit is needed. REPLACE_TEXT find must match one exact occurrence in the current block. PREPEND_TEXT and APPEND_TEXT use empty find and exact added text including spaces. SET_KIND text is the new kind. SET_CITATIONS replaces only that block citation list using supported passages. REMOVE_BLOCK removes an unnecessary heading. Use empty citations for other operations. Return metadata_edits only for metadata that needs correction. After edits, the entire caption must meet the exact length, opening, keyword, citation and final CTA requirements.']);
                    try { $draft=DraftRepair::apply($previousDraft,$patch); }
                    catch (AiProviderException $error) {
                        AgentStep::where('run_id',$run->id)->where('step_type','REVISE')->where('pass_no',$pass)->whereIn('status',['SUCCEEDED','REUSED'])->update(['status'=>'FAILED','error_message'=>$error->getMessage()]);
                        throw $error;
                    }
                }
                if (!$draft) $draft=$this->stage($run,'WRITE',$pass,['brief'=>$brief,'plan'=>$plan,'research'=>$research,'requirements'=>$requirements,
                    'task'=>'Write one complete deliverable satisfying every supplied requirement. For social captions, obey the exact character range, prefix, primary keyword count, final CTA and citation count; do not add article headings. Count only visible block text, not citations or metadata. Cite factual blocks using supplied evidence labels. For blogs include substantive paragraphs under headings. Fill applicable email/SEO metadata; other metadata must be empty strings.']);
                $this->validateDraft($draft,$sources);
                $measurements=GenerationQuality::measurements($draft);
                $quality=$this->checkpoint($run,'QUALITY',$pass)??$this->stage($run,'QUALITY',$pass,['brief'=>$brief,'draft'=>$draft,'research'=>$research,'requirements'=>$requirements,'measurements'=>$measurements,
                    'task'=>'Review actual violations of the saved brief only. Use the supplied exact measurements; never estimate character/word/citation counts. Return at most 8 actionable findings. Set is_violation=false for any check that passed; passing checks are not HARD issues. Do not invent requirements, such as requiring an optional warranty detail absent from the brief. Check factual support and required talking points. Optional style suggestions are ADVISORY. Set needs_revision=false when no essential violation remains.']);
                $findings=$this->findings($brief,$draft,$quality,$sources);
                $qualityStep=AgentStep::where('run_id',$run->id)->where('step_type','QUALITY')->where('pass_no',$pass)->whereIn('status',['SUCCEEDED','REUSED'])->latest()->first();
                if ($qualityStep) $qualityStep->update(['artifacts'=>[...$quality,'gate_findings'=>$findings,'measurements'=>$measurements,'candidate'=>$draft]]);
                $needsRevision=collect($findings)->contains(fn($finding)=>$finding['severity']==='HARD');
                $laterDraft=AgentStep::where('run_id',$run->id)->whereIn('step_type',['WRITE','REVISE'])->where('status','REUSED')->where('pass_no','>',$pass)->exists();
                if ((!$needsRevision && !$laterDraft) || $pass===$run->limits_snapshot['revision_passes']) break;
                $revision=$findings;
                $previousDraft=$draft;
            }
            if (collect($findings)->contains(fn($finding)=>$finding['severity']==='HARD')) throw new AiProviderException('QUALITY_FAILED','Required quality checks still fail after the allowed revisions. The candidate and findings are retained; no new successful draft version was saved.');
            $this->save($run,$draft,$findings,$sources);
        } catch (AiProviderException $e) {
            self::finish($original->id,$e->reason==='CANCELLED'?'CANCELLED':'FAILED',$e->reason,$e->getMessage());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Generation failed',['run_id'=>$original->id,'exception_type'=>$e::class]);
            self::finish($original->id,'FAILED','PROCESSING_ERROR','Generation could not finish safely. Your earlier versions are preserved. Retry or ask the administrator to check the worker.');
        }
    }

    private function checkpoint(AgentRun $run,string $stage,int $pass): ?array
    {
        if (empty($run->input_snapshot['continuation_from_run_id'])) return null;
        $step=AgentStep::where('run_id',$run->id)->where('step_type',$stage)->where('pass_no',$pass)->where('status','REUSED')->first();
        return $step?->artifacts;
    }

    private function stage(AgentRun $original,string $stage,int $pass,array $context): array
    {
        $schema=GenerationSchema::stage($stage);
        $resumedOutput=(int)($original->input_snapshot['recovery_output'][$stage.':'.$pass]??0);
        $thinkingRecoveries=$resumedOutput>0?1:0;$timeoutRecoveries=0;
        for ($attempt=0;$attempt<=2;$attempt++) {
            $run=$this->check($original);
            $remaining=$run->limits_snapshot['max_tokens']-($run->provider_usage['total_tokens']??0);
            // JSON bytes substantially overstate model tokens. Reserve a conservative
            // four bytes per token so large quality contexts do not exhaust the run early.
            $inputBytes=strlen(json_encode($context))+strlen(json_encode($schema))+3000;
            $inputReserve=(int)ceil($inputBytes/4);
            $controlled=($run->input_snapshot['provider']['name']??'')==='openrouter' && ($run->input_snapshot['provider']['thinking_control']??false);
            $stageLimit=($run->input_snapshot['provider']['name']??'')==='openrouter' && !$controlled ? $run->limits_snapshot['max_output_tokens'] : match($stage){'PLAN'=>$controlled?2000:1500,'RESEARCH'=>$controlled?4000:3500,'QUALITY'=>2500,default=>($controlled && ($run->input_snapshot['brief']['content_type']??'')==='SOCIAL'?3000:16000)};
            $attemptOutput=$thinkingRecoveries>0?max($stageLimit,$resumedOutput,8192*(2**($thinkingRecoveries-1))):$stageLimit;
            $maxOutput=min($run->limits_snapshot['max_output_tokens'],$attemptOutput,$remaining-$inputReserve);
            if ($maxOutput<512) throw new AiProviderException('USAGE_LIMIT','The run reached its usage allowance. Reduce source context or increase the configured token allowance.');
            $callTimeout=$controlled?($thinkingRecoveries>0 || $timeoutRecoveries>0?360:240):180;
            $timeout=max(1,min($callTimeout,$run->limits_snapshot['duration_seconds']-(now()->timestamp-($run->started_at??$run->created_at)->timestamp)));
            $step=AgentStep::firstOrCreate(['run_id'=>$run->id,'step_type'=>$stage,'pass_no'=>$pass,'attempt_no'=>$attempt]);
            $step->update(['status'=>'RUNNING','started_at'=>now(),'artifacts'=>null,'error_message'=>null,'finished_at'=>null]);
            $usage=[];
            try {
                $result=$this->gateway->generate($run,strtolower($stage),$schema,$context,$timeout,$maxOutput);
                $usage=$result['usage'];
                if (($usage['total_tokens']??0)<1) $usage=['input_tokens'=>$inputReserve,'output_tokens'=>$maxOutput,'total_tokens'=>$inputReserve+$maxOutput,'estimated'=>true];
                $this->usage($run,$usage);
                GenerationSchema::validate($result['output'],$schema);
                $step->update(['status'=>'SUCCEEDED','artifacts'=>$result['output'],'provider_usage'=>$usage,'finished_at'=>now()]);
                $this->check($run);
                if (($run->fresh()->provider_usage['total_tokens']??0)>$run->limits_snapshot['max_tokens']) throw new AiProviderException('USAGE_LIMIT','The model exceeded the run usage allowance. No new version was promoted.');
                return $result['output'];
            } catch (AiProviderException $e) {
                if (!$usage && $e->usage) { $usage=$e->usage; $this->usage($run,$usage); }
                $step->update(['status'=>'FAILED','error_message'=>$e->getMessage(),'provider_usage'=>$usage,'finished_at'=>now()]);
                if ($e->reason==='THINKING_LIMIT') $thinkingRecoveries++;
                if ($e->reason==='PROVIDER_TIMEOUT' && ++$timeoutRecoveries>1) throw $e;
                if (!$e->transient||$attempt===2) throw $e;
                $this->check($run); sleep(1<<$attempt);
            }
        }
        throw new AiProviderException('RETRIES_EXHAUSTED','The provider retry allowance was exhausted.');
    }

    private function usage(AgentRun $run,array $usage): void
    {
        DB::transaction(function () use ($run,$usage) {
            $locked=AgentRun::lockForUpdate()->findOrFail($run->id); $total=$locked->provider_usage??[];
            foreach (['input_tokens','output_tokens','total_tokens'] as $key) $total[$key]=($total[$key]??0)+($usage[$key]??0);
            if (isset($usage['reasoning_tokens'])) $total['reasoning_tokens']=($total['reasoning_tokens']??0)+$usage['reasoning_tokens'];
            if ($usage['estimated']??false) $total['includes_estimates']=true;
            $locked->update(['provider_usage'=>$total]);
        });
    }

    private function discover(AgentRun $run,array $urls): array
    {
        $discovered=[]; $warnings=[];
        foreach (array_slice(array_unique($urls),0,$run->limits_snapshot['search_results']) as $url) {
            $this->check($run);
            if (Source::where('brief_id',$run->brief_id)->where('is_archived',false)->where('source_type','URL')->whereIn('parse_status',['PENDING','READY'])->count()>=10) { $warnings[]='The website-source quota was reached.'; break; }
            if (Source::where('brief_id',$run->brief_id)->where('created_at','>=',now()->startOfDay())->count()>=100) { $warnings[]='The daily source quota was reached.'; break; }
            $source=null;
            try {
                $page=$this->fetcher->fetch($url);
                $result=$this->extractor->web($page['body'],$page['mime']);
                $source=DB::transaction(function () use ($run,$url,$page,$result) {
                    $this->check($run);
                    return Source::create(['brief_id'=>$run->brief_id,'created_by'=>$run->requested_by,'source_type'=>'URL','title'=>($result['title']??'')?:parse_url($page['url'],PHP_URL_HOST),
                        'requested_url'=>$url,'final_url'=>$page['url'],'mime_type'=>$page['mime'],'byte_size'=>strlen($page['body']),'parse_status'=>'PENDING','scan_status'=>'NOT_REQUIRED','discovered_by_run_id'=>$run->id]);
                });
                $disk=Storage::disk('sources'); $disk->makeDirectory($source->id);
                if (disk_free_space($disk->path(''))<33554432+strlen($page['body'])) throw new \RuntimeException('Source storage is low.');
                $key=$source->id.'/snapshot.bin'; $disk->put($key,$page['body']);
                DB::transaction(function () use ($run,$source,$key,$page,$result) {
                    $this->check($run);
                    $source->update(['storage_key'=>$key,'parse_status'=>'READY','extracted_text'=>$result['text'],'segments'=>$result['segments'],'content_hash'=>hash('sha256',$page['body']),'retrieved_at'=>now()]);
                    AuditEvent::record($run->requester,'source.discovered','SOURCE',$source->id,['run_id'=>$run->id,'brief_id'=>$run->brief_id]);
                });
                $discovered[]=$source->fresh();
            } catch (\Throwable $error) {
                if ($source) { Storage::disk('sources')->deleteDirectory($source->id); $source->update(['parse_status'=>'REJECTED','storage_key'=>null,'error_message'=>'The suggested page could not be retrieved safely.']); }
                if ($error instanceof AiProviderException) throw $error;
                $warnings[]='A suggested page was unavailable or failed source safety checks.';
            }
        }
        return [$discovered,$warnings];
    }

    private function addResearchSources(AgentRun $run,array $sources,array $warnings): void
    {
        $step=AgentStep::where('run_id',$run->id)->where('step_type','RESEARCH')->where('status','SUCCEEDED')->latest()->firstOrFail();
        $step->update(['artifacts'=>$step->artifacts+['source_snapshots'=>$sources,'retrieval_warnings'=>$warnings]]);
    }

    private function citation(array $citation,array $sources): array
    {
        foreach ($sources as $source) if ($source['id']===$citation['source_id']) {
            foreach ($source['segments'] as $segment) if ($segment['ordinal']===$citation['segment_ordinal']) {
                $quote=trim($citation['supporting_excerpt']);
                $normal=fn($text)=>preg_replace('/\s+/u',' ',mb_strtolower($text));
                if (mb_strlen($quote)<10 || !str_contains($normal($segment['text']),$normal($quote))) throw new AiProviderException('CITATION_INVALID','The model supplied an unsupported quotation. Try another model or clearer references.');
                return $citation+['locator'=>$segment['locator']];
            }
        }
        throw new AiProviderException('CITATION_INVALID','The model cited a source or passage outside this run. No new version was saved.');
    }
    private function validateEvidence(array $notes,array $sources): void
    {
        $references=0;
        foreach ($notes as $note) foreach ($note['sources'] as $citation) { $this->citation($citation,$sources); $references++; }
        if (!$references) throw new AiProviderException('MISSING_EVIDENCE','The research stage found no supported facts. Add a clearer reference.');
    }
    private function validateDraft(array $draft,array $sources): void
    {
        if (!trim($draft['title']) || !$draft['blocks']) throw new AiProviderException('OUTPUT_INVALID','The model returned an empty draft.');
        if (!collect($draft['blocks'])->contains(fn($block)=>$block['kind']==='PARAGRAPH'&&mb_strlen(trim($block['text']))>=40)) throw new AiProviderException('OUTPUT_INVALID','The model returned an outline without complete paragraph content. Try again or use a stronger writing model.');
        $ids=[];
        foreach ($draft['blocks'] as $block) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/',$block['id'])||isset($ids[$block['id']])||!trim($block['text'])) throw new AiProviderException('OUTPUT_INVALID','The draft has empty or duplicated content blocks.');
            $ids[$block['id']]=true;
            foreach ($block['citations'] as $citation) $this->citation($citation,$sources);
        }
    }

    public function findings(array $brief,array $draft,array $quality,array $sources): array
    {
        return GenerationQuality::findings($brief,$draft,$quality,$sources);
    }

    private function save(AgentRun $original,array $draft,array $findings,array $sources): void
    {
        if (collect($findings)->contains(fn($finding)=>$finding['severity']==='HARD')) throw new AiProviderException('QUALITY_FAILED','Required quality checks have not passed.');
        $this->check($original);
        DB::transaction(function () use ($original,$draft,$findings,$sources) {
            [$run,$brief,$project,$client]=self::locks($original);
            if (!$run->active()||$run->cancel_requested_at) throw new AiProviderException('CANCELLED','The run was cancelled before saving.');
            if (!WorkspaceAccess::write($run->requester,$client)||$client->is_archived||$project->is_archived||$brief->is_archived) throw new AiProviderException('ACCESS_CHANGED','Workspace access changed before saving.');
            if (now()->timestamp-($run->started_at??$run->created_at)->timestamp >= $run->limits_snapshot['duration_seconds']) throw new AiProviderException('TIME_LIMIT','The run time allowance expired before saving.');
            $body=implode("\n\n",array_map(fn($b)=>match($b['kind']){'HEADING'=>'## '.$b['text'],'BULLET'=>'- '.$b['text'],default=>$b['text']},$draft['blocks']));
            $version=ContentVersion::create(['brief_id'=>$brief->id,'source_run_id'=>$run->id,'author_id'=>$run->requested_by,
                'version_no'=>(ContentVersion::where('brief_id',$brief->id)->max('version_no')??0)+1,'version_kind'=>'AI','title'=>$draft['title'],'body_markdown'=>$body,
                'blocks'=>$draft['blocks'],'format_metadata'=>collect($draft)->only(['email_subject','preview_text','seo_title','meta_description'])->all(),'quality_findings'=>$findings]);
            foreach ($draft['blocks'] as $block) foreach ($block['citations'] as $citation) {
                $citation=$this->citation($citation,$sources);
                Citation::firstOrCreate(['version_id'=>$version->id,'claim_anchor'=>$block['id'],'source_id'=>$citation['source_id'],'segment_ordinal'=>$citation['segment_ordinal']], ['locator'=>$citation['locator'],'supporting_excerpt'=>$citation['supporting_excerpt']]);
            }
            $run->update(['status'=>'SUCCEEDED','active_brief_id'=>null,'finished_at'=>now(),'error_code'=>null,'error_message'=>null]);
            AgentStep::where('run_id',$run->id)->where('status','PENDING')->update(['status'=>'SKIPPED','finished_at'=>now()]);
            $brief->update(['status'=>'READY_FOR_REVIEW','version'=>$brief->version+1]);
            AuditEvent::record($run->requester,'run.succeeded','RUN',$run->id,['brief_id'=>$brief->id,'version_id'=>$version->id]);
        });
    }
}
