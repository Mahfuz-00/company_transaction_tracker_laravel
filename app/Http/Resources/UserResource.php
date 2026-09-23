<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical JSON shape of a user, shared by every auth endpoint.
 *
 * WHAT A RESOURCE IS
 * ------------------
 * A Resource is Laravel's serialization layer: it converts a model into the
 * exact array the API returns. Instead of mapping fields inline in each
 * controller (where the same user shape would drift between login / register /
 * me / profile), the shape lives in ONE place. Change it here and every
 * endpoint changes together — the mobile contract stays consistent.
 *
 * The key field list (and therefore the wire format) is identical to the
 * previous `AuthController::userPayload()` it replaces.
 *
 * @property \App\Models\User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'designation' => $this->designation,
            // Computed public URL (with a cache-busting version query).
            'avatar_url' => $this->avatarUrl(),
            'status' => $this->status,
            'institution_id' => $this->institution_id,
            // Roles/permissions let the mobile client hide what it cannot do.
            'roles' => $this->getRoleNames()->toArray(),
            'permissions' => $this->getAllPermissions()->pluck('name')->toArray(),
            'is_super_admin' => $this->isSuperAdmin(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
