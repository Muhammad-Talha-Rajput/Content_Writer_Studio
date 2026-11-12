<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $fillable = ['client_id', 'created_by', 'name', 'description', 'is_archived', 'version'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'version' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function briefs(): HasMany
    {
        return $this->hasMany(ContentBrief::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('client', fn (Builder $clients) => $clients->visibleTo($user));
    }
}
