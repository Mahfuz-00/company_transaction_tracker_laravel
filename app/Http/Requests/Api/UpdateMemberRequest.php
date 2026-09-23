<?php

namespace App\Http\Requests\Api;

/**
 * Validates "update a member" (PATCH /api/members/{member}).
 *
 * The {member} itself is already tenant-scoped by route-model binding (a
 * foreign id cannot bind and returns 404). This request additionally scopes the
 * referenced department/manager to the same institution.
 */
class UpdateMemberRequest extends StoreMemberRequest
{
    // Same field set and tenant-scoped references as creation.
}
