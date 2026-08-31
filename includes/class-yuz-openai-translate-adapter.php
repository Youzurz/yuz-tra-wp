<?php
defined('ABSPATH') || exit;

/** Real OpenAI-compatible Chat Completions adapter. Never falls back on failure. */
final class YUZ_OpenAI_Translate_Adapter implements \YUZTRA\Interfaces\TranslateAdapterInterface {
    public function translate(string $text, string $source_lang, string $target_lang, array $settings): ?string {
        $key = (string) ($settings['api_key'] ?? '');
        $endpoint = (string) ($settings['endpoint'] ?? 'https://api.openai.com/v1/chat/completions');
        $model = (string) ($settings['model'] ?? '');
        if ($key === '' || $model === '' || !preg_match('#^https://#i', $endpoint)) throw new RuntimeException('openai_configuration_required');
        $schema = ['type'=>'object','properties'=>['translation'=>['type'=>'string']],'required'=>['translation'],'additionalProperties'=>false];
        $body = ['model'=>$model,'temperature'=>0,'stream'=>false,
            'response_format'=>['type'=>'json_schema','json_schema'=>['name'=>'translation','strict'=>true,'schema'=>$schema]],
            'messages'=>[
                ['role'=>'system','content'=>'Translate faithfully between the exact locales. Preserve placeholders, HTML, numbers, negation and meaning. Return only JSON matching the requested schema.'],
                ['role'=>'user','content'=>wp_json_encode(['source_locale'=>$source_lang,'target_locale'=>$target_lang,'text'=>$text],JSON_UNESCAPED_UNICODE)],
            ]];
        YUZ_Translation_Budget::assert_affordable((int) ceil(strlen($text) / 4), 512);
        $started = microtime(true);
        $response = wp_remote_post($endpoint,['timeout'=>min(120,max(5,(int)($settings['provider_timeout'] ?? 45))),'redirection'=>0,'limit_response_size'=>200000,
            'headers'=>['Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
        if (is_wp_error($response)) throw new RuntimeException('openai_transport_failed');
        if (wp_remote_retrieve_response_code($response)!==200) throw new RuntimeException('openai_http_'.wp_remote_retrieve_response_code($response));
        $payload=json_decode(wp_remote_retrieve_body($response),true);
        $usage=$payload['usage'] ?? [];
        if (isset($usage['prompt_tokens'],$usage['completion_tokens'])) YUZ_Translation_Budget::record_metrics((int)$usage['prompt_tokens'],(int)$usage['completion_tokens'],(int)round(1000*(microtime(true)-$started)));
        $content=$payload['choices'][0]['message']['content'] ?? '';
        $decoded=json_decode((string)$content,true);
        if (!is_array($decoded) || !isset($decoded['translation']) || !is_string($decoded['translation']) || trim($decoded['translation'])==='') throw new RuntimeException('openai_invalid_translation');
        return $decoded['translation'];
    }
    public function test_api_conn(array $settings): bool {
        $key=(string)($settings['api_key']??''); $endpoint=(string)($settings['endpoint']??'https://api.openai.com/v1/chat/completions');
        if ($key==='' || !preg_match('#^https://#i',$endpoint)) return false;
        $base=preg_replace('#/chat/completions/?$#','',$endpoint);
        $response=wp_remote_get(rtrim($base,'/').'/models',['timeout'=>10,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$key]]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return false;
        $models=json_decode(wp_remote_retrieve_body($response),true);
        foreach (($models['data']??[]) as $model) if (($model['id']??'')===(string)($settings['model']??'')) return true;
        return false;
    }
}
