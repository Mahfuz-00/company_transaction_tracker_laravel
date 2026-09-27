<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MealMenu;
use App\Models\MealMenuOption;
use App\Models\MealMenuVote;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * MEAL MENU & VOTING.
 *
 * Staff propose a menu for a meal slot; eligible members vote on the options; an
 * Admin or Meal Manager APPROVES it before it becomes the active menu. The whole
 * module exists to make that last step unavoidable - nothing unapproved is ever
 * presented to members as "the menu".
 *
 *   STAFF / MANAGEMENT
 *     GET    /meals/menus                    : the board (drafts, open votes, history)
 *     POST   /meals/menus                    : propose a menu with its options
 *     GET    /meals/menus/{menu}             : one menu, its tally and votes
 *     POST   /meals/menus/{menu}/options     : add an option
 *     DELETE /meals/menus/options/{option}   : remove an option (pre-approval only)
 *     PATCH  /meals/menus/{menu}/open        : open voting
 *     PATCH  /meals/menus/{menu}/approve     : APPROVE (Admin / Meal Manager)
 *     PATCH  /meals/menus/{menu}/reject      : reject with a reason
 *     PATCH  /meals/menus/{menu}/cancel      : withdraw (creator)
 *
 *   MEMBERS (their own view)
 *     GET    /my/menus                       : menus open for voting + my vote
 *     POST   /my/menus/{menu}/vote           : cast or change my one vote
 *
 * WHO MAY VOTE
 *   `eligibleVoters()` is the single definition: active members, plus staff who
 *   have opted into meals (`users.is_meal_participant`). Admins and Meal Managers
 *   are therefore included ONLY when they actually eat in the mess - a staff
 *   member who does not take meals has no vote, which is the correct behaviour.
 */
class MealMenuController extends Controller
{
    /* ------------------------------------------------------------------ *
     * MANAGEMENT BOARD
     * ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $mealType = (string) $request->query('meal_type', '');

        $menus = MealMenu::query()
            ->with(['options', 'creator:id,name', 'approver:id,name'])
            ->withCount('votes')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($mealType !== '', fn ($q) => $q->where('meal_type', $mealType))
            // Newest first, but future/today's dates before past ones.
            ->orderByDesc('menu_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (MealMenu $menu) => [
                'id' => $menu->id,
                'title' => $menu->title,
                'meal_type' => $menu->meal_type,
                'meal_type_label' => $menu->mealTypeLabel(),
                'menu_date' => $menu->menu_date?->format('j M Y'),
                'description' => $menu->description,
                'status' => $menu->status,
                'status_label' => $menu->statusLabel(),
                'status_tone' => $menu->statusTone(),
                'options_count' => $menu->options->count(),
                'votes_count' => $menu->votes_count,
                'voting_open' => $menu->isVotingOpen(),
                'voting_closes_at' => $menu->voting_closes_at?->format('j M Y H:i'),
                'creator' => $menu->creator?->name,
                'approver' => $menu->approver?->name,
                'approved_at' => $menu->approved_at?->format('j M Y H:i'),
                'approval_note' => $menu->approval_note,
                'leading' => $menu->leadingOption(),
            ]);

        return Inertia::render('Meals/Menus/Index', [
            'menus' => $menus,
            'mealTypes' => collect(MealMenu::MEAL_TYPES)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'filters' => ['status' => $status, 'meal_type' => $mealType],
            'canApprove' => $this->canApprove($request->user()),
            'summary' => [
                'open_votes' => MealMenu::query()->where('status', 'voting')->count(),
                'awaiting_approval' => MealMenu::query()->where('status', 'voting')->whereHas('votes')->count(),
                'approved' => MealMenu::query()->where('status', 'approved')->count(),
                'eligible_voters' => $this->eligibleVoterCount(),
            ],
        ]);
    }

    /** Propose a menu together with its options, in one transaction. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'meal_type' => ['required', Rule::in(array_keys(MealMenu::MEAL_TYPES))],
            'menu_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:500'],
            'allow_vote_changes' => ['boolean'],
            'voting_closes_at' => ['nullable', 'date'],
            'open_voting' => ['boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.name' => ['required', 'string', 'max:120'],
            'options.*.description' => ['nullable', 'string', 'max:255'],
            'options.*.estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'options.*.is_recommended' => ['boolean'],
        ]);

        // At least TWO options: a vote with one choice is not a vote.
        $menu = DB::transaction(function () use ($data, $request) {
            $menu = MealMenu::create([
                'institution_id' => Institution::current()?->id,
                'meal_type' => $data['meal_type'],
                'menu_date' => $data['menu_date'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $request->boolean('open_voting') ? 'voting' : 'draft',
                'voting_opens_at' => $request->boolean('open_voting') ? now() : null,
                'voting_closes_at' => $data['voting_closes_at'] ?? null,
                'allow_vote_changes' => $request->boolean('allow_vote_changes', true),
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['options'] as $index => $option) {
                $menu->options()->create([
                    'name' => $option['name'],
                    'description' => $option['description'] ?? null,
                    'estimated_cost' => $option['estimated_cost'] ?? 0,
                    'is_recommended' => (bool) ($option['is_recommended'] ?? false),
                    'sort_order' => $index,
                ]);
            }

            return $menu;
        });

        AuditLogger::log('created', "proposed the menu \"{$menu->title}\"", $menu, [
            'meal_type' => $menu->meal_type,
            'options' => count($data['options']),
            'status' => $menu->status,
        ], ['subject_label' => $menu->title, 'institution_id' => $menu->institution_id]);

        return redirect()
            ->route('meals.menus.show', $menu)
            ->with('success', "Menu \"{$menu->title}\" proposed with ".count($data['options']).' option(s).');
    }

    /** One menu: its options, the tally, and every vote cast. */
    public function show(Request $request, MealMenu $mealMenu)
    {
        $mealMenu->load(['options', 'creator:id,name', 'approver:id,name']);

        $votes = $mealMenu->votes()
            ->with(['user:id,name,email', 'option:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MealMenuVote $vote) => [
                'id' => $vote->id,
                'voter' => $vote->user?->name,
                'email' => $vote->user?->email,
                'option' => $vote->option?->name,
                'comment' => $vote->comment,
                'created_at' => $vote->created_at?->format('j M Y H:i'),
            ]);

        $myVote = $mealMenu->votes()->where('user_id', $request->user()->id)->first();

        return Inertia::render('Meals/Menus/Show', [
            'menu' => [
                'id' => $mealMenu->id,
                'title' => $mealMenu->title,
                'meal_type' => $mealMenu->meal_type,
                'meal_type_label' => $mealMenu->mealTypeLabel(),
                'menu_date' => $mealMenu->menu_date?->format('j M Y'),
                'description' => $mealMenu->description,
                'status' => $mealMenu->status,
                'status_label' => $mealMenu->statusLabel(),
                'status_tone' => $mealMenu->statusTone(),
                'voting_open' => $mealMenu->isVotingOpen(),
                'allow_vote_changes' => $mealMenu->allow_vote_changes,
                'voting_closes_at' => $mealMenu->voting_closes_at?->format('j M Y H:i'),
                'creator' => $mealMenu->creator?->name,
                'approver' => $mealMenu->approver?->name,
                'approved_at' => $mealMenu->approved_at?->format('j M Y H:i'),
                'approval_note' => $mealMenu->approval_note,
                'options' => $mealMenu->options->map(fn ($option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'description' => $option->description,
                    'estimated_cost' => (float) $option->estimated_cost,
                    'is_recommended' => (bool) $option->is_recommended,
                ]),
            ],
            'tally' => $mealMenu->tally(),
            'votes' => $votes,
            'myVote' => $myVote ? [
                'option_id' => $myVote->meal_menu_option_id,
                'comment' => $myVote->comment,
            ] : null,
            'canApprove' => $this->canApprove($request->user()),
            'canVote' => $this->mayVote($request->user(), $mealMenu),
            'totalVotes' => $votes->count(),
            'eligibleVoters' => $this->eligibleVoterCount(),
        ]);
    }

    /** Add an option to an unapproved menu. */
    public function storeOption(Request $request, MealMenu $mealMenu)
    {
        if (! $mealMenu->isOpen()) {
            return back()->with('error', 'Options cannot be changed once a menu is approved or rejected.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'is_recommended' => ['boolean'],
        ]);

        $mealMenu->options()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'estimated_cost' => $data['estimated_cost'] ?? 0,
            'is_recommended' => $request->boolean('is_recommended'),
            'sort_order' => $mealMenu->options()->count(),
        ]);

        return back()->with('success', "\"{$data['name']}\" added to the menu.");
    }

    /** Remove an option, refusing if it would leave fewer than two. */
    public function destroyOption(Request $request, MealMenuOption $option)
    {
        $menu = $option->menu;

        if (! $menu || ! $menu->isOpen()) {
            return back()->with('error', 'Options cannot be changed once a menu is approved or rejected.');
        }

        if ($menu->options()->count() <= 2) {
            return back()->with('error', 'A menu needs at least two options for members to choose between.');
        }

        $name = $option->name;
        // Votes for a removed option go with it (cascade), so the tally stays honest.
        $option->delete();

        return back()->with('success', "\"{$name}\" removed from the menu.");
    }

    /** Move a menu from draft into open voting. */
    public function openVoting(Request $request, MealMenu $mealMenu)
    {
        if ($mealMenu->status !== 'draft') {
            return back()->with('error', 'Only a draft menu can be opened for voting.');
        }

        if ($mealMenu->options()->count() < 2) {
            return back()->with('error', 'Add at least two options before opening the vote.');
        }

        $data = $request->validate([
            'voting_closes_at' => ['nullable', 'date', 'after:now'],
        ]);

        $mealMenu->forceFill([
            'status' => 'voting',
            'voting_opens_at' => now(),
            'voting_closes_at' => $data['voting_closes_at'] ?? $mealMenu->voting_closes_at,
        ])->save();

        return back()->with('success', 'Voting is now open for this menu.');
    }

    /* ------------------------------------------------------------------ *
     * APPROVAL - the control the module exists for
     * ------------------------------------------------------------------ */

    /**
     * APPROVE a menu. Only an Institution Admin or a Meal Manager may do this.
     *
     * Approving with an explicit `option_id` records WHICH dish was chosen. When
     * omitted, the leading option is adopted automatically - but only if there is
     * a clear winner (a tie requires a human to choose).
     */
    public function approve(Request $request, MealMenu $mealMenu)
    {
        if (! $this->canApprove($request->user())) {
            return back()->with('error', 'Only an Institution Admin or Meal Manager can approve a menu.');
        }

        if (! $mealMenu->isOpen()) {
            return back()->with('error', "A menu in \"{$mealMenu->statusLabel()}\" status cannot be approved.");
        }

        $data = $request->validate([
            'option_id' => ['nullable', 'integer', 'exists:meal_menu_options,id'],
            'approval_note' => ['nullable', 'string', 'max:500'],
        ]);

        // Resolve which option was approved.
        $optionId = $data['option_id'] ?? null;

        if ($optionId !== null) {
            // The chosen option must belong to THIS menu.
            $belongsToMenu = $mealMenu->options()->whereKey($optionId)->exists();

            if (! $belongsToMenu) {
                return back()->with('error', 'That option does not belong to this menu.');
            }
        } else {
            $leading = $mealMenu->leadingOption();

            if ($leading === null) {
                return back()->with('error', 'There is no clear winning option yet. Open the menu and choose one to approve.');
            }

            $optionId = $leading['option_id'];
        }

        $mealMenu->forceFill([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_note' => $data['approval_note'] ?? null,
        ])->save();

        AuditLogger::log('updated', "approved the menu \"{$mealMenu->title}\"", $mealMenu, [
            'option_id' => $optionId,
        ], ['subject_label' => $mealMenu->title, 'institution_id' => $mealMenu->institution_id]);

        return back()->with('success', "Menu \"{$mealMenu->title}\" approved and is now active.");
    }

    /** Reject a menu, with a reason its proposer will see. */
    public function reject(Request $request, MealMenu $mealMenu)
    {
        if (! $this->canApprove($request->user())) {
            return back()->with('error', 'Only an Institution Admin or Meal Manager can reject a menu.');
        }

        if (! $mealMenu->isOpen()) {
            return back()->with('error', "A menu in \"{$mealMenu->statusLabel()}\" status cannot be rejected.");
        }

        $data = $request->validate([
            'approval_note' => ['required', 'string', 'max:500'],
        ]);

        $mealMenu->forceFill([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'approval_note' => $data['approval_note'],
        ])->save();

        AuditLogger::log('updated', "rejected the menu \"{$mealMenu->title}\"", $mealMenu, [
            'reason' => $data['approval_note'],
        ], ['subject_label' => $mealMenu->title, 'institution_id' => $mealMenu->institution_id]);

        return back()->with('success', "Menu \"{$mealMenu->title}\" rejected.");
    }

    /** Withdraw a menu (its creator, or an approver). */
    public function cancel(Request $request, MealMenu $mealMenu)
    {
        $user = $request->user();

        $mayCancel = $mealMenu->created_by === $user->id || $this->canApprove($user);

        if (! $mayCancel) {
            return back()->with('error', 'Only the proposer or an approver can withdraw this menu.');
        }

        if (! $mealMenu->isOpen()) {
            return back()->with('error', 'An approved or rejected menu cannot be withdrawn.');
        }

        $mealMenu->forceFill(['status' => 'cancelled'])->save();

        return back()->with('success', 'Menu withdrawn.');
    }

    /* ------------------------------------------------------------------ *
     * MEMBER SIDE - voting
     * ------------------------------------------------------------------ */

    /** The member's own view: menus open for voting, plus their vote history. */
    public function myMenus(Request $request)
    {
        $user = $request->user();

        $open = MealMenu::query()
            ->where('status', 'voting')
            ->with(['options', 'votes' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('menu_date')
            ->get()
            ->filter(fn (MealMenu $menu) => $menu->isVotingOpen())
            ->map(fn (MealMenu $menu) => [
                'id' => $menu->id,
                'title' => $menu->title,
                'meal_type' => $menu->meal_type,
                'meal_type_label' => $menu->mealTypeLabel(),
                'menu_date' => $menu->menu_date?->format('j M Y'),
                'description' => $menu->description,
                'voting_closes_at' => $menu->voting_closes_at?->format('j M Y H:i'),
                'allow_vote_changes' => $menu->allow_vote_changes,
                'options' => $menu->options->map(fn ($option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'description' => $option->description,
                    'estimated_cost' => (float) $option->estimated_cost,
                    'is_recommended' => (bool) $option->is_recommended,
                ]),
                'my_vote_option_id' => $menu->votes->first()?->meal_menu_option_id,
                'my_vote_comment' => $menu->votes->first()?->comment,
                'tally' => $menu->tally(),
                'total_votes' => $menu->votes()->count(),
            ]);

        // What the member has voted on recently, so they can see the outcome.
        $history = MealMenu::query()
            ->whereIn('status', ['approved', 'rejected'])
            ->whereHas('votes', fn ($q) => $q->where('user_id', $user->id))
            ->with(['votes' => fn ($q) => $q->where('user_id', $user->id), 'options'])
            ->orderByDesc('menu_date')
            ->limit(10)
            ->get()
            ->map(fn (MealMenu $menu) => [
                'id' => $menu->id,
                'title' => $menu->title,
                'meal_type_label' => $menu->mealTypeLabel(),
                'menu_date' => $menu->menu_date?->format('j M Y'),
                'status' => $menu->status,
                'status_label' => $menu->statusLabel(),
                'status_tone' => $menu->statusTone(),
                'my_choice' => $menu->options->firstWhere('id', $menu->votes->first()?->meal_menu_option_id)?->name,
            ]);

        return Inertia::render('Member/Menus', [
            'openMenus' => $open->values(),
            'history' => $history,
            'canVote' => $this->isEligibleVoter($user),
        ]);
    }

    /**
     * CAST OR CHANGE a vote.
     *
     * The eligibility gate lives here AND in `mayVote()`: a member who does not
     * take meals (a staff member with `is_meal_participant = false`) is refused,
     * however they reached the endpoint.
     */
    public function vote(Request $request, MealMenu $mealMenu)
    {
        $user = $request->user();

        if (! $this->isEligibleVoter($user)) {
            return back()->with('error', 'You are not currently enrolled in meals, so you cannot vote on menus.');
        }

        if (! $mealMenu->isVotingOpen()) {
            return back()->with('error', 'Voting is not open for this menu.');
        }

        $data = $request->validate([
            'option_id' => ['required', 'integer', 'exists:meal_menu_options,id'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        // The option must belong to THIS menu - otherwise a crafted request could
        // vote for a dish on a different institution's menu.
        $option = $mealMenu->options()->whereKey($data['option_id'])->first();

        if (! $option) {
            return back()->with('error', 'That option does not belong to this menu.');
        }

        $existing = $mealMenu->votes()->where('user_id', $user->id)->first();

        // Changing a vote is only allowed when the menu permits it.
        if ($existing && ! $mealMenu->allow_vote_changes) {
            return back()->with('error', 'This vote has been locked. You cannot change your choice.');
        }

        // ONE vote per member per menu: update the existing row rather than adding
        // a second (the unique index would reject a duplicate anyway).
        $student = $user->studentRecord();

        $mealMenu->votes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'meal_menu_option_id' => $option->id,
                'student_id' => $student?->id,
                'comment' => $data['comment'] ?? null,
            ]
        );

        return back()->with(
            'success',
            $existing
                ? "Your vote was changed to \"{$option->name}\"."
                : "Thanks! Your vote for \"{$option->name}\" was recorded."
        );
    }

    /* ------------------------------------------------------------------ *
     * Eligibility + authorisation
     * ------------------------------------------------------------------ */

    /**
     * May this user vote?
     *
     * The SINGLE definition of eligibility: an active account that either holds
     * the Member role OR has opted into meals as staff (`is_meal_participant`).
     * An Admin/Meal Manager who does not take meals is deliberately excluded.
     */
    public function isEligibleVoter(?User $user): bool
    {
        if (! $user || ! $user->isActive()) {
            return false;
        }

        // A regular member always eats.
        if ($user->hasRole('Member')) {
            return true;
        }

        // Staff: only if they have explicitly opted into meals.
        return (bool) $user->is_meal_participant;
    }

    /** Convenience wrapper used by the show page. */
    protected function mayVote(?User $user, MealMenu $menu): bool
    {
        return $this->isEligibleVoter($user) && $menu->isVotingOpen();
    }

    /** Who may approve: an Institution Admin or a Meal Manager. */
    protected function canApprove(?User $user): bool
    {
        return $user !== null && ($user->isInstitutionAdmin() || $user->hasRole('Meal Manager') || $user->isSuperAdmin());
    }

    /**
     * How many people are eligible to vote - shown on the board so an approver can
     * judge turnout ("12 of 240 voted" is far more meaningful than "12 votes").
     */
    protected function eligibleVoterCount(): int
    {
        return User::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereHas('roles', fn ($q) => $q->where('name', 'Member'))
                    ->orWhere('is_meal_participant', true);
            })
            ->count();
    }
}
