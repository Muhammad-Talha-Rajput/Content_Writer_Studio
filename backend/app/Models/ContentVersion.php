<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class ContentVersion extends Model
{
    use HasUuids;
    protected $fillable = ['brief_id','source_run_id','author_id','version_no','version_kind','title','body_markdown','blocks','format_metadata','brief_snapshot','quality_findings'];
    protected function casts(): array { return ['blocks'=>'array','format_metadata'=>'array','brief_snapshot'=>'array','quality_findings'=>'array','version_no'=>'integer']; }
    public function brief() { return $this->belongsTo(ContentBrief::class); }
    public function citations() { return $this->hasMany(Citation::class, 'version_id'); }
    public function reviewSubmissions() { return $this->hasMany(ReviewSubmission::class, 'version_id'); }
}
