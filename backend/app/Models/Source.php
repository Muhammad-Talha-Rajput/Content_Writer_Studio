<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    use HasUuids;

    protected $fillable = ['brief_id', 'created_by', 'source_type', 'title', 'requested_url', 'final_url', 'storage_key', 'mime_type', 'byte_size', 'parse_status', 'scan_status', 'error_message', 'content_hash', 'extracted_text', 'segments', 'retrieved_at', 'processing_started_at', 'is_archived', 'version', 'discovered_by_run_id'];
    protected $hidden = ['storage_key'];
    protected function casts(): array
    {
        return ['segments' => 'array', 'retrieved_at' => 'datetime', 'processing_started_at' => 'datetime', 'is_archived' => 'boolean', 'version' => 'integer'];
    }
    public function brief() { return $this->belongsTo(ContentBrief::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
