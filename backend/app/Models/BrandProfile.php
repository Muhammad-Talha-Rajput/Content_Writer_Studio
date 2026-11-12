<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BrandProfile extends Model
{
    use HasUuids;

    protected $fillable = ['client_id', 'default_audience', 'default_tone', 'guidelines', 'updated_by', 'version'];

    protected function casts(): array
    {
        return ['guidelines' => 'array', 'version' => 'integer'];
    }
}
