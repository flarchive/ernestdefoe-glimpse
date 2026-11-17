<?php

declare(strict_types=1);

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Every permission Glimpse checks, seeded so each shows on the Permissions page with its default.
return Migration::addPermissions([
    'glimpse.view' => Group::GUEST_ID,
    'glimpse.create' => Group::MEMBER_ID,
    'glimpse.react' => Group::MEMBER_ID,
    'glimpse.skipApproval' => Group::MODERATOR_ID,
    'glimpse.moderate' => Group::MODERATOR_ID,
]);
