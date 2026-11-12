<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\User;
use App\Support\AccountRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['invitations' => Invitation::latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)], 'client_ids' => ['present', 'array', 'max:100'],
            'client_ids.*' => ['required', 'uuid', 'distinct', 'exists:clients,id']]);
        $rawToken = Str::random(64);
        $invitation = AccountRules::exclusive(fn () => DB::transaction(function () use ($request, $data, $rawToken) {
            $invitation = Invitation::updateOrCreate(['email' => $data['email']], [
                'role' => $data['role'], 'invited_by' => $request->user()->id,
                'token_hash' => hash('sha256', $rawToken),
                'client_ids' => $data['role'] === 'ADMIN' ? [] : $data['client_ids'],
                'expires_at' => now()->addDays(7), 'accepted_at' => null, 'revoked_at' => null,
            ]);
            AuditEvent::record($request->user(), 'invitation.created', 'INVITATION', $invitation->id,
                ['role' => $data['role']]);

            return $invitation;
        }));

        return response()->json(['invitation' => $invitation,
            'invitation_url' => rtrim(config('studio.frontend_url'), '/').'/accept-invitation?token='.$rawToken], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, Invitation $invitation): JsonResponse
    {
        DB::transaction(function () use ($request, $invitation) {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_if($locked->accepted_at, 409, 'This invitation has already been accepted.');
            $locked->update(['revoked_at' => now()]);
            AuditEvent::record($request->user(), 'invitation.revoked', 'INVITATION', $locked->id);
        });

        return response()->json(['message' => 'Invitation revoked.']);
    }

    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $invitation = Invitation::where('token_hash', hash('sha256', $data['token']))->first();
        abort_unless($invitation?->isUsable(), 422, 'This invitation is invalid, expired, or no longer available.');

        return response()->json(['email' => $invitation->email, 'role' => $invitation->role,
            'expires_at' => $invitation->expires_at])->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request): JsonResponse
    {
        abort_if(Auth::guard('web')->check(), 409, 'Sign out before accepting an invitation.');
        $data = $request->validate(['token' => ['required', 'string', 'size:64'],
            'name' => ['required', 'string', 'max:200'], 'password' => AccountRules::password()]);
        $user = AccountRules::exclusive(fn () => DB::transaction(function () use ($data) {
            $invitation = Invitation::where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            abort_unless($invitation?->isUsable(), 422, 'This invitation is invalid, expired, or no longer available.');
            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['email' => 'An account already exists. Sign in or request recovery.']);
            }
            $user = User::create(['name' => $data['name'], 'email' => $invitation->email,
                'password' => $data['password'], 'role' => $invitation->role, 'is_active' => true]);
            if ($user->role !== 'ADMIN') {
                foreach (Client::whereIn('id', $invitation->client_ids)->pluck('id') as $clientId) {
                    $user->clients()->attach($clientId, ['id' => (string) Str::uuid(), 'assigned_by' => $invitation->invited_by]);
                }
            }
            $invitation->update(['accepted_at' => now()]);
            AuditEvent::record($user, 'invitation.accepted', 'INVITATION', $invitation->id);

            return $user;
        }));
        $user->refresh();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->fresh()->auth_version);

        return response()->json(['user' => $user], 201);
    }
}
