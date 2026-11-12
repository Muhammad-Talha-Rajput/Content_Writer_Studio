<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $user = $request->user('web');
        if ($user && (! $user->is_active || (int) $request->session()->get('auth_version', -1) !== $user->auth_version)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $user = null;
        }

        return response()->json(['user' => $user, 'setup_available' => AccountRules::setupAvailable($request),
            'local_mail' => config('mail.default') === 'log'])->header('Cache-Control', 'no-store');
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email:rfc', 'max:254'], 'password' => AccountRules::password()]);
        $user = AccountRules::exclusive(function () use ($request, $data) {
            abort_unless(AccountRules::setupAvailable($request), 409, 'Administrator setup is no longer available.');

            return DB::transaction(function () use ($data) {
                $user = User::create([...$data, 'role' => 'ADMIN', 'is_active' => true]);
                AuditEvent::record($user, 'administrator.created', 'USER', $user->id);

                return $user;
            });
        });
        $user->refresh();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->fresh()->auth_version);

        return response()->json(['user' => $user], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024']]);
        $data['email'] = mb_strtolower(trim($data['email']));
        if (! Auth::guard('web')->attempt([...$data, 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        $request->session()->put('auth_version', $user->auth_version);
        AuditEvent::record($user, 'session.started', 'USER', $user->id);

        return response()->json(['user' => $user]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditEvent::record($request->user(), 'session.ended', 'USER', $request->user()->id);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'You have been signed out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($data['email'])), 'is_active' => true]);

        return response()->json(['message' => 'If an active account matches that address, recovery instructions have been requested.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'max:256'], 'password' => AccountRules::password()]);
        $data['email'] = mb_strtolower(trim($data['email']));
        $status = DB::transaction(function () use ($data) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset([...$data, 'is_active' => true], function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60),
                    'auth_version' => $user->auth_version + 1])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                AuditEvent::record($user, 'password.reset', 'USER', $user->id);
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This recovery link is invalid or has expired. Request a new link.']);
        }
        if ($request->user('web')?->email === $data['email']) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Your password was reset. Sign in with your new password.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['bail', 'required', 'string', 'max:1024', 'current_password:web'],
            'password' => AccountRules::password()]);
        DB::transaction(function () use ($request, $data) {
            $user = $request->user();
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60),
                'auth_version' => $user->auth_version + 1])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditEvent::record($user, 'password.changed', 'USER', $user->id);
        });
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Password changed. Sign in again on each device.']);
    }
}
