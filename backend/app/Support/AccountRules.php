<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountRules
{
    public static function password(): array
    {
        return ['bail', 'required', 'string', 'confirmed', Password::min(12),
            function (string $attribute, mixed $value, Closure $fail) {
                if (strlen($value) > 72) {
                    $fail('This password is too long. Use a shorter password.');
                }
            }];
    }

    public static function setupAvailable(Request $request): bool
    {
        return app()->environment(['local', 'testing'])
            && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            && ! User::query()->exists();
    }

    public static function exclusive(Closure $action): mixed
    {
        try {
            return Cache::lock('studio-account-administration', 15)->block(5, $action);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'account' => 'Another account change is in progress. Please try again.',
            ]);
        }
    }
}
