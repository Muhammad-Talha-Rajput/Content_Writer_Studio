<?php
namespace App\Services;
class GenerationInput
{
    public static function sources(iterable $sources, int $characters): array
    {
        $result=[]; $used=0;
        foreach ($sources as $source) $result[]=['id'=>$source->id,'title'=>$source->title,'url'=>$source->final_url,'hash'=>$source->content_hash,'segments'=>[],'available_segments'=>$source->segments??[]];
        // Round-robin selection gives each selected source context before filling the remainder.
        for ($i=0;$i<500;$i++) {
            $found=false;
            foreach ($result as &$row) {
                $segment=$row['available_segments'][$i]??null;
                if (!$segment) continue; $found=true;
                if ($used+mb_strlen($segment['text'])>$characters) continue;
                $row['segments'][]=$segment; $used+=mb_strlen($segment['text']);
            }
            unset($row);
            if (!$found) break;
        }
        foreach ($result as &$row) { $row['context_limited']=count($row['segments'])<count($row['available_segments']); unset($row['available_segments']); }
        return $result;
    }
}
