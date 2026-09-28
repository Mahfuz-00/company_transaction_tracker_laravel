<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealMenu;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * MEAL MENUS & VOTING API.
 *
 * The kitchen proposes menus; members vote; a manager approves the winner. Two
 * distinct audiences share this controller:
 *
 *   - index/show          : EVERYONE (a member sees the menus open for voting and
 *                           whether they have already voted; staff see all).
 *   - store/options/approve/reject/close : staff, gated by `meals.reports`.
 *
 * THE ONE-VOTE-PER-MEMBER RULE lives on the server (`vote`), not in the client.
 * A member's own vote is resolved from their token — the endpoint never accepts a
 * member id — so a crafted request cannot vote on someone else's behalf, and a
 * repeat vote is an idempotent update rather than a duplicate.
 */
class MenuApiController extends Controller
{
    /**
     * GET /api/menus?status=&meal_type=
     *
     * Lists menus. A member sees only menus that are OPEN FOR VOTING (a draft is
     * internal to the kitchen); staff see every status.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isStaff = $user->can('meals.reports');

        $status = (string) $request->query('status', '');
        $mealType = (string) $request->query('meal_type', '');
        $perPage = min((int) $request->query('per_page', 20), 100);

        $student = $user->studentRecord();

        $menus = MealMenu::query()
            ->with([
                'options' => fn ($q) => $q->withCount('votes'),
                'votes',
            ])
            ->when(! $isStaff, fn ($q) => $q->where('status', 'voting'))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($mealType !== '', fn ($q) => $q->where('meal_type', $mealType))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $menus->through(fn (MealMenu $m) => $this->present($m, $student?->id));

        return response()->json([
            'data' => $menus->items(),
            'meta' => [
                'statuses' => MealMenu::STATUSES,
                'meal_types' => MealMenu::MEAL_TYPES,
                'can_manage' => $isStaff,
                'pagination' => [
                    'current_page' => $menus->currentPage(),
                    'last_page' => $menus->lastPage(),
                    'per_page' => $menus->perPage(),
                    'total' => $menus->total(),
                ],
            ],
        ]);
    }

    /** GET /api/menus/{menu} */
    public function show(Request $request, MealMenu $menu)
    {
        $student = $request->user()->studentRecord();

        $menu->load([
            'options' => fn ($q) => $q->withCount('votes'),
            'votes',
        ]);

        return response()->json([
            'data' => $this->present($menu, $student?->id, full: true),
            'meta' => [
                'can_manage' => $request->user()->can('meals.reports'),
                'statuses' => MealMenu::STATUSES,
                'meal_types' => MealMenu::MEAL_TYPES,
            ],
        ]);
    }

    /**
     * POST /api/menus/{menu}/vote
     *
     * Cast (or change) the CALLER's vote. Body: `{ option_id }`.
     *
     * Idempotent per member: a second vote replaces the first rather than adding
     * another, which is what the unique index on (menu, member) enforces. Returns
     * the refreshed tallies so the UI updates without a second call.
     */
    public function vote(Request $request, MealMenu $menu)
    {
        $student = $request->user()->studentRecord();

        if (! $student) {
            return response()->json(['message' => 'Your account is not linked to a member record.'], 409);
        }

        // A menu that is not open cannot be voted on — a closed or approved menu
        // must not silently accept new votes. Delegates to the model rule so the
        // window logic (status AND the opens/closes timestamps) is applied once.
        if (! $menu->isVotingOpen()) {
            return response()->json([
                'message' => 'This menu is not open for voting.',
            ], 409);
        }

        $data = $request->validate([
            'option_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        // The option must belong to THIS menu. Without this check a caller could
        // vote for an option on an unrelated menu.
        $option = $menu->options()->whereKey($data['option_id'])->first();

        if (! $option) {
            return response()->json([
                'message' => 'That option does not belong to this menu.',
                'errors' => ['option_id' => ['That option does not belong to this menu.']],
            ], 422);
        }

        // One vote per member per menu: update the existing row if there is one.
        // The (meal_menu_id, user_id) unique index enforces this at the database
        // level too, so a race cannot produce a duplicate.
        $menu->votes()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'meal_menu_option_id' => $option->id,
                'student_id' => $student->id,
                'comment' => $data['comment'] ?? null,
            ],
        );

        $menu->load([
            'options' => fn ($q) => $q->withCount('votes'),
            'votes',
        ]);

        AuditLogger::log('voted', "voted on the menu \"{$menu->title}\"", $menu, [
            'option' => $option->name,
        ], ['subject_label' => $student->name, 'institution_id' => $menu->institution_id]);

        return response()->json([
            'data' => $this->present($menu, $student->id, full: true),
            'message' => 'Your vote has been recorded.',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Staff side
     * ------------------------------------------------------------------ */

    /** POST /api/menus — propose a menu (staff). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'meal_type' => ['required', Rule::in(array_keys(MealMenu::MEAL_TYPES))],
            'menu_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.name' => ['required', 'string', 'max:180'],
            'options.*.description' => ['nullable', 'string', 'max:500'],
            'options.*.estimated_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $menu = MealMenu::create([
            'institution_id' => $request->user()->institution_id,
            'title' => $data['title'],
            'meal_type' => $data['meal_type'],
            'menu_date' => $data['menu_date'] ?? now()->toDateString(),
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['options'] as $index => $option) {
            $menu->options()->create([
                'name' => $option['name'],
                'description' => $option['description'] ?? null,
                'estimated_cost' => $option['estimated_cost'] ?? null,
                'sort_order' => $index,
            ]);
        }

        AuditLogger::log('created', "proposed the menu \"{$menu->title}\"", $menu, [], [
            'subject_label' => $menu->title,
            'institution_id' => $menu->institution_id,
        ]);

        return response()->json([
            'data' => $this->present($menu->fresh(['options', 'votes']), null, full: true),
            'message' => 'Menu created. Open it for voting when you are ready.',
        ], 201);
    }

    /** PATCH /api/menus/{menu}/status — move a menu through its lifecycle. */
    public function updateStatus(Request $request, MealMenu $menu)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(MealMenu::STATUSES)],
            'approval_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $attributes = ['status' => $data['status']];

        // Approving stamps who approved it and when — the audit the module needs.
        if ($data['status'] === 'approved') {
            $attributes['approved_by'] = $request->user()->id;
            $attributes['approved_at'] = now();
            $attributes['approval_note'] = $data['approval_note'] ?? null;
        }

        $menu->update($attributes);

        AuditLogger::log('updated', "set the menu \"{$menu->title}\" to {$data['status']}", $menu, [
            'status' => $data['status'],
        ], ['subject_label' => $menu->title, 'institution_id' => $menu->institution_id]);

        return response()->json([
            'data' => $this->present($menu->fresh(['options', 'votes']), null, full: true),
            'message' => "Menu marked as {$data['status']}.",
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Serialise a menu, including the tallies and (for a member) their own vote.
     *
     * `$voterId` is the signed-in member's id, so the UI can highlight the option
     * they already chose and disable re-voting.
     */
    protected function present(MealMenu $menu, ?int $voterId, bool $full = false): array
    {
        $totalVotes = $menu->options->sum('votes_count');

        $options = $menu->options->map(function ($option) use ($totalVotes, $voterId, $menu) {
            $count = (int) ($option->votes_count ?? 0);

            return [
                'id' => $option->id,
                'name' => $option->name,
                'description' => $option->description,
                'estimated_cost' => $option->estimated_cost !== null ? (float) $option->estimated_cost : null,
                'is_recommended' => (bool) $option->is_recommended,
                'votes' => $count,
                'percentage' => $totalVotes > 0 ? round($count / $totalVotes * 100, 1) : 0.0,
                'is_my_choice' => $voterId !== null
                    && $menu->votes->firstWhere('student_id', $voterId)?->meal_menu_option_id === $option->id,
            ];
        })->values()->all();

        return [
            'id' => $menu->id,
            'title' => $menu->title,
            'meal_type' => $menu->meal_type,
            'meal_type_label' => $menu->mealTypeLabel(),
            'menu_date' => $menu->menu_date?->toDateString(),
            'status' => $menu->status,
            'status_label' => $menu->statusLabel(),
            'status_tone' => $menu->statusTone(),
            'description' => $menu->description,
            'voting_closes_at' => $menu->voting_closes_at?->toIso8601String(),
            'total_votes' => $totalVotes,
            // Delegates to the model so the open/closed rule lives in ONE place.
            'is_open_for_voting' => $menu->isVotingOpen(),
            'allow_vote_changes' => (bool) $menu->allow_vote_changes,
            'has_voted' => $voterId !== null
                && $menu->votes->contains(fn ($v) => $v->student_id === $voterId),
            'options' => $options,
            'approved_at' => $menu->approved_at?->toIso8601String(),
            'created_at' => $menu->created_at?->toIso8601String(),
        ];
    }
}
