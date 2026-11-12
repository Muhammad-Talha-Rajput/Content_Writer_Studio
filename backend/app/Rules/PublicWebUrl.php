<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicWebUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = is_string($value) ? parse_url($value) : false;
        $host = strtolower(trim($url['host'] ?? '', '[]'));
        $invalidHost = ! str_contains($host, '.') || $host === 'localhost'
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $invalidHost = ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)
            || ! in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || $invalidHost) {
            $fail('Use a public HTTP or HTTPS URL without login credentials.');
        }
    }
}
