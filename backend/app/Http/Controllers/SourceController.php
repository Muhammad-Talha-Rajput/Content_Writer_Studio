<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSource;
use App\Models\AuditEvent;
use App\Models\ContentBrief;
use App\Models\Source;
use App\Rules\PublicWebUrl;
use App\Support\SourceWorkspace;
use App\Support\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SourceController extends Controller
{
    public function index(ContentBrief $brief)
    {
        Gate::authorize('view', $brief);
        $sources = Source::where('brief_id', $brief->id)->latest()->get(['id', 'brief_id', 'source_type', 'title', 'requested_url', 'final_url', 'mime_type', 'byte_size', 'parse_status', 'scan_status', 'error_message', 'content_hash', 'retrieved_at', 'created_at', 'is_archived', 'version']);
        return response()->json(['sources' => $sources, 'ready_count' => $sources->where('parse_status', 'READY')->where('is_archived', false)->count(), 'limits' => ['files' => 5, 'urls' => 10, 'file_bytes' => 10485760]]);
    }

    public function store(Request $request, ContentBrief $brief)
    {
        Gate::authorize('view', $brief);
        $data = $request->validate(['source_type' => ['required', 'in:FILE,URL'],
            'file' => ['required_if:source_type,FILE', 'prohibited_unless:source_type,FILE', 'file', 'max:10240', 'extensions:txt,pdf,docx'],
            'url' => ['required_if:source_type,URL', 'prohibited_unless:source_type,URL', 'string', 'max:2048', new PublicWebUrl],
        ]);
        $key = null;
        try {
            $source = DB::transaction(function () use ($request, $brief, $data, &$key) {
                $brief = SourceWorkspace::lock($request->user(), $brief);
                SourceWorkspace::quota($brief, $data['source_type']);
                abort_if(Source::where('brief_id', $brief->id)->where('created_at', '>=', now()->startOfDay())->count() >= 100, 429, 'This brief has reached its daily source limit. Try tomorrow.');
                if ($data['source_type'] === 'URL') {
                    abort_if(Source::where('brief_id', $brief->id)->where('requested_url', $data['url'])->where('is_archived', false)->whereIn('parse_status', ['PENDING', 'READY'])->exists(), 422, 'This link is already attached. Archive the existing snapshot before retrieving a new one.');
                }
                $id = (string) Str::uuid();
                $values = ['id' => $id, 'brief_id' => $brief->id, 'created_by' => $request->user()->id, 'source_type' => $data['source_type']];
                if ($data['source_type'] === 'FILE') {
                    $file = $request->file('file');
                    $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                    abort_if(mb_strlen($name) > 200 || preg_match('/[\x00-\x1f\x7f]/', $name), 422, 'Use a file name with at most 200 readable characters.');
                    $root = Storage::disk('sources')->path('');
                    if (!is_dir($root)) mkdir($root, 0700, true);
                    abort_if(disk_free_space($root) < 33554432 + $file->getSize(), 507, 'Source storage is low. Ask the administrator to free space on the project drive.');
                    $key = $id.'/original.bin';
                    Storage::disk('sources')->putFileAs($id, $file, 'original.bin');
                    $values += ['title' => $name, 'storage_key' => $key, 'byte_size' => $file->getSize()];
                } else {
                    $values += ['title' => parse_url($data['url'], PHP_URL_HOST), 'requested_url' => $data['url']];
                }
                $source = new Source($values); $source->id = $id; $source->save();
                AuditEvent::record($request->user(), 'source.attached', 'SOURCE', $source->id, ['brief_id' => $brief->id, 'type' => $data['source_type']]);
                ProcessSource::dispatch($source->id)->onConnection(config('sources.connection'))->onQueue('sources');
                return $source->fresh();
            });
        } catch (\Throwable $error) {
            if ($key) Storage::disk('sources')->deleteDirectory(explode('/', $key)[0]);
            throw $error;
        }
        return response()->json(['source' => $source], 202);
    }

    public function show(Source $source)
    {
        Gate::authorize('view', $source->brief);
        return response()->json(['source' => $source]);
    }

    public function archive(Request $request, Source $source)
    {
        Gate::authorize('view', $source->brief);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'is_archived' => ['required', 'boolean']]);
        $source = DB::transaction(function () use ($request, $source, $data) {
            $brief = SourceWorkspace::lock($request->user(), $source->brief);
            $source = Source::lockForUpdate()->findOrFail($source->id);
            WorkspaceAccess::version($source, (int) $data['version']);
            abort_if($source->parse_status === 'PENDING' && !$data['is_archived'], 409, 'Wait for this source to finish before restoring it.');
            if (!$data['is_archived'] && $source->parse_status === 'READY') SourceWorkspace::quota($brief, $source->source_type, $source->id);
            $source->update(['is_archived' => $data['is_archived'], 'version' => $source->version + 1]);
            AuditEvent::record($request->user(), $data['is_archived'] ? 'source.archived' : 'source.restored', 'SOURCE', $source->id, ['brief_id' => $brief->id]);
            return $source;
        });
        return response()->json(['source' => $source]);
    }

    public function preview(Source $source)
    {
        Gate::authorize('view', $source->brief);
        abort_unless($source->parse_status === 'READY' && $source->scan_status === 'CLEAN', 409, 'Only ready, scanned files can be previewed.');
        abort_unless($source->source_type === 'FILE' && $source->mime_type === 'application/pdf', 415, 'Original-layout preview is available for PDF sources.');
        $disk = Storage::disk('sources');
        abort_unless($source->storage_key && $disk->exists($source->storage_key), 404, 'The original PDF is unavailable.');
        return response()->file($disk->path($source->storage_key), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="source.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function previewPage(Source $source, int $page)
    {
        Gate::authorize('view', $source->brief);
        abort_unless($source->parse_status === 'READY' && $source->scan_status === 'CLEAN', 409, 'Only ready, scanned files can be previewed.');
        abort_unless($source->source_type === 'FILE' && $source->mime_type === 'application/pdf', 415, 'Page previews require a PDF source.');
        abort_unless($page >= 1 && $page <= 100, 404);
        $disk = Storage::disk('sources');
        abort_unless($source->storage_key && $disk->exists($source->storage_key), 404);
        $directory = storage_path('framework/source-previews');
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        abort_if(disk_free_space($directory) < 16777216, 507, 'Preview storage is low. Download the original PDF instead.');
        $output = $directory.'/'.Str::uuid().'.png';
        try {
            $process = new \Symfony\Component\Process\Process([config('sources.python'), '-B', base_path('scripts/render_source_pdf.py'), $disk->path($source->storage_key), (string) $page, $output], base_path(), ['PYTHONDONTWRITEBYTECODE' => '1']);
            $process->setTimeout(20);
            $process->run();
            $metadata = json_decode($process->getOutput(), true);
            abort_unless($process->isSuccessful() && is_file($output) && isset($metadata['pages']), 422, 'The PDF page could not be displayed. Use the original PDF link.');
            return response()->file($output, ['Content-Type' => 'image/png', 'X-PDF-Page-Count' => (string) $metadata['pages'],
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        } catch (\Throwable $error) {
            if (is_file($output)) unlink($output);
            if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) throw $error;
            abort(422, 'The PDF page could not be displayed. Use the original PDF link.');
        }
    }

    public function download(Source $source)
    {
        Gate::authorize('view', $source->brief);
        abort_unless($source->parse_status === 'READY', 409, 'Only ready sources can be downloaded.');
        if ($source->source_type === 'FILE') {
            abort_unless($source->scan_status === 'CLEAN', 409, 'This file has not passed its malware scan.');
            return Storage::disk('sources')->download($source->storage_key, $source->title, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        return response()->streamDownload(fn () => print($source->extracted_text), 'source-'.$source->id.'.txt', ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
