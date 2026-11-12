<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\User;
use App\Rules\PublicWebUrl;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['clients' => Client::visibleTo($request->user())->orderBy('name')->get()]);
    }

    public function show(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        return response()->json(['client' => $client->load('brandProfile')->loadCount('projects')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'],
            'industry' => ['nullable', 'string', 'max:200'], 'website' => ['bail', 'nullable', 'string', 'max:2048', new PublicWebUrl]]);
        $client = DB::transaction(function () use ($request, $data) {
            $client = Client::create($data);
            AuditEvent::record($request->user(), 'client.created', 'CLIENT', $client->id);

            return $client;
        });

        return response()->json(['client' => $client->fresh()], 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('update', $client);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:200'], 'industry' => ['nullable', 'string', 'max:200'],
            'website' => ['bail', 'nullable', 'string', 'max:2048', new PublicWebUrl], 'is_archived' => ['sometimes', 'boolean']]);
        $client = DB::transaction(function () use ($request, $client, $data) {
            $locked = Client::query()->lockForUpdate()->findOrFail($client->id);
            WorkspaceAccess::version($locked, (int) $data['version']);
            abort_if($locked->is_archived && ($data['is_archived'] ?? true), 409, 'Restore this client before editing it.');
            $locked->fill(collect($data)->except('version')->all());
            $locked->version++;
            $locked->save();
            AuditEvent::record($request->user(), 'client.updated', 'CLIENT', $locked->id,
                ['is_archived' => $locked->is_archived]);

            return $locked;
        });

        return response()->json(['client' => $client->load('brandProfile')]);
    }

    public function reviewers(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('view', $client);
        $reviewers = User::where('is_active', true)->whereIn('role', ['ADMIN', 'REVIEWER'])
            ->where('id', '!=', $request->user()->id)
            ->where(fn ($query) => $query->where('role', 'ADMIN')
                ->orWhereHas('clients', fn ($clients) => $clients->where('clients.id', $client->id)))
            ->orderBy('name')->get(['id', 'name', 'role']);

        return response()->json(['reviewers' => $reviewers]);
    }
}
