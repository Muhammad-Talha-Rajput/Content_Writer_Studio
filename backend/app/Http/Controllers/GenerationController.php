<?php
namespace App\Http\Controllers;
use App\Jobs\GenerateContent;
use App\Models\{AgentRun, AgentStep, AiSetting, AuditEvent, Client, ContentBrief, ContentVersion, Project, Source};
use App\Services\GenerationInput;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};

class GenerationController extends Controller
{
    public function index(Request $request, ContentBrief $brief)
    {
        Gate::authorize('view',$brief);
        \App\Services\GenerationWorkflow::recoverExpired();
        $runs=AgentRun::where('brief_id',$brief->id)->latest()->limit(30)->get(['id','brief_id','requested_by','status','started_at','finished_at','cancel_requested_at','error_code','error_message','provider_usage','created_at']);
        $versions=ContentVersion::where('brief_id',$brief->id)->orderByDesc('version_no')->get(['id','brief_id','source_run_id','version_no','version_kind','title','created_at']);
        $dailyRuns=AgentRun::where('requested_by',$request->user()->id)->where('created_at','>=',now()->startOfDay())->count();
        $latestRun=AgentRun::where('brief_id',$brief->id)->latest()->first(['input_snapshot']);
        $profiles=collect(['openrouter','gemini','ollama','huggingface'])->map(function ($name) use ($dailyRuns) {
            $p=AiSetting::current($name);
            return ['provider'=>$name,'model'=>$p->model,'ready'=>$p->enabled && $p->model!=='' && ($name==='ollama'||$p->key()!==''),'free_only'=>$name==='huggingface'||$p->free_only,'daily_runs_used'=>$dailyRuns,'daily_run_limit'=>$p->limits['user_daily_runs']];
        });
        return response()->json(['runs'=>$runs,'versions'=>$versions,'profiles'=>$profiles,'default_provider'=>$latestRun?->input_snapshot['provider']['name']??null]);
    }
    public function store(Request $request, ContentBrief $brief)
    {
        Gate::authorize('view',$brief);
        \App\Services\GenerationWorkflow::recoverExpired();
        $data=$request->validate(['idempotency_key'=>['required','uuid'],'brief_version'=>['required','integer','min:1'],'latest_version_id'=>['present','nullable','uuid'],
            'provider'=>['required','in:openrouter,gemini,ollama,huggingface'],'source_ids'=>['present','array','max:15'],'source_ids.*'=>['required','uuid','distinct']]);
        sort($data['source_ids']);
        $fingerprint=hash('sha256',json_encode(collect($data)->except('idempotency_key')->all()));
        $run=DB::transaction(function () use ($request,$brief,$data,$fingerprint) {
            $global=AiSetting::current(); AiSetting::lockForUpdate()->findOrFail($global->id);
            $client=Client::lockForUpdate()->findOrFail($brief->project->client_id);
            WorkspaceAccess::editable($request->user(),$client);
            $project=Project::lockForUpdate()->findOrFail($brief->project_id);
            $brief=ContentBrief::lockForUpdate()->findOrFail($brief->id);
            $existing=AgentRun::where('requested_by',$request->user()->id)->where('idempotency_key',$data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->brief_id===$brief->id && ($existing->input_snapshot['request_fingerprint']??'')===$fingerprint,409,'This request key was already used for different inputs.');
                return $existing;
            }
            WorkspaceAccess::version($brief,(int)$data['brief_version']);
            abort_if($project->is_archived||$brief->is_archived,409,'Restore the project and brief before generating.');
            abort_if(AgentRun::where('active_brief_id',$brief->id)->exists(),409,'This brief already has an active run.');
            abort_unless(in_array($brief->status,['DRAFT','READY_FOR_REVIEW','CHANGES_REQUESTED'],true),409,'This brief cannot start generation in its current state.');
            $latest=ContentVersion::where('brief_id',$brief->id)->orderByDesc('version_no')->first();
            abort_unless($latest?->id===$data['latest_version_id'],409,'A newer content version exists. Reload before generating.');
            $profile=AiSetting::current($data['provider']);
            abort_if($profile->provider==='openrouter' && !\App\Services\AiGateway::textModel(['id'=>$profile->model]),422,'This is a reranking, embedding or safety model. Select a text-generation model in AI providers.');
            abort_if($profile->provider==='huggingface' && !$profile->free_only,422,'Hugging Face must use verified zero-cost routes only.');
            abort_unless($profile->enabled && $profile->model!=='' && ($profile->provider==='ollama'||$profile->key()!==''),422,'Ask an administrator to configure and enable this provider in AI settings.');
            $limits=$profile->limits;
            abort_if(AgentRun::where('created_at','>=',now()->startOfDay())->where('requested_by',$request->user()->id)->count()>=$limits['user_daily_runs'],429,'Your daily generation limit has been reached.');
            abort_if(AgentRun::where('created_at','>=',now()->startOfDay())->count()>=$limits['organization_daily_runs'],429,'The organization daily generation limit has been reached.');
            abort_if(AgentRun::whereIn('status',['QUEUED','RUNNING'])->count()>=$limits['active_runs'],429,'All generation slots are busy. Try again after a run finishes.');
            $sources=Source::where('brief_id',$brief->id)->whereIn('id',$data['source_ids'])->where('is_archived',false)->where('parse_status','READY')->get();
            abort_unless($sources->count()===count($data['source_ids']),422,'Choose only ready, active sources belonging to this brief.');
            abort_if(($brief->instructions['research_mode']??'PROVIDED_ONLY')==='PROVIDED_ONLY' && $sources->isEmpty(),422,'Attach at least one ready source before starting a provided-sources run.');
            $snapshot=['brief'=>$brief->only(['id','topic','content_type','instructions','brand_snapshot','version']), 'sources'=>GenerationInput::sources($sources,$limits['context_characters']),
                'provider'=>['name'=>$profile->provider,'model'=>$profile->model,'endpoint'=>$profile->endpoint,'strict_json'=>$profile->strict_json,'free_only'=>$profile->free_only,'thinking_control'=>$profile->provider==='openrouter'],
                'prompt_version'=>config('generation.prompt_version'),'request_fingerprint'=>$fingerprint];
            try { \App\Services\GenerationQuality::rules($snapshot['brief']); }
            catch (\App\Services\AiProviderException $error) { abort(422,$error->getMessage()); }
            $run=AgentRun::create(['brief_id'=>$brief->id,'active_brief_id'=>$brief->id,'requested_by'=>$request->user()->id,'idempotency_key'=>$data['idempotency_key'],'previous_brief_status'=>$brief->status,
                'input_snapshot'=>$snapshot,'limits_snapshot'=>$limits,'provider_secret'=>$profile->key(),'provider_usage'=>['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0]]);
            foreach (['PLAN','RESEARCH','WRITE','QUALITY'] as $stage) AgentStep::create(['run_id'=>$run->id,'step_type'=>$stage]);
            $brief->update(['status'=>'IN_PROGRESS','version'=>$brief->version+1]);
            AuditEvent::record($request->user(),'run.queued','RUN',$run->id,['brief_id'=>$brief->id,'provider'=>$profile->provider,'model'=>$profile->model]);
            GenerateContent::dispatch($run->id)->onConnection('generation')->onQueue('generation');
            return $run->fresh();
        });
        return response()->json(['run'=>$run],202);
    }
    public function show(AgentRun $run)
    {
        Gate::authorize('view',$run->brief);
        return response()->json(['run'=>$run->load(['steps'=>fn($q)=>$q->orderBy('created_at'),'output'])]);
    }
    public function continueRun(Request $request, AgentRun $run)
    {
        Gate::authorize('view',$run->brief);
        $data=$request->validate(['idempotency_key'=>['required','uuid'],'quality_correction'=>['sometimes','boolean']]);
        $continued=DB::transaction(function () use ($request,$run,$data) {
            $global=AiSetting::current(); AiSetting::lockForUpdate()->findOrFail($global->id);
            $brief=ContentBrief::findOrFail($run->brief_id);
            $project=Project::findOrFail($brief->project_id);
            $client=Client::lockForUpdate()->findOrFail($project->client_id);
            $project=Project::lockForUpdate()->findOrFail($project->id);
            WorkspaceAccess::editable($request->user(),$client);
            $brief=ContentBrief::lockForUpdate()->findOrFail($brief->id);
            $sourceRun=AgentRun::lockForUpdate()->findOrFail($run->id);
            $existing=AgentRun::where('requested_by',$request->user()->id)->where('idempotency_key',$data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->brief_id===$brief->id && ($existing->input_snapshot['continuation_from_run_id']??null)===$sourceRun->id,409,'This request key was already used for different inputs.');
                return $existing;
            }
            $qualityCorrection=($data['quality_correction']??false) || in_array($sourceRun->error_code,['QUALITY_FAILED','REPAIR_INVALID'],true);
            abort_unless($sourceRun->status==='FAILED' || ($qualityCorrection && $sourceRun->status==='SUCCEEDED'),409,'Only failed runs or an explicit quality correction can be continued.');
            abort_unless(!in_array($sourceRun->error_code,['MISSING_EVIDENCE','ACCESS_CHANGED'],true),409,'This run needs different references or restored access. Start a new run after correcting it.');
            $sourceOutput=ContentVersion::where('source_run_id',$sourceRun->id)->first();
            abort_if($sourceOutput && !$qualityCorrection,409,'This run already produced a saved version.');
            if ($sourceOutput) abort_unless(ContentVersion::where('brief_id',$brief->id)->orderByDesc('version_no')->value('id')===$sourceOutput->id,409,'A newer version exists. Correct the latest saved draft instead.');
            $providerName=$sourceRun->input_snapshot['provider']['name']??'';
            abort_unless(in_array($providerName,['openrouter','gemini','ollama','huggingface'],true),422,'The saved run has no supported provider.');
            $profile=AiSetting::current($providerName);
            abort_if($profile->provider==='openrouter' && !\App\Services\AiGateway::textModel(['id'=>$profile->model]),422,'Select an OpenRouter text-generation model before continuing.');
            abort_if($profile->provider==='huggingface' && !$profile->free_only,422,'Hugging Face must use verified zero-cost routes only.');
            abort_unless($profile->enabled && $profile->model!=='' && ($profile->provider==='ollama'||$profile->key()!==''),422,'Ask an administrator to configure and enable this provider before continuing.');
            $priorBrief=$sourceRun->input_snapshot['brief']??[];
            foreach (['topic','content_type','instructions','brand_snapshot'] as $field) abort_unless(($priorBrief[$field]??null)===$brief->{$field},409,'The brief changed after this run. Save it and start a new run to use the updated instructions.');
            try { \App\Services\GenerationQuality::rules($priorBrief); }
            catch (\App\Services\AiProviderException $error) { abort(422,$error->getMessage()); }
            abort_if($project->is_archived||$brief->is_archived,409,'Restore the project and brief before continuing.');
            abort_if(AgentRun::where('active_brief_id',$brief->id)->exists(),409,'This brief already has an active run.');
            abort_unless(in_array($brief->status,['DRAFT','READY_FOR_REVIEW','CHANGES_REQUESTED'],true),409,'This brief cannot continue in its current state.');
            $limits=$profile->limits;
            abort_if(AgentRun::where('created_at','>=',now()->startOfDay())->where('requested_by',$request->user()->id)->count()>=$limits['user_daily_runs'],429,'Your daily generation limit has been reached.');
            abort_if(AgentRun::where('created_at','>=',now()->startOfDay())->count()>=$limits['organization_daily_runs'],429,'The organization daily generation limit has been reached.');
            abort_if(AgentRun::whereIn('status',['QUEUED','RUNNING'])->count()>=$limits['active_runs'],429,'All generation slots are busy. Try again after a run finishes.');
            $checkpoints=\App\Services\GenerationCheckpoints::collect($sourceRun);
            $qualityRetry=null;
            if ($qualityCorrection) {
                $qualitySteps=$sourceRun->steps()->where('step_type','QUALITY')->whereIn('status',['SUCCEEDED','REUSED'])->orderByDesc('pass_no')->orderByDesc('attempt_no')->get();
                $passedQuality=$qualitySteps->first(fn($step)=>isset($step->artifacts['candidate'],$step->artifacts['gate_findings']) && !collect($step->artifacts['gate_findings'])->contains(fn($finding)=>$finding['severity']==='HARD'));
                $lastQuality=$passedQuality??$qualitySteps->first();
                $candidate=$sourceOutput?->only(['title','blocks']) ?? ($lastQuality?->artifacts['candidate']??null);
                if ($sourceOutput) $candidate=[...$candidate,...($sourceOutput->format_metadata??[])];
                if (!$candidate) $candidate=$sourceRun->steps()->where('step_type','WRITE')->whereIn('status',['SUCCEEDED','REUSED'])->orderByDesc('pass_no')->first()?->artifacts;
                abort_unless($candidate,409,'No saved candidate is available for targeted quality correction.');
                $qualityRetry=['draft'=>$candidate,'findings'=>\App\Services\GenerationQuality::findings($priorBrief,$candidate,['findings'=>$lastQuality?->artifacts['findings']??[]],$sourceRun->input_snapshot['sources'])];
                $checkpoints=$checkpoints->filter(fn($step)=>in_array($step->step_type,['PLAN','RESEARCH'],true));
                if (!$sourceOutput && $passedQuality && !collect($qualityRetry['findings'])->contains(fn($finding)=>$finding['severity']==='HARD')) {
                    $reviewCheckpoint=clone $passedQuality;$reviewCheckpoint->pass_no=0;
                    $checkpoints->put('QUALITY:0',$reviewCheckpoint);
                }
            }
            abort_if($checkpoints->isEmpty(),409,'This run has no completed stages to continue from.');
            $limits['revision_passes']=max($limits['revision_passes'],$checkpoints->max('pass_no')??0);
            $snapshot=$sourceRun->input_snapshot;
            if ($qualityRetry) $snapshot['quality_retry']=$qualityRetry;
            $recoveryOutput=$snapshot['recovery_output']??[];
            foreach ($sourceRun->steps()->where('status','FAILED')->get() as $failedStep) {
                $usage=$failedStep->provider_usage??[];
                if (($usage['reasoning_tokens']??0)>0 && in_array($sourceRun->error_code,['OUTPUT_LIMIT','THINKING_LIMIT'],true)) {
                    $key=$failedStep->step_type.':'.$failedStep->pass_no;
                    $recoveryOutput[$key]=min($limits['max_output_tokens'],max(8192,($usage['output_tokens']??0)*2));
                }
            }
            $snapshot['recovery_output']=$recoveryOutput;
            $research=$checkpoints->get('RESEARCH:0');
            if (!empty($research?->artifacts['source_snapshots'])) $snapshot['sources']=$research->artifacts['source_snapshots'];
            $snapshot['continuation_from_run_id']=$sourceRun->id;
            $snapshot['provider']=['name'=>$profile->provider,'model'=>$profile->model,'endpoint'=>$profile->endpoint,'strict_json'=>$profile->strict_json,'free_only'=>$profile->free_only,'thinking_control'=>$profile->provider==='openrouter'];
            $continued=AgentRun::create(['brief_id'=>$brief->id,'active_brief_id'=>$brief->id,'requested_by'=>$request->user()->id,'idempotency_key'=>$data['idempotency_key'],'previous_brief_status'=>$brief->status,
                'input_snapshot'=>$snapshot,'limits_snapshot'=>$limits,'provider_secret'=>$profile->key(),'provider_usage'=>['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0]]);
            foreach (['PLAN','RESEARCH','WRITE','QUALITY'] as $stage) AgentStep::create(['run_id'=>$continued->id,'step_type'=>$stage]);
            foreach ($checkpoints as $checkpoint) AgentStep::updateOrCreate(
                ['run_id'=>$continued->id,'step_type'=>$checkpoint->step_type,'pass_no'=>$checkpoint->pass_no,'attempt_no'=>0],
                ['status'=>'REUSED','artifacts'=>$checkpoint->artifacts,'provider_usage'=>null,'finished_at'=>now(),'error_message'=>null]
            );
            $brief->update(['status'=>'IN_PROGRESS','version'=>$brief->version+1]);
            AuditEvent::record($request->user(),'run.continued','RUN',$continued->id,['brief_id'=>$brief->id,'parent_run_id'=>$sourceRun->id,'reused_stages'=>$checkpoints->count()]);
            GenerateContent::dispatch($continued->id)->onConnection('generation')->onQueue('generation');
            return $continued->fresh();
        });
        return response()->json(['run'=>$continued],202);
    }
    public function reviseRun(Request $request, AgentRun $run)
    {
        $request->merge(['quality_correction'=>true]);
        return $this->continueRun($request,$run);
    }
    public function cancel(Request $request, AgentRun $run)
    {
        Gate::authorize('view',$run->brief);
        abort_unless(WorkspaceAccess::write($request->user(),$run->brief->project->client),403);
        \App\Services\GenerationWorkflow::finish($run->id,'CANCELLED','CANCELLED','Cancelled by a team member.');
        return response()->json(['run'=>$run->fresh()]);
    }
    public function version(ContentVersion $version)
    {
        Gate::authorize('view',$version->brief);
        return response()->json(['version'=>$version->load('citations.source:id,title,source_type,final_url,retrieved_at')]);
    }
}
