<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'industry', 'website', 'is_archived'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'version' => 'integer'];
    }

    public function brandProfile(): HasOne
    {
        return $this->hasOne(BrandProfile::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_access')->withPivot('id', 'assigned_by')->withTimestamps();
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->whereHas('users', fn (Builder $users) => $users->where('users.id', $user->id));
        }
    }
}
