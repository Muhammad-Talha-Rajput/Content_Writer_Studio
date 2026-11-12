<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveBriefRequest;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ContentBrief;
use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ContentBriefController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(config('content'));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['briefs' => ContentBrief::visibleTo($request->user())->with('project.client:id,name,is_archived')->latest('updated_at')->get()]);
    }

    public function show(ContentBrief $brief): JsonResponse
    {
        Gate::authorize('view', $brief);

        return response()->json(['brief' => $brief->load('project.client.brandProfile')]);
    }

    private function snapshot(Client $client): array
    {
        $client->load('brandProfile');

        return ['client' => $client->only(['id', 'name', 'industry', 'website']),
            'profile' => $client->brandProfile?->toArray(), 'captured_at' => now()->toIso8601String()];
    }

    private function instructions(array $data): array
    {
        $instructions = collect($data)->only(['objective', 'audience', 'language', 'tone', 'target_words', 'social_platform',
            'target_characters', 'primary_keyword', 'secondary_keywords', 'call_to_action', 'talking_points',
            'additional_instructions', 'reference_urls', 'research_mode', 'reviewer_id'])->all();
        foreach (['target_words', 'target_characters'] as $key) {
            if (isset($instructions[$key])) {
                $instructions[$key] = (int) $instructions[$key];
            }
        }

        return $instructions;
    }

    private function validateReviewer(Request $request, Client $client, array $data, ?ContentBrief $brief = null): void
    {
        if (empty($data['reviewer_id'])) {
            return;
        }
        $reviewer = User::find($data['reviewer_id']);
        if (! $reviewer?->is_active || ! in_array($reviewer->role, ['ADMIN', 'REVIEWER'], true)
            || in_array($reviewer->id, array_filter([$request->user()->id, $brief?->created_by]), true)
            || ! WorkspaceAccess::view($reviewer, $client)) {
            throw ValidationException::withMessages(['reviewer_id' => 'Choose a different active reviewer or administrator with access to this client.']);
        }
    }

    public function store(SaveBriefRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $data = $request->validated();
        $brief = DB::transaction(function () use ($request, $project, $data) {
            $client = Client::query()->lockForUpdate()->findOrFail($project->client_id);
            WorkspaceAccess::editable($request->user(), $client);
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_if($project->is_archived, 409, 'Restore this project before adding a brief.');
            $this->validateReviewer($request, $client, $data);
            $brief = ContentBrief::create(['project_id' => $project->id, 'created_by' => $request->user()->id,
                'topic' => $data['topic'], 'content_type' => $data['content_type'],
                'instructions' => $this->instructions($data),
                'brand_snapshot' => $this->snapshot($client)]);
            AuditEvent::record($request->user(), 'brief.created', 'BRIEF', $brief->id, ['label' => $brief->topic]);

            return $brief->fresh()->load('project.client.brandProfile');
        });

        return response()->json(['brief' => $brief], 201);
    }

    public function update(SaveBriefRequest $request, ContentBrief $brief): JsonResponse
    {
        Gate::authorize('view', $brief);
        $data = $request->validated();
        $brief = DB::transaction(function () use ($request, $brief, $data) {
            $client = Client::query()->lockForUpdate()->findOrFail($brief->project->client_id);
            WorkspaceAccess::editable($request->user(), $client);
            $project = Project::query()->lockForUpdate()->findOrFail($brief->project_id);
            $brief = ContentBrief::query()->lockForUpdate()->findOrFail($brief->id);
            WorkspaceAccess::version($brief, (int) $data['version']);
            abort_if($project->is_archived || $brief->is_archived, 409, 'Restore the project and brief before editing.');
            abort_unless(in_array($brief->status, ['DRAFT', 'CHANGES_REQUESTED', 'READY_FOR_REVIEW'], true), 409, 'This brief cannot be edited at its current workflow stage.');
            $this->validateReviewer($request, $client, $data, $brief);
            $brief->fill(['topic' => $data['topic'], 'content_type' => $data['content_type'],
                'instructions' => $this->instructions($data)]);
            if ($data['refresh_brand'] ?? false) {
                $brief->brand_snapshot = $this->snapshot($client);
            }
            $brief->version++;
            $brief->status = 'DRAFT';
            $brief->save();
            AuditEvent::record($request->user(), 'brief.updated', 'BRIEF', $brief->id, ['label' => $brief->topic]);

            return $brief->load('project.client.brandProfile');
        });

        return response()->json(['brief' => $brief]);
    }

    public function archive(Request $request, ContentBrief $brief): JsonResponse
    {
        Gate::authorize('view', $brief);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'is_archived' => ['required', 'boolean']]);
        $brief = DB::transaction(function () use ($request, $brief, $data) {
            $client = Client::query()->lockForUpdate()->findOrFail($brief->project->client_id);
            WorkspaceAccess::editable($request->user(), $client);
            $project = Project::query()->lockForUpdate()->findOrFail($brief->project_id);
            abort_if($project->is_archived, 409, 'Restore the project before changing its briefs.');
            $brief = ContentBrief::query()->lockForUpdate()->findOrFail($brief->id);
            WorkspaceAccess::version($brief, (int) $data['version']);
            abort_if($brief->status === 'IN_PROGRESS', 409, 'Cancel the active run before archiving.');
            $brief->is_archived = $data['is_archived'];
            $brief->version++;
            $brief->save();
            AuditEvent::record($request->user(), 'brief.archived_changed', 'BRIEF', $brief->id, ['is_archived' => $brief->is_archived, 'label' => $brief->topic]);

            return $brief;
        });

        return response()->json(['brief' => $brief]);
    }
}
