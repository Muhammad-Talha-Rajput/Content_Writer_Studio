<?php
namespace App\Services;
class GenerationSchema
{
    private static function text(int $max = 2000): array { return ['type'=>'string','maxLength'=>$max]; }
    private static function listOf(array $item, int $max = 40): array { return ['type'=>'array','items'=>$item,'maxItems'=>$max]; }
    private static function object(array $properties): array { return ['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false]; }
    public static function citation(): array { return self::object(['source_id'=>self::text(36),'segment_ordinal'=>['type'=>'integer','minimum'=>1], 'supporting_excerpt'=>self::text(400)]); }
    public static function stage(string $stage): array
    {
        return match ($stage) {
            'PLAN'=>self::object(['summary'=>self::text(), 'outline'=>self::listOf(self::text(300),15), 'research_urls'=>self::listOf(self::text(2048),3)]),
            'RESEARCH'=>self::object(['evidence_sufficient'=>['type'=>'boolean'], 'missing_information'=>self::listOf(self::text(500),10), 'notes'=>self::listOf(self::object(['fact'=>self::text(1000),'sources'=>self::listOf(self::citation(),5)]),30)]),
            'WRITE'=>self::object(['title'=>self::text(200),'blocks'=>self::listOf(self::object(['id'=>self::text(60),'kind'=>['type'=>'string','enum'=>['HEADING','PARAGRAPH','BULLET']], 'text'=>self::text(6000), 'citations'=>self::listOf(self::citation(),5)]),100), 'email_subject'=>self::text(300),'preview_text'=>self::text(300),'seo_title'=>self::text(300),'meta_description'=>self::text(500)]),
            'REVISE'=>self::object(['edits'=>self::listOf(self::object(['block_id'=>self::text(60),'operation'=>['type'=>'string','enum'=>['REPLACE_TEXT','PREPEND_TEXT','APPEND_TEXT','REMOVE_BLOCK','SET_KIND','SET_CITATIONS']],'find'=>self::text(6000),'text'=>self::text(6000),'citations'=>self::listOf(self::citation(),5)]),30),'metadata_edits'=>self::listOf(self::object(['field'=>['type'=>'string','enum'=>['title','email_subject','preview_text','seo_title','meta_description']],'value'=>self::text(500)]),5)]),
            'QUALITY'=>self::object(['needs_revision'=>['type'=>'boolean'],'findings'=>self::listOf(self::object(['code'=>['type'=>'string','enum'=>['CHARACTER_RANGE','WORD_TARGET','PRIMARY_KEYWORD','REQUIRED_PREFIX','CTA','CITATION_COUNT','FORMAT','PROHIBITED_TERM','FACT_SUPPORT','TALKING_POINT_COVERAGE','TONE','STYLE','AUDIENCE','OBJECTIVE','OTHER']],'is_violation'=>['type'=>'boolean'],'severity'=>['type'=>'string','enum'=>['ADVISORY','HARD']], 'message'=>self::text(1000),'block_id'=>self::text(60)]),30)]),
        };
    }
    public static function validate(mixed $value, array $schema, string $path = '$'): void
    {
        if (is_string($value) && mb_strlen($value)>($schema['maxLength']??6000)) throw new AiProviderException('OUTPUT_INVALID','Text at '.$path.' has '.mb_strlen($value).' characters; the limit is '.($schema['maxLength']??6000).'.');
        $valid = match ($schema['type']) { 'object'=>is_array($value) && !array_is_list($value), 'array'=>is_array($value) && array_is_list($value), 'string'=>is_string($value) && mb_check_encoding($value, 'UTF-8'), 'integer'=>is_int($value), 'boolean'=>is_bool($value), default=>false };
        if (!$valid || isset($schema['enum']) && !in_array($value,$schema['enum'],true)
            || is_int($value) && $value<($schema['minimum']??0)) throw new AiProviderException('OUTPUT_INVALID','The model output does not match the required structure at '.$path.' (expected '.$schema['type'].').');
        if ($schema['type']==='object') {
            if (array_diff(array_keys($value),array_keys($schema['properties'])) || array_diff($schema['required'],array_keys($value))) throw new AiProviderException('OUTPUT_INVALID','The model output has missing or unexpected fields at '.$path.'.');
            foreach ($schema['properties'] as $key=>$child) self::validate($value[$key],$child,$path.'.'.$key);
        } elseif ($schema['type']==='array') {
            if (count($value)>($schema['maxItems']??100)) throw new AiProviderException('OUTPUT_INVALID','The model output at '.$path.' has '.count($value).' items; the limit is '.($schema['maxItems']??100).'.');
            foreach ($value as $index=>$item) self::validate($item,$schema['items'],$path.'['.$index.']');
        }
    }
}
