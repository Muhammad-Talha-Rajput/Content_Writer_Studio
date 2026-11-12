<?php
namespace Tests\Feature;
use App\Models\{AgentRun,AiSetting};
use App\Services\{AiGateway,AiProviderException,GenerationSchema,OpenRouterThinking};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,Http};
use Tests\TestCase;

class AiGatewayTest extends TestCase
{
    use RefreshDatabase;
    private function runFor(string $provider): AgentRun
    {
        return new AgentRun(['provider_secret'=>'test-only-key','input_snapshot'=>['provider'=>['name'=>$provider,'model'=>$provider==='gemini'?'test-gemini':'test-model','strict_json'=>false,'free_only'=>true,'endpoint'=>'http://127.0.0.1:11434']]]);
    }
    private function controlledCatalog(): array
    {
        return [
            ['id'=>'safe/a:free','pricing'=>['prompt'=>'0','completion'=>'0'],'reasoning'=>['mandatory'=>false],'supported_parameters'=>['reasoning','response_format','structured_outputs']],
            ['id'=>'safe/b:free','pricing'=>['prompt'=>'0','completion'=>'0'],'reasoning'=>['mandatory'=>false],'supported_parameters'=>['reasoning','response_format','structured_outputs']],
            ['id'=>'mandatory/uncapped:free','pricing'=>['prompt'=>'0','completion'=>'0'],'reasoning'=>['mandatory'=>true],'supported_parameters'=>['reasoning','response_format','structured_outputs']],
            ['id'=>'mandatory/capped:free','pricing'=>['prompt'=>'0','completion'=>'0'],'reasoning'=>['mandatory'=>true,'supports_max_tokens'=>true],'supported_parameters'=>['reasoning','response_format','structured_outputs']],
            ['id'=>'paid/model','pricing'=>['prompt'=>'0.01','completion'=>'0.01'],'reasoning'=>['mandatory'=>false],'supported_parameters'=>['reasoning','response_format','structured_outputs']],
        ];
    }

    public function test_thinking_catalog_cache_stores_only_small_routing_metadata(): void
    {
        Cache::forget(OpenRouterThinking::CATALOG_KEY);
        $model=$this->controlledCatalog()[0];$model['description']=str_repeat('Large public model description. ',100000);
        Http::fake(['openrouter.ai/api/v1/models'=>Http::response(['data'=>[$model]])]);
        $catalog=(new OpenRouterThinking)->catalog();
        $this->assertArrayNotHasKey('description',$catalog[0]);
        $this->assertLessThan(4000,strlen(serialize($catalog)));
        $this->assertSame('safe/a:free',$catalog[0]['id']);
    }

    public function test_controlled_free_routing_disables_thinking_and_excludes_uncapped_mandatory_or_paid_models(): void
    {
        Cache::put(OpenRouterThinking::CATALOG_KEY,$this->controlledCatalog(),300);
        $run=$this->runFor('openrouter');$snapshot=$run->input_snapshot;$snapshot['provider']=[...$snapshot['provider'],'model'=>'openrouter/free','thinking_control'=>true,'strict_json'=>true];$run->input_snapshot=$snapshot;
        Http::fake(['*'=>Http::response(['model'=>'safe/a:free','choices'=>[['message'=>['content'=>json_encode(['summary'=>'Plan','outline'=>['Section'],'research_urls'=>[]])],'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>20,'total_tokens'=>30,'completion_tokens_details'=>['reasoning_tokens'=>0]]])]);
        $result=(new AiGateway)->generate($run,'plan',GenerationSchema::stage('PLAN'),[],30,2000);
        $this->assertSame(0,$result['usage']['reasoning_tokens']);
        Http::assertSent(fn($r)=>$r['model']==='safe/a:free' && $r['models']===['safe/a:free','safe/b:free'] && $r['reasoning']['enabled']===false && !isset($r['reasoning']['effort']) && $r['provider']['max_price']['prompt']===0 && $r['provider']['require_parameters']===true);
        $route=(new OpenRouterThinking)->route($snapshot['provider'],2000,['safe/a:free']);
        $this->assertSame(['safe/b:free'],$route['models']);
    }

    public function test_selected_mandatory_thinking_is_not_disabled_when_the_endpoint_requires_it(): void
    {
        Cache::put(OpenRouterThinking::CATALOG_KEY,$this->controlledCatalog(),300);Http::fake();
        $run=$this->runFor('openrouter');$snapshot=$run->input_snapshot;$snapshot['provider']['model']='mandatory/uncapped:free';$snapshot['provider']['thinking_control']=true;$run->input_snapshot=$snapshot;
        $route=(new OpenRouterThinking)->route($snapshot['provider'],2000);
        $this->assertSame(['enabled'=>true],$route['reasoning']);
        $this->assertNull($route['budget']);
        Http::assertNothingSent();
    }

    public function test_supported_mandatory_thinking_gets_a_numeric_cap_and_leaves_space_for_the_answer(): void
    {
        Cache::put(OpenRouterThinking::CATALOG_KEY,$this->controlledCatalog(),300);
        $profile=['model'=>'mandatory/capped:free','free_only'=>true,'strict_json'=>true];
        $route=(new OpenRouterThinking)->route($profile,2500);
        $this->assertSame(['max_tokens'=>1024],$route['reasoning']);
        $this->assertGreaterThanOrEqual(512,2500-$route['reasoning']['max_tokens']);
        try{(new OpenRouterThinking)->route($profile,1200);$this->fail('No space left for answer');}
        catch(AiProviderException $error){$this->assertSame('THINKING_UNSUPPORTED',$error->reason);}
    }

    public function test_thinking_only_exhaustion_records_usage_and_can_try_another_compatible_route(): void
    {
        Cache::put(OpenRouterThinking::CATALOG_KEY,$this->controlledCatalog(),300);
        $run=$this->runFor('openrouter');$snapshot=$run->input_snapshot;$snapshot['provider']['model']='openrouter/free';$snapshot['provider']['thinking_control']=true;$run->input_snapshot=$snapshot;
        Http::fake(['*'=>Http::response(['model'=>'safe/a:free','choices'=>[['message'=>['content'=>''],'finish_reason'=>'length']],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>2000,'total_tokens'=>2100,'completion_tokens_details'=>['reasoning_tokens'=>2000]]])]);
        try{(new AiGateway)->generate($run,'plan',GenerationSchema::stage('PLAN'),[],30,2000);$this->fail('Thinking-only reply accepted');}
        catch(AiProviderException $error){$this->assertSame('THINKING_LIMIT',$error->reason);$this->assertTrue($error->transient);$this->assertTrue($error->usage['thinking_budget_exceeded']);$this->assertSame(2100,$error->usage['total_tokens']);}
    }
    public function test_all_three_adapters_parse_outputs_and_usage_without_cross_provider_fallback(): void
    {
        Http::preventStrayRequests();
        $json=json_encode(['summary'=>'Plan','outline'=>['Section'],'research_urls'=>[]]);
        Http::fake([
            'openrouter.ai/api/v1/chat/completions'=>Http::response(['choices'=>[['message'=>['content'=>$json],'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>10,'completion_tokens'=>5,'total_tokens'=>15]]),
            'generativelanguage.googleapis.com/v1beta/models/test-gemini:generateContent'=>Http::response(['candidates'=>[['content'=>['parts'=>[['text'=>$json]]],'finishReason'=>'STOP']],'usageMetadata'=>['promptTokenCount'=>10,'candidatesTokenCount'=>5,'totalTokenCount'=>15]]),
            '127.0.0.1:11434/api/chat'=>Http::response(['message'=>['content'=>$json],'done'=>true,'prompt_eval_count'=>10,'eval_count'=>5]),
        ]);
        foreach (['openrouter','gemini','ollama'] as $provider) {
            $result=(new AiGateway)->generate($this->runFor($provider),'plan',GenerationSchema::stage('PLAN'),['brief'=>'A test'],30,1000);
            $this->assertSame('Plan',$result['output']['summary']); $this->assertSame(15,$result['usage']['total_tokens']);
        }
        Http::assertSentCount(3);
        Http::assertSent(fn($r)=>str_contains($r->url(),'openrouter.ai') && $r['provider']['max_price']['prompt']===0 && !$r->hasHeader('x-goog-api-key'));
        Http::assertSent(fn($r)=>str_contains($r->url(),'googleapis.com') && $r->hasHeader('x-goog-api-key','test-only-key') && $r['generationConfig']['responseMimeType']==='application/json');
        Http::assertSent(fn($r)=>str_contains($r->url(),'11434') && $r['stream']===false && is_array($r['format']) && !$r->hasHeader('Authorization'));
    }
    public function test_rate_limits_stop_immediate_retries_and_malformed_outputs_keep_usage(): void
    {
        Http::fake(['*'=>Http::sequence()->push(['error'=>['message'=>'Do not expose upstream details']],429)->push(['choices'=>[['message'=>['content'=>'not json']]],'usage'=>['total_tokens'=>23]])]);
        try { (new AiGateway)->generate($this->runFor('openrouter'),'plan',GenerationSchema::stage('PLAN'),[],10,1000); $this->fail('429 accepted'); }
        catch (AiProviderException $e) { $this->assertFalse($e->transient); $this->assertStringNotContainsString('Do not expose',$e->getMessage()); }
        try { (new AiGateway)->generate($this->runFor('openrouter'),'plan',GenerationSchema::stage('PLAN'),[],10,1000); $this->fail('Bad output accepted'); }
        catch (AiProviderException $e) { $this->assertSame('OUTPUT_INVALID',$e->reason); $this->assertSame(23,$e->usage['total_tokens']); }
    }

    public function test_upstream_model_limits_are_distinguished_from_daily_account_limits(): void
    {
        Http::fake(['*'=>Http::sequence()->push(['error'=>['message'=>'Provider returned error','metadata'=>['provider_name'=>'Google AI Studio','raw'=>'Temporarily rate-limited upstream.']]],429)->push(['error'=>['message'=>'Rate limit exceeded: free-models-per-day']],429)]);
        foreach(['upstream at Google AI Studio','daily free-request limit'] as $expected){
            try{(new AiGateway)->generate($this->runFor('openrouter'),'plan',GenerationSchema::stage('PLAN'),[],30,1000);$this->fail('Rate limit accepted');}
            catch(AiProviderException $error){$this->assertFalse($error->transient);$this->assertStringContainsString($expected,$error->getMessage());}
        }
    }

    public function test_free_router_requires_structured_output_support_without_paid_routing(): void
    {
        $run=$this->runFor('openrouter');$snapshot=$run->input_snapshot;$snapshot['provider']['model']='openrouter/free';$snapshot['provider']['strict_json']=true;$run->input_snapshot=$snapshot;
        Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>json_encode(['summary'=>'Plan','outline'=>['Section'],'research_urls'=>[]])]]],'usage'=>['total_tokens'=>10]])]);
        (new AiGateway)->generate($run,'plan',GenerationSchema::stage('PLAN'),[],30,1000);
        Http::assertSent(fn($r)=>$r['model']==='openrouter/free' && $r['reasoning']['effort']==='low' && !isset($r['reasoning']['enabled']) && $r['provider']['require_parameters']===true && $r['provider']['max_price']['prompt']===0 && $r['provider']['max_price']['completion']===0 && $r['response_format']['type']==='json_schema');
    }

    public function test_reasoning_exhaustion_reports_actual_model_and_token_breakdown(): void
    {
        Http::fake(['*'=>Http::response(['model'=>'example/resolved-model:free','choices'=>[['message'=>['content'=>''],'finish_reason'=>'length']],'usage'=>['prompt_tokens'=>700,'completion_tokens'=>8000,'total_tokens'=>8700,'completion_tokens_details'=>['reasoning_tokens'=>7999]]])]);
        try{(new AiGateway)->generate($this->runFor('openrouter'),'plan',GenerationSchema::stage('PLAN'),[],30,8000);$this->fail('Truncated result accepted');}
        catch(AiProviderException $error){$this->assertSame('OUTPUT_LIMIT',$error->reason);$this->assertStringContainsString('7999 reasoning tokens',$error->getMessage());$this->assertSame('example/resolved-model:free',$error->usage['resolved_model']);}
    }

    public function test_transport_timeout_is_identified_without_repeating_a_long_request(): void
    {
        Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out'));
        try {(new AiGateway)->generate($this->runFor('openrouter'),'quality',GenerationSchema::stage('QUALITY'),[],180,1000);$this->fail('Timeout accepted');}
        catch(AiProviderException $error){$this->assertSame('PROVIDER_TIMEOUT',$error->reason);$this->assertFalse($error->transient);$this->assertStringContainsString('OpenRouter',$error->getMessage());}
    }

    public function test_research_sends_reference_text_once_and_keeps_exact_citation_validation(): void
    {
        $run=$this->runFor('openrouter');$id='11111111-1111-4111-8111-111111111111';
        $run->input_snapshot=[...$run->input_snapshot,'sources'=>[['id'=>$id]]];
        $quote='The approved package includes eight social media captions.';
        $output=['evidence_sufficient'=>true,'missing_information'=>[],'notes'=>[['fact'=>'Eight captions.','sources'=>[['passage_id'=>'q1']]]]];
        Http::fake(['*'=>Http::response(['choices'=>[['message'=>['content'=>json_encode($output)],'finish_reason'=>'stop']],'usage'=>['total_tokens'=>30]])]);
        $result=(new AiGateway)->generate($run,'research',GenerationSchema::stage('RESEARCH'),['sources'=>[['id'=>$id,'segments'=>[['ordinal'=>1,'text'=>$quote,'locator'=>'Text']]]]],180,1000);
        $this->assertSame($quote,$result['output']['notes'][0]['sources'][0]['supporting_excerpt']);
        Http::assertSent(function($request)use($quote){$context=json_decode($request['messages'][1]['content'],true);return !isset($context['sources'][0]['segments'][0]['text']) && substr_count($request['messages'][1]['content'],$quote)===1;});
    }

    public function test_long_pdf_table_passages_fit_the_restored_citation_limit_without_changing_source_text(): void
    {
        $run=$this->runFor('openrouter');$id='11111111-1111-4111-8111-111111111111';$snapshot=$run->input_snapshot;$snapshot['sources']=[['id'=>$id]];$run->input_snapshot=$snapshot;
        $quote=str_repeat('Campus A1 new 4 GB RAM 64 GB storage PKR 24900 ',12).'.';
        Http::fake(function($request)use($quote){
            $context=json_decode($request['messages'][1]['content'],true);$passages=$context['exact_passage_labels'];
            $this->assertGreaterThan(1,count($passages));
            foreach($passages as $passage){$this->assertLessThanOrEqual(400,mb_strlen($passage['text']));$this->assertStringContainsString($passage['text'],$quote);}
            $output=['evidence_sufficient'=>true,'missing_information'=>[],'notes'=>[
                ['fact'=>'Sample phone stock.','sources'=>[['passage_id'=>array_key_first($passages)]]],
            ]];
            return Http::response(['choices'=>[['message'=>['content'=>json_encode($output)]]],'usage'=>['total_tokens'=>30]]);
        });
        $result=(new AiGateway)->generate($run,'research',GenerationSchema::stage('RESEARCH'),['sources'=>[['id'=>$id,'segments'=>[['ordinal'=>1,'text'=>$quote,'locator'=>'Page 1']]]]],180,1000);
        GenerationSchema::validate($result['output'],GenerationSchema::stage('RESEARCH'));
        $this->assertSame($id,$result['output']['notes'][0]['sources'][0]['source_id']);
    }
    public function test_model_discovery_lists_only_zero_price_openrouter_models_and_local_endpoint_is_fixed(): void
    {
        Http::fake(['openrouter.ai/api/v1/models'=>Http::response(['data'=>[
            ['id'=>'free-model','name'=>'Free model','pricing'=>['prompt'=>'0','completion'=>'0']],
            ['id'=>'paid-model','name'=>'Paid model','pricing'=>['prompt'=>'0','completion'=>'0.01']],
            ['id'=>'nvidia/example-rerank:free','name'=>'Reranker','pricing'=>['prompt'=>'0','completion'=>'0']],
            ['id'=>'example/embed:free','name'=>'Embeddings','pricing'=>['prompt'=>'0','completion'=>'0'],'architecture'=>['output_modalities'=>['embedding']]],
        ]])]);
        $models=(new AiGateway)->models(AiSetting::current()); $this->assertSame(['free-model'],array_column($models,'id'));
        foreach (['http://192.168.1.2:11434','http://127.0.0.1:3306','https://example.com','http://user@localhost:11434'] as $url) {
            try { AiGateway::localEndpoint($url); $this->fail('Unsafe local endpoint accepted'); } catch (AiProviderException $e) { $this->assertSame('SETUP',$e->reason); }
        }
    }

    public function test_local_model_source_labels_are_constrained_and_restored_to_authorized_ids(): void
    {
        $id='11111111-1111-4111-8111-111111111111';
        $run=$this->runFor('ollama');
        $run->input_snapshot=[...$run->input_snapshot,'sources'=>[['id'=>$id]]];
        Http::fake(['127.0.0.1:11434/api/chat'=>Http::response(['message'=>['content'=>json_encode(['evidence_sufficient'=>true,'missing_information'=>[],'notes'=>[['fact'=>'s1','sources'=>[['passage_id'=>'q1']]]]])],'done'=>true,'prompt_eval_count'=>20,'eval_count'=>10])]);
        $result=(new AiGateway)->generate($run,'research',GenerationSchema::stage('RESEARCH'),['sources'=>[['id'=>$id,'segments'=>[['ordinal'=>1,'text'=>'An exact source quotation.','locator'=>'Text']]]]],30,1000);
        $this->assertSame($id,$result['output']['notes'][0]['sources'][0]['source_id']);
        $this->assertSame('An exact source quotation.',$result['output']['notes'][0]['sources'][0]['supporting_excerpt']);
        $this->assertSame('s1',$result['output']['notes'][0]['fact']);
        Http::assertSent(fn($request)=>$request['format']['properties']['notes']['items']['properties']['sources']['items']['properties']['passage_id']['enum']===['q1']);
    }

    public function test_exact_passage_labels_restore_verbatim_quotes_and_reject_unlisted_quotes(): void
    {
        $id='22222222-2222-4222-8222-222222222222';
        $run=$this->runFor('ollama');
        $run->input_snapshot=[...$run->input_snapshot,'sources'=>[['id'=>$id]]];
        $quote='Small businesses can use a clear content plan to organize ideas.';
        $context=['brief'=>['topic'=>'Content planning'],'sources'=>[['id'=>$id,'segments'=>[['ordinal'=>2,'text'=>$quote,'locator'=>'Page 1']]]]];
        Http::fake(['127.0.0.1:11434/api/chat'=>Http::sequence()
            ->push(['message'=>['content'=>json_encode(['evidence_sufficient'=>true,'missing_information'=>[],'notes'=>[['fact'=>'A clear plan organizes content ideas.','sources'=>[['passage_id'=>'q1']]]]])],'done'=>true,'prompt_eval_count'=>20,'eval_count'=>10])
            ->push(['message'=>['content'=>json_encode(['evidence_sufficient'=>true,'missing_information'=>[],'notes'=>[['fact'=>'A supported fact.','sources'=>[['passage_id'=>'unknown']]]]])],'done'=>true])]);
        $result=(new AiGateway)->generate($run,'research',GenerationSchema::stage('RESEARCH'),$context,30,1000);
        $citation=$result['output']['notes'][0]['sources'][0];
        $this->assertSame($id,$citation['source_id']);
        $this->assertSame($quote,$citation['supporting_excerpt']);
        Http::assertSent(fn($request)=>$request['format']['properties']['notes']['items']['properties']['sources']['items']['properties']['passage_id']['enum']===['q1']
            && $request['messages'][1]['content']!==''
            && str_contains($request['messages'][1]['content'],'exact_passage_labels'));

        try {
            (new AiGateway)->generate($run,'research',GenerationSchema::stage('RESEARCH'),$context,30,1000);
            $this->fail('A quotation that was not supplied as a label was accepted.');
        } catch (AiProviderException $e) {
            $this->assertSame('OUTPUT_INVALID',$e->reason);
        }
    }

    public function test_huggingface_lists_only_verified_free_live_text_routes(): void
    {
        $setting=AiSetting::current('huggingface');
        $setting->api_key='hf-test-token';
        Http::fake(['router.huggingface.co/v1/models'=>Http::response(['data'=>[
            ['id'=>'org/free-promo','architecture'=>['output_modalities'=>['text']],'providers'=>[
                ['provider'=>'free-host','status'=>'live','is_free'=>true],
                ['provider'=>'paid-host','status'=>'live','pricing'=>['input'=>0.2,'output'=>0.3]],
                ['provider'=>'unknown-host','status'=>'live'],
                ['provider'=>'offline-free','status'=>'error','is_free'=>true],
            ]],
            ['id'=>'org/zero-price','architecture'=>['output_modalities'=>['text']],'providers'=>[
                ['provider'=>'zero-host','status'=>'live','pricing'=>['input'=>'0','output'=>'0']],
            ]],
            ['id'=>'org/embeddings','architecture'=>['output_modalities'=>['embedding']],'providers'=>[
                ['provider'=>'free-host','status'=>'live','is_free'=>true],
            ]],
        ]])]);
        $models=(new AiGateway)->models($setting);
        $this->assertSame(['org/free-promo:free-host','org/zero-price:zero-host'],array_column($models,'id'));
        Http::assertSent(fn($request)=>str_contains($request->url(),'/v1/models')&&$request->hasHeader('Authorization','Bearer hf-test-token'));
    }

    public function test_huggingface_pins_a_free_provider_and_rechecks_before_chat(): void
    {
        $run=$this->runFor('huggingface');
        $run->input_snapshot=[...$run->input_snapshot,'provider'=>['name'=>'huggingface','model'=>'org/test-model:free-host','free_only'=>true,'strict_json'=>false]];
        $json=json_encode(['summary'=>'Plan','outline'=>['Section'],'research_urls'=>[]]);
        Http::fake([
            'router.huggingface.co/v1/models/org/test-model'=>Http::sequence()
                ->push(['providers'=>[['provider'=>'free-host','status'=>'live','is_free'=>true]]])
                ->push(['providers'=>[['provider'=>'free-host','status'=>'live','pricing'=>['input'=>1,'output'=>1]]]]),
            'router.huggingface.co/v1/chat/completions'=>Http::response(['choices'=>[['message'=>['content'=>$json],'finish_reason'=>'stop']],'usage'=>['prompt_tokens'=>8,'completion_tokens'=>4,'total_tokens'=>12]]),
        ]);
        $result=(new AiGateway)->generate($run,'plan',GenerationSchema::stage('PLAN'),[],30,1000);
        $this->assertSame('Plan',$result['output']['summary']);
        $this->assertSame(12,$result['usage']['total_tokens']);
        Http::assertSentCount(2);
        Http::assertSent(fn($request)=>str_contains($request->url(),'/chat/completions')&&$request['model']==='org/test-model:free-host'&&$request->hasHeader('Authorization','Bearer test-only-key'));

        try {
            (new AiGateway)->generate($run,'plan',GenerationSchema::stage('PLAN'),[],30,1000);
            $this->fail('A now-paid route was allowed to generate.');
        } catch (AiProviderException $e) {
            $this->assertSame('FREE_ROUTE_UNAVAILABLE',$e->reason);
        }
        Http::assertSentCount(3);
    }
}
