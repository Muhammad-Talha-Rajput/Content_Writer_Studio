<?php

namespace App\Http\Requests;

use App\Rules\PublicWebUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Resource authorization is performed against the locked parent in the controller.
    }

    public function rules(): array
    {
        $social = $this->input('content_type') === 'SOCIAL';
        $blog = $this->input('content_type') === 'BLOG';
        $platform = $this->input('social_platform');
        $cap = is_string($platform) ? (config('content.social_limits')[$platform] ?? 2000) : 2000;

        return [
            'version' => [$this->isMethod('POST') ? 'sometimes' : 'required', 'integer', 'min:1'],
            'topic' => ['required', 'string', 'min:5', 'max:200'],
            'content_type' => ['required', Rule::in(array_keys(config('content.types')))],
            'objective' => ['required', Rule::in(array_keys(config('content.objectives')))],
            'audience' => ['required', 'string', 'min:10', 'max:500'],
            'language' => ['required', Rule::in(config('content.languages'))],
            'tone' => ['required', 'string', 'max:300'],
            'target_words' => [Rule::excludeIf($social), 'required', 'integer', 'min:'.($blog ? 300 : 50), 'max:'.($blog ? 2500 : 1500)],
            'social_platform' => [Rule::excludeIf(! $social), 'required', Rule::in(array_keys(config('content.social_limits')))],
            'target_characters' => [Rule::excludeIf(! $social), 'required', 'integer', 'min:1', 'max:'.$cap],
            'primary_keyword' => ['nullable', 'string', 'max:100'],
            'secondary_keywords' => ['present', 'array', 'max:10'],
            'secondary_keywords.*' => ['bail', 'required', 'string', 'max:100', 'distinct:ignore_case'],
            'call_to_action' => ['nullable', 'string', 'max:300'],
            'talking_points' => ['nullable', 'string', 'max:5000'],
            'additional_instructions' => ['nullable', 'string', 'max:5000'],
            'reference_urls' => ['present', 'array', 'max:10'],
            'reference_urls.*' => ['bail', 'required', 'string', 'max:2048', 'distinct', new PublicWebUrl],
            'research_mode' => ['required', Rule::in(['PROVIDED_ONLY', 'PUBLIC_WEB'])],
            'reviewer_id' => ['nullable', 'uuid', 'exists:users,id'],
            'refresh_brand' => ['sometimes', 'boolean'],
            'project_id' => ['prohibited'], 'client_id' => ['prohibited'], 'status' => ['prohibited'], 'created_by' => ['prohibited'],
        ];
    }
}
