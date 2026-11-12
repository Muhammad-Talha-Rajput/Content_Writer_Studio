<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBrief extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'created_by', 'topic', 'content_type', 'instructions', 'brand_snapshot', 'status', 'is_archived', 'version'];

    protected function casts(): array
    {
        return ['instructions' => 'array', 'brand_snapshot' => 'array', 'is_archived' => 'boolean', 'version' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentVersions() { return $this->hasMany(ContentVersion::class, 'brief_id'); }
    public function reviewSubmissions() { return $this->hasMany(ReviewSubmission::class, 'brief_id'); }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('project', fn (Builder $projects) => $projects->visibleTo($user));
    }
}
