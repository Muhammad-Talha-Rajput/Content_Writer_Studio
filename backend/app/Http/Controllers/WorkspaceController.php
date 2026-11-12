<?php

namespace App\Http\Controllers;

use App\Models\{AgentRun, ContentBrief, ContentVersion, Project, ReviewSubmission, User};
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    private function visibleBriefs(User $user)
    {
        return ContentBrief::visibleTo($user)->where('is_archived', false)
            ->whereHas('project', fn ($project) => $project->where('is_archived', false)
                ->whereHas('client', fn ($client) => $client->where('is_archived', false)));
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $briefs = $this->visibleBriefs($user);
        $pending = ReviewSubmission::where('status', 'SUBMITTED')->whereHas('version.brief', fn ($brief) => $brief->visibleTo($user))
            ->when(! $user->isAdmin(), fn ($query) => $query->where('reviewer_id', $user->id));
        $recentVersions = ContentVersion::whereHas('brief', fn ($brief) => $brief->visibleTo($user))
            ->with(['brief.project.client:id,name', 'author:id,name'])->latest()->limit(8)->get();
        $recentRuns = AgentRun::whereHas('brief', fn ($brief) => $brief->visibleTo($user))
            ->with('brief:id,project_id,topic')->latest()->limit(8)
            ->get(['id', 'brief_id', 'status', 'error_code', 'created_at', 'finished_at']);

        return response()->json(['counts' => [
            'briefs' => (clone $briefs)->count(),
            'projects' => Project::visibleTo($user)->where('is_archived', false)->whereHas('client', fn ($client) => $client->where('is_archived', false))->count(),
            'drafts' => (clone $briefs)->whereIn('status', ['DRAFT', 'READY_FOR_REVIEW', 'CHANGES_REQUESTED'])->count(),
            'in_review' => (clone $briefs)->where('status', 'IN_REVIEW')->count(),
            'approved' => (clone $briefs)->where('status', 'APPROVED')->count(),
            'active_runs' => AgentRun::whereIn('status', ['QUEUED', 'RUNNING'])->whereHas('brief', fn ($brief) => $brief->visibleTo($user))->count(),
            'pending_reviews' => (clone $pending)->count(),
        ], 'recent_versions' => $recentVersions, 'recent_runs' => $recentRuns,
            'pending_reviews' => (clone $pending)->with(['version:id,brief_id,version_no,title', 'version.brief:id,project_id,topic', 'submitter:id,name'])->latest()->limit(8)->get()]);
    }

    public function library(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:DRAFT,IN_PROGRESS,READY_FOR_REVIEW,IN_REVIEW,CHANGES_REQUESTED,APPROVED'],
            'content_type' => ['nullable', 'in:BLOG,SOCIAL,EMAIL,WEBSITE,PRODUCT'],
            'client_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'],
            'include_archived' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $query = ContentBrief::visibleTo($user)->with(['project.client:id,name,is_archived', 'latestVersion.author:id,name'])
            ->when(empty($filters['include_archived']), fn ($query) => $query->where('is_archived', false)
                ->whereHas('project', fn ($project) => $project->where('is_archived', false)->whereHas('client', fn ($client) => $client->where('is_archived', false))))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['content_type']), fn ($query) => $query->where('content_type', $filters['content_type']))
            ->when(! empty($filters['client_id']), fn ($query) => $query->whereHas('project', fn ($project) => $project->where('client_id', $filters['client_id'])))
            ->when(! empty($filters['project_id']), fn ($query) => $query->where('project_id', $filters['project_id']));

        if (! empty($filters['q'])) {
            $term = '%'.addcslashes(trim($filters['q']), '%_\\').'%';
            $query->where(fn ($query) => $query->where('topic', 'like', $term)
                ->orWhereHas('project', fn ($project) => $project->where('name', 'like', $term)->orWhereHas('client', fn ($client) => $client->where('name', 'like', $term)))
                ->orWhereHas('contentVersions', fn ($version) => $version->where('title', 'like', $term)->orWhere('body_markdown', 'like', $term)));
        }

        return response()->json(['briefs' => $query->latest('updated_at')->paginate($filters['per_page'] ?? 25)->appends($filters), 'filters' => $filters]);
    }

    public function exportHistory(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'format' => ['nullable', 'in:markdown,html'],
            'client_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $request->user();
        $query = DB::table('audit_events as event')
            ->join('content_versions as version', 'version.id', '=', 'event.target_id')
            ->join('content_briefs as brief', 'brief.id', '=', 'version.brief_id')
            ->join('projects', 'projects.id', '=', 'brief.project_id')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'event.actor_user_id')
            ->where('event.event_type', 'content.version_exported')->where('event.target_type', 'VERSION')
            ->when(! $user->isAdmin(), fn (Builder $query) => $query->join('client_access as access', 'access.client_id', '=', 'clients.id')->where('access.user_id', $user->id))
            ->when(! empty($filters['format']), fn (Builder $query) => $query->where('event.metadata->format', $filters['format']))
            ->when(! empty($filters['client_id']), fn (Builder $query) => $query->where('clients.id', $filters['client_id']))
            ->when(! empty($filters['q']), function (Builder $query) use ($filters) {
                $term = '%'.addcslashes(trim($filters['q']), '%_\\').'%';
                $query->where(fn (Builder $query) => $query->where('version.title', 'like', $term)->orWhere('brief.topic', 'like', $term)
                    ->orWhere('projects.name', 'like', $term)->orWhere('clients.name', 'like', $term));
            })
            ->select(['event.id', 'event.created_at', 'event.metadata', 'version.id as version_id', 'version.version_no', 'version.title',
                'brief.id as brief_id', 'brief.topic', 'projects.name as project_name', 'clients.id as client_id', 'clients.name as client_name', 'actor.name as exported_by'])
            ->orderByDesc('event.created_at');

        return response()->json(['exports' => $query->paginate($filters['per_page'] ?? 25)->appends($filters), 'filters' => $filters]);
    }
}
