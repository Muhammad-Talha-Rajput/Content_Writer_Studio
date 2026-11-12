<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\WorkspaceAccess;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return WorkspaceAccess::view($user, $project->client);
    }
}
