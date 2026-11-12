<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class AgentRun extends Model
{
    use HasUuids;
    protected $fillable = ['brief_id','active_brief_id','requested_by','idempotency_key','status','previous_brief_status','input_snapshot','limits_snapshot','provider_usage','provider_secret','started_at','finished_at','cancel_requested_at','error_code','error_message'];
    protected $hidden = ['provider_secret'];
    protected function casts(): array { return ['provider_secret'=>'encrypted','input_snapshot'=>'array','limits_snapshot'=>'array','provider_usage'=>'array','started_at'=>'datetime','finished_at'=>'datetime','cancel_requested_at'=>'datetime']; }
    public function brief() { return $this->belongsTo(ContentBrief::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function steps() { return $this->hasMany(AgentStep::class, 'run_id'); }
    public function output() { return $this->hasOne(ContentVersion::class, 'source_run_id'); }
    public function active(): bool { return in_array($this->status, ['QUEUED','RUNNING'], true); }
}
