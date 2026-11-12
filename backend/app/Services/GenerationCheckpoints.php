<?php
namespace App\Services;

use App\Models\AgentRun;
use Illuminate\Support\Collection;

class GenerationCheckpoints
{
    public static function collect(AgentRun $run): Collection
    {
        $checkpoints=collect(); $seen=[]; $briefId=$run->brief_id;
        // Older workers could overwrite a reused checkpoint while retrying it.
        // Recover its last valid result from the linked attempts instead.
        for ($depth=0;$run && $depth<100 && !isset($seen[$run->id]);$depth++) {
            if ($run->brief_id!==$briefId) break;
            $seen[$run->id]=true;
            foreach ($run->steps()->whereIn('status',['SUCCEEDED','REUSED'])->whereNotNull('artifacts')->orderByDesc('attempt_no')->get() as $step) {
                $key=$step->step_type.':'.$step->pass_no;
                if ($checkpoints->has($key)) continue;
                $artifact=$step->artifacts;
                if ($step->step_type==='RESEARCH') unset($artifact['source_snapshots'],$artifact['retrieval_warnings']);
                if ($step->step_type==='QUALITY') unset($artifact['gate_findings'],$artifact['measurements'],$artifact['candidate']);
                try { GenerationSchema::validate($artifact,GenerationSchema::stage($step->step_type)); }
                catch (AiProviderException) { continue; }
                $checkpoints->put($key,$step);
            }
            $parent=$run->input_snapshot['continuation_from_run_id']??null;
            $run=$parent?AgentRun::find($parent):null;
        }
        return $checkpoints;
    }
}
