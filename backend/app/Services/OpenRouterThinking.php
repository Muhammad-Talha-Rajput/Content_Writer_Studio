<?php
namespace App\Services;

use Illuminate\Support\Facades\{Cache,Http};

class OpenRouterThinking
{
    public const CATALOG_KEY='ai.openrouter.thinking-catalog.v1';
    public const BUDGET=1024;

    public function catalog(): array
    {
        return Cache::remember(self::CATALOG_KEY,300,function(){
            try {
                $response=Http::acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects'=>false,'proxy'=>''])->get('https://openrouter.ai/api/v1/models');
            } catch (\Illuminate\Http\Client\ConnectionException) {
                throw new AiProviderException('MODEL_LIST','OpenRouter thinking controls could not be verified. Check the worker internet connection before continuing.');
            }
            if (!$response->successful() || !is_array($response->json('data'))) throw new AiProviderException('MODEL_LIST','OpenRouter thinking controls are unavailable. Retry model lookup before generating.');
            // Cache only routing metadata; the full catalog contains large descriptions
            // and must not be written into the application's database cache.
            return collect($response->json('data'))->map(fn($model)=>[
                'id'=>$model['id'],
                'pricing'=>array_intersect_key($model['pricing']??[],array_flip(['prompt','completion'])),
                'supported_parameters'=>$model['supported_parameters']??[],
                'reasoning'=>$model['reasoning']??null,
                'architecture'=>['output_modalities'=>$model['architecture']['output_modalities']??null],
            ])->all();
        });
    }

    public static function free(array $model): bool
    {
        $pricing=$model['pricing']??[];
        return isset($pricing['prompt'],$pricing['completion']) && is_numeric($pricing['prompt']) && is_numeric($pricing['completion']) && (float)$pricing['prompt']===0.0 && (float)$pricing['completion']===0.0;
    }

    public static function controllable(array $model): bool
    {
        if (($model['id']??'')==='openrouter/free') return true;
        $reasoning=$model['reasoning']??null;
        if (!in_array('reasoning',$model['supported_parameters']??[],true)) return $reasoning===null;
        return isset($reasoning['mandatory']);
    }

    public function route(array $profile,int $maxOutput,array $excluded=[],int $recovery=0): array
    {
        $catalog=$this->catalog();$dynamic=$profile['model']==='openrouter/free';
        if ($dynamic) {
            $models=collect($catalog)->filter(function($model)use($profile,$excluded){
                return AiGateway::textModel($model) && self::free($model) && !in_array($model['id'],$excluded,true)
                    && ($model['reasoning']['mandatory']??null)===false
                    && in_array('reasoning',$model['supported_parameters']??[],true)
                    && (!$profile['strict_json'] || in_array('response_format',$model['supported_parameters']??[],true) || in_array('structured_outputs',$model['supported_parameters']??[],true));
            })->sortByDesc(fn($model)=>in_array('structured_outputs',$model['supported_parameters']??[],true)?1:0)->take(3)->values();
            if ($models->isEmpty()) throw new AiProviderException('THINKING_ROUTE_UNAVAILABLE','No verified free model with thinking disabled is available. Refresh the free model list; completed stages are retained.');
            return ['models'=>$models->pluck('id')->all(),'reasoning'=>['enabled'=>false],'budget'=>self::BUDGET,'free_only'=>true];
        }
        $model=collect($catalog)->firstWhere('id',$profile['model']);
        if (!$model) throw new AiProviderException('THINKING_ROUTE_UNAVAILABLE','The selected model is unavailable. Refresh the model list before continuing.');
        if (($profile['free_only']??false) && !self::free($model)) throw new AiProviderException('FREE_ROUTE_UNAVAILABLE','This OpenRouter model is no longer listed at zero price. Choose a verified free model.');
        if (!self::controllable($model)) throw new AiProviderException('THINKING_UNSUPPORTED','This model does not publish usable thinking controls. Refresh the model list or use controlled free routing.');
        $reasoning=null;$budget=self::BUDGET;
        if (in_array('reasoning',$model['supported_parameters']??[],true)) {
            if (($model['reasoning']['mandatory']??null)===false) $reasoning=['enabled'=>false];
            elseif (($model['reasoning']['supports_max_tokens']??false)===true) {
                if ($maxOutput<self::BUDGET+512) throw new AiProviderException('THINKING_UNSUPPORTED','This model needs a thinking budget plus room for the answer. Use a model with thinking disabled for short content.');
                $budget=min(self::BUDGET*(2**min($recovery,2)),$maxOutput-512);
                $reasoning=['max_tokens'=>$budget];
            } else {
                $budget=null;
                $efforts=$model['reasoning']['supported_efforts']??[];
                $effort=collect(['minimal','low','medium','high','xhigh','max'])->first(fn($level)=>in_array($level,$efforts,true));
                $reasoning=$effort?['effort'=>$effort]:['enabled'=>true];
            }
        }
        return ['models'=>[$model['id']],'reasoning'=>$reasoning,'budget'=>$budget,'free_only'=>$profile['free_only']??false];
    }
}
