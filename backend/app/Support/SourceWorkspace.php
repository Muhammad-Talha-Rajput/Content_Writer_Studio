<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ContentBrief;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;

class SourceWorkspace
{
    // Call inside a transaction. Use the same parent-lock order as brief edits.
    public static function lock(User $user, ContentBrief $brief, bool $processing = false): ContentBrief
    {
        $project = Project::findOrFail($brief->project_id);
        $client = Client::lockForUpdate()->findOrFail($project->client_id);
        WorkspaceAccess::editable($user, $client);
        $project = Project::lockForUpdate()->findOrFail($project->id);
        $brief = ContentBrief::lockForUpdate()->findOrFail($brief->id);
        abort_if($project->is_archived || $brief->is_archived, 409, 'Restore the project and brief before changing sources.');
        if (!$processing) abort_unless(in_array($brief->status, ['DRAFT', 'CHANGES_REQUESTED', 'READY_FOR_REVIEW'], true), 409, 'Sources cannot be changed at this brief status.');
        return $brief;
    }

    public static function quota(ContentBrief $brief, string $type, ?string $except = null): void
    {
        $count = Source::where('brief_id', $brief->id)->where('source_type', $type)->where('is_archived', false)
            ->whereIn('parse_status', ['PENDING', 'READY'])->when($except, fn ($q) => $q->where('id', '!=', $except))->count();
        abort_if($count >= ($type === 'FILE' ? 5 : 10), 422, $type === 'FILE' ? 'A brief supports up to 5 active files. Archive a source before adding another.' : 'A brief supports up to 10 active website sources.');
    }
}
