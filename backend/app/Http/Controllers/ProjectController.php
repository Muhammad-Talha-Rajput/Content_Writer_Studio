<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => ['nullable', 'uuid']]);
        $query = Project::visibleTo($request->user())->with('client:id,name,is_archived')->withCount('briefs');
        if (! empty($data['client_id'])) {
            $client = Client::findOrFail($data['client_id']);
            Gate::authorize('view', $client);
            $query->where('client_id', $client->id);
        }

        return response()->json(['projects' => $query->latest('updated_at')->get()]);
    }

    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return response()->json(['project' => $project->load(['client.brandProfile', 'briefs' => fn ($query) => $query->latest('updated_at')])]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'client_id' => ['prohibited']]);
        $project = DB::transaction(function () use ($request, $client, $data) {
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            WorkspaceAccess::editable($request->user(), $client);
            $project = Project::create([...$data, 'client_id' => $client->id, 'created_by' => $request->user()->id]);
            AuditEvent::record($request->user(), 'project.created', 'PROJECT', $project->id, ['label' => $project->name]);

            return $project->fresh();
        });

        return response()->json(['project' => $project], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'is_archived' => ['sometimes', 'boolean'], 'client_id' => ['prohibited'], 'created_by' => ['prohibited']]);
        $project = DB::transaction(function () use ($request, $project, $data) {
            $client = Client::query()->lockForUpdate()->findOrFail($project->client_id);
            WorkspaceAccess::editable($request->user(), $client);
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            WorkspaceAccess::version($project, (int) $data['version']);
            abort_if($project->is_archived && ($data['is_archived'] ?? true), 409, 'Restore this project before editing it.');
            $project->fill(collect($data)->except('version')->all());
            $project->version++;
            $project->save();
            AuditEvent::record($request->user(), 'project.updated', 'PROJECT', $project->id, ['is_archived' => $project->is_archived, 'label' => $project->name]);

            return $project;
        });

        return response()->json(['project' => $project]);
    }
}
