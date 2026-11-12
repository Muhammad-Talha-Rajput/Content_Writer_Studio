<?php
namespace App\Services;

class DraftRepair
{
    public static function apply(array $draft,array $patch): array
    {
        $result=$draft;
        foreach ($patch['edits'] as $edit) {
            $index=null;
            foreach ($result['blocks'] as $key=>$block) if ($block['id']===$edit['block_id']) {$index=$key;break;}
            if ($index===null) throw new AiProviderException('REPAIR_INVALID','The correction targeted an unknown content block. The original candidate is retained.');
            $block=&$result['blocks'][$index];
            switch ($edit['operation']) {
                case 'REPLACE_TEXT':
                    if ($edit['find']==='' || substr_count($block['text'],$edit['find'])!==1) throw new AiProviderException('REPAIR_INVALID','A correction must identify one exact text occurrence. The original candidate is retained.');
                    $block['text']=str_replace($edit['find'],$edit['text'],$block['text']);break;
                case 'PREPEND_TEXT': $block['text']=$edit['text'].$block['text'];break;
                case 'APPEND_TEXT': $block['text'].=$edit['text'];break;
                case 'SET_KIND':
                    if (!in_array($edit['text'],['HEADING','PARAGRAPH','BULLET'],true)) throw new AiProviderException('REPAIR_INVALID','Invalid content-block kind in correction.');
                    $block['kind']=$edit['text'];break;
                case 'SET_CITATIONS': $block['citations']=$edit['citations'];break;
                case 'REMOVE_BLOCK': unset($result['blocks'][$index]);break;
                default: throw new AiProviderException('REPAIR_INVALID','Unsupported correction operation.');
            }
            unset($block);
        }
        $result['blocks']=array_values($result['blocks']);
        foreach ($patch['metadata_edits'] as $edit) {
            if (!in_array($edit['field'],['title','email_subject','preview_text','seo_title','meta_description'],true)) throw new AiProviderException('REPAIR_INVALID','Unsupported metadata correction.');
            $result[$edit['field']]=$edit['value'];
        }
        GenerationSchema::validate($result,GenerationSchema::stage('WRITE'));
        return $result;
    }
}
