<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Citation extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $fillable = ['version_id','source_id','claim_anchor','segment_ordinal','locator','supporting_excerpt'];
    public function source() { return $this->belongsTo(Source::class); }
}
