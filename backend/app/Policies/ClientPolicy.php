<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\WorkspaceAccess;

class ClientPolicy
{
    public function view(User $user, Client $client): bool
    {
        return WorkspaceAccess::view($user, $client);
    }

    public function work(User $user, Client $client): bool
    {
        return WorkspaceAccess::write($user, $client);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->isAdmin();
    }
}
