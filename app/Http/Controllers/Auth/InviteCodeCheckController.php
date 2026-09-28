<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LIVE INSTITUTION INVITE-CODE VALIDATION.
 *
 * PURPOSE
 * -------
 * The registration screen gates the SSO/OAuth buttons behind an institution
 * invite code. A client-side "is the box empty / at least 4 characters" check is
 * not a gate at all — it happily unlocks the provider buttons for a code that
 * resolves to nothing, sending the user through a full OAuth round-trip only to
 * be rejected on the way back. This endpoint is the real check: it asks the
 * server whether the code maps to an ACTIVE institution, and the UI unlocks SSO
 * only when it does.
 *
 * WHAT IT RETURNS — AND WHY SO LITTLE
 * -----------------------------------
 * Only `{ valid: bool, institution_name: string|null }`.
 *
 * It deliberately does NOT return the institution id, slug, settings, member
 * count or anything else about the tenant. This route is PUBLIC (its caller is,
 * by definition, an unauthenticated guest), so a richer payload would turn it
 * into an enumeration oracle that leaks the existence and shape of every
 * workspace to anyone guessing codes. The display name alone is what the user
 * needs to confirm "yes, that is my institution".
 *
 * ABUSE
 * -----
 * Route-level `throttle:12,1` (12 requests/minute per IP). That is generous for a
 * human typing a code and their occasional typo, but useless for brute-forcing an
 * 8-character code space.
 *
 * A code that does not resolve, and a code that resolves to an INACTIVE
 * institution, both return `valid: false` — the same shape — so the endpoint does
 * not disclose which of the two happened.
 */
class InviteCodeCheckController extends Controller
{
    /**
     * Validate an invite code. Always returns HTTP 200 with a `valid` flag —
     * "not valid" is a normal answer to a question, not an error, and a 4xx here
     * would make the client's "unlock the buttons" logic branchy for no benefit.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invite_code' => ['required', 'string', 'max:24'],
        ]);

        $institution = Institution::findByInviteCode($data['invite_code']);

        // An inactive institution must not unlock signup: the same code would be
        // rejected at the final POST /register, so refusing it here keeps the two
        // checks consistent.
        $valid = $institution !== null && $institution->is_active;

        return response()->json([
            'valid' => $valid,
            'institution_name' => $valid ? $institution->name : null,
        ]);
    }
}
