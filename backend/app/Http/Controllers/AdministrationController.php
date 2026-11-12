<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdministrationController extends Controller
{
    public function users(): JsonResponse
    {
        return response()->json(['users' => User::with('clients:id,name')->orderBy('name')->get()]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['sometimes', Rule::in(User::ROLES)], 'is_active' => ['sometimes', 'boolean']]);
        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages(['user' => 'Ask another administrator to change your role or deactivate your account.']);
        }
        AccountRules::exclusive(function () use ($request, $user, $data) {
            DB::transaction(function () use ($request, $user, $data) {
                $user = User::query()->lockForUpdate()->findOrFail($user->id);
                $removingAdmin = $user->isAdmin()
                    && (($data['role'] ?? $user->role) !== 'ADMIN' || ! ($data['is_active'] ?? $user->is_active));
                if ($removingAdmin && User::where('role', 'ADMIN')->where('is_active', true)->count() <= 1) {
                    throw ValidationException::withMessages(['user' => 'At least one active administrator must remain.']);
                }
                $before = $user->only(['role', 'is_active']);
                $user->fill($data)->save();
                if ($before !== $user->only(['role', 'is_active'])) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    $user->forceFill(['remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
                    Invitation::where('invited_by', $user->id)->whereNull('accepted_at')->update(['revoked_at' => now()]);
                    AuditEvent::record($request->user(), 'user.access_changed', 'USER', $user->id,
                        ['before' => $before, 'after' => $user->only(['role', 'is_active'])]);
                }
            });
        });

        return response()->json(['user' => $user->fresh()->load('clients:id,name')]);
    }

    public function assignments(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['client_ids' => ['present', 'array', 'max:100'],
            'client_ids.*' => ['required', 'uuid', 'distinct', 'exists:clients,id']]);
        DB::transaction(function () use ($request, $user, $data) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active || $user->role === 'ADMIN') {
                throw ValidationException::withMessages(['client_ids' => 'Assign clients to an active writer or reviewer. Administrators already have access to every client.']);
            }
            $previous = $user->clients()->get()->keyBy('id');
            $assignments = [];
            foreach ($data['client_ids'] as $clientId) {
                $assignments[$clientId] = ['id' => $previous->get($clientId)?->pivot->id ?? (string) Str::uuid(),
                    'assigned_by' => $previous->get($clientId)?->pivot->assigned_by ?? $request->user()->id];
            }
            $user->clients()->sync($assignments);
            AuditEvent::record($request->user(), 'client_access.changed', 'USER', $user->id,
                ['client_ids' => array_values($data['client_ids'])]);
        });

        return response()->json(['user' => $user->fresh()->load('clients:id,name')]);
    }

    public function auditEvents(): JsonResponse
    {
        return response()->json(AuditEvent::with('actor:id,name')->latest('created_at')->paginate(30));
    }

    public function recoveryLink(Request $request, User $user): JsonResponse
    {
        abort_unless($user->is_active, 422, 'Recovery links are only available for active users.');
        $token = Password::createToken($user);
        AuditEvent::record($request->user(), 'password.recovery_requested', 'USER', $user->id);

        return response()->json(['recovery_url' => rtrim(config('studio.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token, 'email' => $user->email,
        ])])->header('Cache-Control', 'no-store');
    }
}
