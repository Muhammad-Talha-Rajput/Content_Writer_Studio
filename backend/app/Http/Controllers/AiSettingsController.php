<?php
namespace App\Http\Controllers;
use App\Models\AiSetting;
use App\Models\AuditEvent;
use App\Services\AiGateway;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiSettingsController extends Controller
{
    public function index()
    {
        return response()->json(['profiles'=>array_map(function ($name) {
            $profile = AiSetting::current($name);
            return $profile->toArray() + ['has_key'=> $profile->key() !== '', 'ready'=>$profile->enabled && $profile->model !== '' && ($name === 'ollama' || $profile->key() !== '')];
        }, ['openrouter','gemini','ollama','huggingface'])]);
    }
    public function update(Request $request, string $provider)
    {
        $data = $request->validate(['version'=>['required','integer','min:1'], 'model'=>['nullable','string','max:200','regex:/^[a-zA-Z0-9_:.\/\-]+$/'],
            'api_key'=>['nullable','string','max:500','regex:/^[^\s]+$/'], 'clear_key'=>['sometimes','boolean'], 'enabled'=>['required','boolean'],
            'endpoint'=>['required','string','max:200'], 'strict_json'=>['required','boolean'], 'free_only'=>['required','boolean'],
            'limits'=>['required','array:user_daily_runs,organization_daily_runs,active_runs,duration_seconds,max_tokens,max_output_tokens,context_characters,revision_passes,search_results'],
            'limits.user_daily_runs'=>['required','integer','between:1,1000'], 'limits.organization_daily_runs'=>['required','integer','between:1,10000'],
            'limits.active_runs'=>['required','integer','between:1,5'], 'limits.duration_seconds'=>['required','integer','between:60,1800'],
            'limits.max_tokens'=>['required','integer','between:2000,100000'], 'limits.max_output_tokens'=>['required','integer','between:512,16000'],
            'limits.context_characters'=>['required','integer','between:2000,60000'], 'limits.revision_passes'=>['required','integer','between:0,2'],
            'limits.search_results'=>['required','integer','between:0,3']]);
        try { AiGateway::localEndpoint($data['endpoint']); }
        catch (\App\Services\AiProviderException $e) { abort(422, $e->getMessage()); }
        abort_if($provider==='huggingface' && !($data['free_only']??false),422,'Hugging Face is locked to verified zero-cost routes.');
        abort_if($provider==='openrouter' && !AiGateway::textModel(['id'=>$data['model']??'']),422,'Choose a text-generation model. Reranking, embedding and safety classifiers cannot write drafts.');
        $profile = DB::transaction(function () use ($request, $provider, $data) {
            $profile = AiSetting::current($provider);
            $profile = AiSetting::lockForUpdate()->findOrFail($profile->id);
            WorkspaceAccess::version($profile, (int)$data['version']);
            $values = collect($data)->except(['version','api_key','clear_key'])->all();
            $values['model'] = $data['model'] ?? '';
            if ($provider==='huggingface') { $values['free_only']=true; $values['strict_json']=false; }
            if ($data['clear_key'] ?? false) $values['api_key'] = null;
            elseif (!empty($data['api_key'])) $values['api_key'] = $data['api_key'];
            $profile->fill($values); $profile->version++; $profile->save();
            AuditEvent::record($request->user(), 'ai.settings_changed', 'AI_SETTINGS', null, ['provider'=>$provider,'model'=>$profile->model,'enabled'=>$profile->enabled]);
            return $profile;
        });
        return response()->json(['profile'=>$profile, 'has_key'=>$profile->key() !== '']);
    }
    public function models(Request $request, string $provider, AiGateway $gateway)
    {
        $data=$request->validate(['api_key'=>['nullable','string','max:500','regex:/^[^\s]+$/']]);
        $profile=AiSetting::current($provider);
        if (!empty($data['api_key'])) $profile->api_key=$data['api_key']; // In-memory lookup only; save remains explicit.
        try { return response()->json(['models'=>$gateway->models($profile)]); }
        catch (\App\Services\AiProviderException $e) { abort(422, $e->getMessage()); }
    }
}
