<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'event_type', 'target_type', 'target_id', 'metadata', 'created_at'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(?User $actor, string $event, string $type, ?string $id, array $metadata = []): void
    {
        static::create(['actor_user_id' => $actor?->id, 'event_type' => $event,
            'target_type' => $type, 'target_id' => $id, 'metadata' => $metadata, 'created_at' => now()]);
    }
}
