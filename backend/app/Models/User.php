<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    public const ROLES = ['ADMIN', 'WRITER', 'REVIEWER'];

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->id ??= (string) Str::uuid();
            $user->auth_subject = 'local:'.$user->id;
        });
        static::saving(fn (User $user) => $user->email = mb_strtolower(trim($user->email)));
    }

    public function isAdmin(): bool
    {
        return $this->is_active && $this->role === 'ADMIN';
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_access')->withPivot('id', 'assigned_by')->withTimestamps();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'auth_subject',
        'auth_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'auth_version' => 'integer',
        ];
    }
}
