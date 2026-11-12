<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReviewSubmission extends Model
{
    use HasUuids;

    protected $fillable = ['brief_id', 'version_id', 'submitted_by', 'reviewer_id', 'status', 'decision_comment', 'submitted_at', 'decided_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function version() { return $this->belongsTo(ContentVersion::class, 'version_id'); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewer_id'); }
}
