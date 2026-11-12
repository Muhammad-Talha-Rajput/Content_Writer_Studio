<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class WorkspaceAccess
{
    public static function view(User $user, Client $client): bool
    {
        return $user->is_active && ($user->isAdmin() || $user->clients()->where('clients.id', $client->id)->exists());
    }

    public static function write(User $user, Client $client): bool
    {
        return self::view($user, $client) && in_array($user->role, ['ADMIN', 'WRITER'], true);
    }

    public static function editable(User $user, Client $client): void
    {
        Gate::forUser($user)->authorize('work', $client);
        abort_if($client->is_archived, 409, 'Restore this client before changing its workspace.');
    }

    public static function version(object $record, int $expected): void
    {
        abort_if($record->version !== $expected, 409, 'This record changed since you opened it. Reload the saved version before making further changes.');
    }
}
