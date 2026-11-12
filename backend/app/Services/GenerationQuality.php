<?php
namespace App\Services;

class GenerationQuality
{
    public static function rules(array $brief): array
    {
        $i=$brief['instructions'];$extra=$i['additional_instructions']??'';
        $rules=['primary_keyword'=>$i['primary_keyword']??'','cta'=>$i['call_to_action']??'','prefix'=>'','min_characters'=>0,'max_characters'=>null,'max_citations'=>null,'keyword_once'=>false,'no_headings'=>false];
        if ($brief['content_type']==='SOCIAL') {
            $rules['max_characters']=min((int)($i['target_characters']??2000),(int)config('content.social_limits.'.$i['social_platform'],2000));
            if (preg_match('/between\s+(\d+)\s+(?:and|to)\s+(\d+)\s+characters/i',$extra,$m) || preg_match('/(\d+)\s*[-\x{2013}]\s*(\d+)\s+characters/iu',$extra,$m)) {
                $rules['min_characters']=(int)$m[1];$rules['max_characters']=min($rules['max_characters'],(int)$m[2]);
            }
            $rules['no_headings']=(bool)preg_match('/(?:ONE\s+\w+\s+caption|Do not write an article)/i',$extra);
        }
        if (preg_match('/^\s*(?:Start|Begin) with\s*:\s*(.+)$/im',$extra,$m)) $rules['prefix']=trim($m[1]," \t\r\n\"'");
        if (preg_match('/(?:maximum\s+(\d+)\s+citations?|citations?\s+(?:is\s+)?maximum\s+(\d+))/i',$extra,$m)) $rules['max_citations']=(int)(($m[1]??'')!==''?$m[1]:$m[2]);
        $rules['keyword_once']=$rules['primary_keyword']!=='' && mb_stripos($extra,$rules['primary_keyword'])!==false && (bool)preg_match('/(?:exactly|naturally)\s+once|once\s+only/i',$extra);
        if ($rules['max_characters']!==null && $rules['min_characters']>$rules['max_characters']) throw new AiProviderException('BRIEF_CONFLICT','The requested minimum character length exceeds the target/platform maximum. Correct the brief before generating.');
        return $rules;
    }

    public static function measurements(array $draft): array
    {
        $text=implode("\n",array_column($draft['blocks'],'text'));
        return ['text'=>$text,'characters'=>mb_strlen($text),'words'=>preg_match_all('/[\p{L}\p{N}]+(?:[\x27\x{2019}-][\p{L}\p{N}]+)*/u',$text),'citations'=>array_sum(array_map(fn($b)=>count($b['citations']??[]),$draft['blocks']))];
    }

    public static function findings(array $brief,array $draft,array $quality,array $sources): array
    {
        $rules=self::rules($brief);$measure=self::measurements($draft);$text=$measure['text'];$i=$brief['instructions'];$items=[];
        $add=function($code,$message,$severity='HARD')use(&$items){$items[]=['code'=>$code,'message'=>$message,'severity'=>$severity,'origin'=>'DETERMINISTIC'];};
        if ($brief['content_type']==='SOCIAL') {
            if ($measure['characters']<$rules['min_characters'] || $measure['characters']>$rules['max_characters']) $add('CHARACTER_RANGE','Caption has '.$measure['characters'].' characters; required range: '.$rules['min_characters'].'-'.$rules['max_characters'].'.');
            if ($rules['no_headings'] && collect($draft['blocks'])->contains('kind','HEADING')) $add('FORMAT','Write a caption without article headings.');
        } elseif (isset($i['target_words']) && abs($measure['words']-$i['target_words'])>$i['target_words']*0.1) $add('WORD_TARGET','Draft has '.$measure['words'].' words; requested target: '.$i['target_words'].' (10% tolerance).');
        if ($rules['prefix']!=='' && !str_starts_with($text,$rules['prefix'])) $add('REQUIRED_PREFIX','Start exactly with: '.$rules['prefix']);
        if ($rules['primary_keyword']!=='') {
            $count=substr_count(mb_strtolower($text),mb_strtolower($rules['primary_keyword']));
            if ($count===0 || ($rules['keyword_once'] && $count!==1)) $add('PRIMARY_KEYWORD','Use the exact phrase "'.$rules['primary_keyword'].'"'.($rules['keyword_once']?' exactly once.':' in the draft.'));
        }
        if ($rules['cta']!=='' && !str_ends_with(mb_strtolower(rtrim($text," \t\r\n.!?")),mb_strtolower(rtrim(trim($rules['cta']),".!?")))) $add('CTA','End with the requested call to action: '.$rules['cta']);
        if ($rules['max_citations']!==null && $measure['citations']>$rules['max_citations']) $add('CITATION_COUNT','Draft has '.$measure['citations'].' citations; maximum: '.$rules['max_citations'].'.');
        $guidelines=$brief['brand_snapshot']['profile']['guidelines']??[];
        foreach ($guidelines['prohibited_terms']??[] as $term) if ($term!=='' && mb_stripos($text,$term)!==false) $add('PROHIBITED_TERM','Remove prohibited wording: '.$term);
        foreach ($guidelines['preferred_terms']??[] as $term) if ($term!=='' && mb_stripos($text,$term)===false) $add('PREFERRED_TERM','Preferred wording is missing: '.$term,'ADVISORY');
        if ($brief['content_type']==='BLOG' && !collect($draft['blocks'])->contains('kind','HEADING')) $add('BLOG_HEADINGS','The blog needs section headings.');
        if ($brief['content_type']==='EMAIL' && !trim($draft['email_subject'])) $add('EMAIL_SUBJECT','An email subject is required.');
        if (collect($draft['blocks'])->contains(fn($b)=>$b['kind']!=='HEADING' && !$b['citations'])) $add('UNCITED_BLOCKS','Review uncited passages for factual claims.','ADVISORY');
        if (collect($sources)->contains(fn($s)=>$s['context_limited']??false)) $add('SOURCE_CONTEXT','Only part of the selected source text fit this run. Review source coverage.','ADVISORY');
        $measured=['CHARACTER_RANGE','WORD_TARGET','PRIMARY_KEYWORD','REQUIRED_PREFIX','CTA','CITATION_COUNT','FORMAT','PROHIBITED_TERM'];
        foreach ($quality['findings']??[] as $finding) {
            // Passing checks are not issues. Measurable checks are decided by code,
            // not an approximate or contradictory model count.
            if (!($finding['is_violation']??false) || in_array($finding['code'],$measured,true)) continue;
            unset($finding['is_violation']);$items[]=$finding+['origin'=>'AI_JUDGMENT'];
        }
        return $items;
    }
}
