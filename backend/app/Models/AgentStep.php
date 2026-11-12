<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class AgentStep extends Model
{
    use HasUuids;
    protected $fillable = ['run_id','step_type','pass_no','attempt_no','status','artifacts','provider_usage','started_at','finished_at','error_message'];
    protected function casts(): array { return ['artifacts'=>'array','provider_usage'=>'array','started_at'=>'datetime','finished_at'=>'datetime']; }
}
