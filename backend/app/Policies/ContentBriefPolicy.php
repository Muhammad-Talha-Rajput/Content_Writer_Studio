<?php

namespace App\Policies;

use App\Models\ContentBrief;
use App\Models\User;
use App\Support\WorkspaceAccess;

class ContentBriefPolicy
{
    public function view(User $user, ContentBrief $brief): bool
    {
        return WorkspaceAccess::view($user, $brief->project->client);
    }
}
