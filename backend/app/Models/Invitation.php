<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use HasUuids;

    protected $fillable = ['email', 'role', 'invited_by', 'token_hash', 'client_ids', 'expires_at', 'accepted_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['client_ids' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isUsable(): bool
    {
        return ! $this->accepted_at && ! $this->revoked_at && $this->expires_at->isFuture() && $this->inviter?->isAdmin();
    }
}
