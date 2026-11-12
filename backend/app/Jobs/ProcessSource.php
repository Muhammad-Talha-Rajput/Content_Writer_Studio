<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\Source;
use App\Services\PublicSourceFetcher;
use App\Services\SourceExtractor;
use App\Services\SourceScanner;
use App\Support\SourceWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessSource implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public string $sourceId) {}

    public function handle(SourceScanner $scanner, SourceExtractor $extractor, PublicSourceFetcher $fetcher): void
    {
        $source = Source::find($this->sourceId);
        if (!$source || $source->parse_status !== 'PENDING' || $source->processing_started_at) return;
        try {
            $claimed = DB::transaction(function () use ($source) {
                SourceWorkspace::lock($source->creator, $source->brief, true);
                $locked = Source::lockForUpdate()->findOrFail($source->id);
                if ($locked->processing_started_at || $locked->parse_status !== 'PENDING') return false;
                if ($locked->is_archived) throw new RuntimeException('This source was archived before processing.');
                $locked->update(['processing_started_at' => now()]);
                return true;
            });
            if (!$claimed) return;
            $disk = Storage::disk('sources');
            if ($source->source_type === 'FILE') {
                $path = $disk->path($source->storage_key);
                $scanner->scan($path);
                // Only claim CLEAN after the actual scanner has returned successfully.
                Source::whereKey($source->id)->update(['scan_status' => 'CLEAN']);
                $result = $extractor->file($path, $source->title);
                $hash = hash_file('sha256', $path);
                $extra = ['mime_type' => $result['mime'], 'scan_status' => 'CLEAN'];
            } else {
                $page = $fetcher->fetch($source->requested_url);
                $result = $extractor->web($page['body'], $page['mime']);
                $hash = hash('sha256', $page['body']);
                $key = $source->id.'/snapshot.bin';
                $disk->makeDirectory($source->id);
                if (disk_free_space($disk->path('')) < 33554432 + strlen($page['body'])) throw new RuntimeException('Source storage is low. Ask the administrator to free space on the project drive.');
                $disk->put($key, $page['body']);
                // Persist the key immediately so failed final authorization can remove the snapshot.
                $source->update(['storage_key' => $key]);
                $extra = ['final_url' => $page['url'], 'mime_type' => $page['mime'], 'byte_size' => strlen($page['body']),
                    'scan_status' => 'NOT_REQUIRED', 'title' => $result['title'] ?? $source->title];
                if ($extra['title'] === '') $extra['title'] = $source->title;
            }
            DB::transaction(function () use ($source, $result, $hash, $extra) {
                SourceWorkspace::lock($source->creator->fresh(), $source->brief, true);
                $locked = Source::lockForUpdate()->findOrFail($source->id);
                if ($locked->is_archived) throw new RuntimeException('This source was archived during processing.');
                $locked->update($extra + ['parse_status' => 'READY', 'extracted_text' => $result['text'], 'segments' => $result['segments'],
                    'content_hash' => $hash, 'retrieved_at' => now(), 'error_message' => null]);
                AuditEvent::record($source->creator, 'source.ready', 'SOURCE', $source->id, ['brief_id' => $source->brief_id, 'hash' => $hash]);
            });
        } catch (Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('Source processing rejected', ['source_id' => $source->id, 'exception_type' => $error::class]);
            $this->reject($error instanceof RuntimeException ? $error->getMessage() : 'This source could not be processed safely. Try another source or ask the administrator to check processing.');
        }
    }

    private function reject(string $message): void
    {
        $source = Source::find($this->sourceId);
        if (!$source || $source->parse_status !== 'PENDING') return;
        Storage::disk('sources')->deleteDirectory($source->id);
        $source->update(['parse_status' => 'REJECTED', 'scan_status' => $source->scan_status === 'CLEAN' ? 'CLEAN' : 'FAILED',
            'storage_key' => null, 'error_message' => mb_substr($message, 0, 500)]);
        AuditEvent::record($source->creator, 'source.rejected', 'SOURCE', $source->id, ['brief_id' => $source->brief_id]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->reject('Source processing stopped before completion. Attach the source again to retry.');
    }
}
