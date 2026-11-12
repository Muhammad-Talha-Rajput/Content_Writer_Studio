<?php

namespace App\Http\Controllers;

use App\Models\{AuditEvent, Client, ContentBrief, ContentVersion, Project, ReviewSubmission};
use App\Services\GenerationQuality;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReviewWorkflowController extends Controller
{
    public function index(ContentBrief $brief)
    {
        Gate::authorize('view', $brief);
        $submissions = ReviewSubmission::where('brief_id', $brief->id)->with(['version:id,version_no,title', 'submitter:id,name', 'reviewer:id,name,role'])->latest()->get();
        $latest = ContentVersion::where('brief_id', $brief->id)->orderByDesc('version_no')->first();

        $assignedReviewer = $brief->instructions['reviewer_id'] ?? null;
        $reviewer = $assignedReviewer ? \App\Models\User::whereKey($assignedReviewer)->first(['id', 'name', 'role', 'is_active']) : null;
        return response()->json(['submissions' => $submissions, 'latest_version_id' => $latest?->id,
            'assigned_reviewer_id' => $assignedReviewer, 'assigned_reviewer' => $reviewer]);
    }

    public function updateDraft(Request $request, ContentBrief $brief)
    {
        Gate::authorize('view', $brief);
        $data = $request->validate([
            'latest_version_id' => ['required', 'uuid'], 'title' => ['required', 'string', 'min:1', 'max:200'],
            'blocks' => ['required', 'array', 'min:1', 'max:50'], 'blocks.*.id' => ['required', 'string', 'max:60', 'distinct'],
            'blocks.*.kind' => ['required', 'in:HEADING,PARAGRAPH,BULLET'], 'blocks.*.text' => ['required', 'string', 'min:1', 'max:6000'],
            'format_metadata' => ['sometimes', 'array'], 'format_metadata.email_subject' => ['sometimes', 'nullable', 'string', 'max:500'],
            'format_metadata.preview_text' => ['sometimes', 'nullable', 'string', 'max:500'], 'format_metadata.seo_title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'format_metadata.meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        abort_if(collect($data['blocks'])->contains(fn ($block) => trim($block['text']) === ''), 422, 'Content sections cannot be blank.');

        $version = DB::transaction(function () use ($request, $brief, $data) {
            $client = Client::lockForUpdate()->findOrFail($brief->project->client_id);
            WorkspaceAccess::editable($request->user(), $client);
            abort_if(Project::lockForUpdate()->findOrFail($brief->project_id)->is_archived, 409, 'Restore the project before editing this content.');
            $brief = ContentBrief::lockForUpdate()->findOrFail($brief->id);
            abort_if($brief->is_archived || in_array($brief->status, ['IN_PROGRESS', 'IN_REVIEW'], true), 409, 'This brief is currently locked for generation or review.');
            $original = ContentVersion::where('brief_id', $brief->id)->orderByDesc('version_no')->firstOrFail();
            abort_unless($original->id === $data['latest_version_id'], 409, 'A newer version exists. Reload it before editing.');

            $originalBlocks = collect($original->blocks)->keyBy('id');
            foreach ($data['blocks'] as $block) {
                if (! $originalBlocks->has($block['id'])) {
                    throw ValidationException::withMessages(['blocks' => 'Edit the existing blocks in this version. Reload before changing its structure.']);
                }
            }
            $blocks = collect($data['blocks'])->map(function ($block) use ($originalBlocks) {
                $before = $originalBlocks->get($block['id']);
                $sameContent = $before['text'] === $block['text'] && $before['kind'] === $block['kind'];
                return [...$block, 'citations' => $sameContent ? ($before['citations'] ?? []) : []];
            })->values()->all();

            $draft = ['title' => trim($data['title']), 'blocks' => $blocks,
                ...array_replace(['email_subject' => '', 'preview_text' => '', 'seo_title' => '', 'meta_description' => ''], $original->format_metadata ?? [], $data['format_metadata'] ?? [])];
            $briefInput = ['content_type' => $brief->content_type, 'instructions' => $brief->instructions, 'brand_snapshot' => $brief->brand_snapshot];
            try {
                $findings = GenerationQuality::findings($briefInput, $draft, ['findings' => []], []);
            } catch (\App\Services\AiProviderException $error) {
                throw ValidationException::withMessages(['brief' => $error->getMessage()]);
            }
            $body = implode("\n\n", array_map(fn ($block) => match ($block['kind']) {
                'HEADING' => '## '.str_replace(["\r", "\n"], ' ', trim($block['text'])),
                'BULLET' => '- '.str_replace(["\r", "\n"], ' ', trim($block['text'])),
                default => str_replace(["\r\n", "\r"], "\n", trim($block['text'])),
            }, $blocks));
            $version = ContentVersion::create(['brief_id' => $brief->id, 'source_run_id' => null, 'author_id' => $request->user()->id,
                'version_no' => $original->version_no + 1, 'version_kind' => 'MANUAL', 'title' => $draft['title'], 'body_markdown' => $body,
                'blocks' => $blocks, 'format_metadata' => collect($draft)->only(['email_subject', 'preview_text', 'seo_title', 'meta_description'])->all(),
                'brief_snapshot' => $brief->only(['id', 'topic', 'content_type', 'instructions', 'brand_snapshot']), 'quality_findings' => $findings]);

            foreach ($blocks as $block) {
                foreach ($block['citations'] as $citation) {
                    $version->citations()->create(['claim_anchor' => $block['id'], 'source_id' => $citation['source_id'],
                        'segment_ordinal' => $citation['segment_ordinal'], 'locator' => $citation['locator'], 'supporting_excerpt' => $citation['supporting_excerpt']]);
                }
            }
            ReviewSubmission::where('brief_id', $brief->id)->where('status', 'SUBMITTED')->update(['status' => 'SUPERSEDED', 'decided_at' => now(), 'decision_comment' => 'A newer version was saved.']);
            $brief->update(['status' => 'DRAFT', 'version' => $brief->version + 1]);
            AuditEvent::record($request->user(), 'content.version_edited', 'VERSION', $version->id, ['brief_id' => $brief->id, 'version_no' => $version->version_no]);

            return $version;
        });

        return response()->json(['version' => $version->load('citations.source:id,title,source_type,final_url,retrieved_at')], 201);
    }

    public function submit(Request $request, ContentBrief $brief)
    {
        Gate::authorize('view', $brief);
        $request->validate(['version_id' => ['required', 'uuid']]);
        $submission = DB::transaction(function () use ($request, $brief) {
            $client = Client::lockForUpdate()->findOrFail($brief->project->client_id);
            WorkspaceAccess::editable($request->user(), $client);
            abort_if(Project::lockForUpdate()->findOrFail($brief->project_id)->is_archived, 409, 'Restore the project before submitting this content.');
            $brief = ContentBrief::lockForUpdate()->findOrFail($brief->id);
            abort_if($brief->is_archived || in_array($brief->status, ['IN_PROGRESS', 'IN_REVIEW'], true), 409, 'This brief cannot be submitted at its current workflow stage.');
            $version = ContentVersion::where('brief_id', $brief->id)->orderByDesc('version_no')->firstOrFail();
            abort_unless($version->id === $request->input('version_id'), 409, 'Only the latest saved version can be submitted.');
            $snapshot = $version->brief_snapshot ?? [];
            foreach (['topic', 'content_type', 'instructions', 'brand_snapshot'] as $field) {
                abort_unless(($snapshot[$field] ?? null) === $brief->{$field}, 409, 'This version uses older brief instructions. Save a new manual version before submitting it.');
            }
            abort_unless(! collect($version->quality_findings ?? [])->contains(fn ($finding) => ($finding['severity'] ?? '') === 'HARD'), 422, 'Resolve all blocking quality findings before requesting review.');
            $reviewerId = $brief->instructions['reviewer_id'] ?? null;
            abort_unless($reviewerId, 422, 'Assign a reviewer in the brief before submitting it.');
            abort_unless($reviewerId !== $request->user()->id && $version->author_id !== $reviewerId, 422, 'The assigned reviewer must be different from the submitter and the version author.');
            $reviewer = \App\Models\User::whereKey($reviewerId)->where('is_active', true)->whereIn('role', ['ADMIN', 'REVIEWER'])->first();
            abort_unless($reviewer && WorkspaceAccess::view($reviewer, $client), 422, 'The assigned reviewer is inactive or no longer has access to this client.');

            $submission = ReviewSubmission::create(['brief_id' => $brief->id, 'version_id' => $version->id, 'submitted_by' => $request->user()->id,
                'reviewer_id' => $reviewerId, 'status' => 'SUBMITTED', 'submitted_at' => now()]);
            $brief->update(['status' => 'IN_REVIEW', 'version' => $brief->version + 1]);
            AuditEvent::record($request->user(), 'review.submitted', 'REVIEW', $submission->id, ['brief_id' => $brief->id, 'version_id' => $version->id]);
            return $submission;
        });

        return response()->json(['submission' => $submission->load(['version:id,version_no,title', 'submitter:id,name', 'reviewer:id,name,role'])], 201);
    }

    public function decide(Request $request, ReviewSubmission $submission)
    {
        $data = $request->validate(['decision' => ['required', 'in:APPROVED,CHANGES_REQUESTED'], 'comment' => ['nullable', 'string', 'max:2000']]);
        if ($data['decision'] === 'CHANGES_REQUESTED' && mb_strlen(trim($data['comment'] ?? '')) < 5) {
            throw ValidationException::withMessages(['comment' => 'Add a clear note of at least 5 characters explaining the requested changes.']);
        }
        $submission = DB::transaction(function () use ($request, $submission, $data) {
            $current = ReviewSubmission::lockForUpdate()->findOrFail($submission->id);
            $brief = ContentBrief::lockForUpdate()->findOrFail($current->brief_id);
            $client = Client::lockForUpdate()->findOrFail($brief->project->client_id);
            Gate::authorize('view', $brief);
            abort_unless($request->user()->isAdmin() || ($current->reviewer_id === $request->user()->id && $request->user()->role === 'REVIEWER'), 403, 'Only the assigned reviewer or an administrator can decide this submission.');
            abort_unless($request->user()->id !== $current->version->author_id && $request->user()->id !== $current->submitted_by && WorkspaceAccess::view($request->user(), $client), 403, 'The author and submitter cannot review their own submission.');
            abort_unless($current->status === 'SUBMITTED' && $brief->status === 'IN_REVIEW', 409, 'This submission has already been decided or replaced.');
            abort_unless(ContentVersion::where('brief_id', $brief->id)->orderByDesc('version_no')->value('id') === $current->version_id, 409, 'A newer version exists; this submission can no longer be approved.');
            $current->update(['status' => $data['decision'], 'decision_comment' => trim($data['comment'] ?? ''), 'decided_at' => now()]);
            $brief->update(['status' => $data['decision'], 'version' => $brief->version + 1]);
            AuditEvent::record($request->user(), 'review.'.strtolower($data['decision']), 'REVIEW', $current->id, ['brief_id' => $brief->id, 'version_id' => $current->version_id]);
            return $current;
        });

        return response()->json(['submission' => $submission->fresh()->load(['version:id,version_no,title', 'submitter:id,name', 'reviewer:id,name,role'])]);
    }

    public function export(Request $request, ContentVersion $version)
    {
        Gate::authorize('view', $version->brief);
        $format = $request->validate(['format' => ['required', 'in:markdown,html']])['format'];
        $file = DB::transaction(function () use ($request, $version, $format) {
            $brief = ContentBrief::lockForUpdate()->findOrFail($version->brief_id);
            $currentVersion = ContentVersion::where('brief_id', $brief->id)->whereKey($version->id)->firstOrFail();
            $latestId = ContentVersion::where('brief_id', $brief->id)->orderByDesc('version_no')->value('id');
            $approved = ReviewSubmission::where('brief_id', $brief->id)->where('version_id', $version->id)->where('status', 'APPROVED')->exists();
            abort_unless($latestId === $currentVersion->id && $brief->status === 'APPROVED' && $approved, 403, 'Only the latest approved version can be exported.');
            $currentVersion->load('citations.source:id,title');
            $citations = $currentVersion->citations->map(fn ($citation) => ['title' => $citation->source?->title ?? 'Reference', 'locator' => $citation->locator, 'excerpt' => $citation->supporting_excerpt]);
            if ($format === 'markdown') {
                $body = '# '.$currentVersion->title."\n\n";
                if (! empty($currentVersion->format_metadata['email_subject'])) $body .= '**Subject:** '.$currentVersion->format_metadata['email_subject']."\n\n";
                if (! empty($currentVersion->format_metadata['preview_text'])) $body .= '**Preview:** '.$currentVersion->format_metadata['preview_text']."\n\n";
                $body .= $currentVersion->body_markdown;
                if ($citations->isNotEmpty()) $body .= "\n\n## References\n".$citations->map(fn ($cite) => '- '.$cite['title'].' ('.$cite['locator'].'): “'.$cite['excerpt'].'”')->implode("\n");
                $contentType = 'text/markdown; charset=UTF-8'; $extension = 'md';
            } else {
                $body = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'.e($currentVersion->title).'</title><body><main><h1>'.e($currentVersion->title).'</h1>';
                if (! empty($currentVersion->format_metadata['email_subject'])) $body .= '<p><strong>Subject:</strong> '.e($currentVersion->format_metadata['email_subject']).'</p>';
                if (! empty($currentVersion->format_metadata['preview_text'])) $body .= '<p><strong>Preview:</strong> '.e($currentVersion->format_metadata['preview_text']).'</p>';
                foreach (preg_split('/\R\s*\R/', trim($currentVersion->body_markdown)) as $paragraph) {
                    if (str_starts_with($paragraph, '## ')) $body .= '<h2>'.e(substr($paragraph, 3)).'</h2>';
                    elseif (str_starts_with($paragraph, '- ')) $body .= '<p>• '.e(substr($paragraph, 2)).'</p>';
                    else $body .= '<p>'.nl2br(e($paragraph), false).'</p>';
                }
                if ($citations->isNotEmpty()) $body .= '<h2>References</h2><ul>'.$citations->map(fn ($cite) => '<li>'.e($cite['title'].' ('.$cite['locator'].'): '.$cite['excerpt']).'</li>')->implode('').'</ul>';
                $body .= '</main></body></html>';
                $contentType = 'text/html; charset=UTF-8'; $extension = 'html';
            }
            AuditEvent::record($request->user(), 'content.version_exported', 'VERSION', $version->id, ['brief_id' => $brief->id, 'format' => $format]);
            return ['body' => $body, 'filename' => (Str::slug($currentVersion->title) ?: 'approved-content').'-v'.$currentVersion->version_no.'.'.$extension, 'content_type' => $contentType];
        });
        return response($file['body'], 200, ['Content-Type' => $file['content_type'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"']);
    }
}
