<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Institution;
use App\Models\MemberPayment;
use App\Models\SavedReport;
use App\Models\Student;
use App\Models\Transaction;
use App\Support\AuditLogger;
use App\Support\ReportBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * DYNAMIC REPORTS BUILDER.
 *
 *   GET    /meals/report-builder        : the builder + saved reports
 *   POST   /meals/report-builder/run    : run an ad-hoc definition (no save)
 *   POST   /meals/report-builder        : save a definition
 *   PUT    /meals/report-builder/{r}    : update a saved report
 *   DELETE /meals/report-builder/{r}    : delete
 *   GET    /meals/report-builder/{r}/run: run a saved report
 *
 * The heavy lifting (and every security whitelist) lives in App\Support\ReportBuilder;
 * this controller is validation + persistence + presentation.
 */
class ReportBuilderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $reports = SavedReport::query()
            ->visibleTo($user)
            ->with('owner:id,name')
            ->orderByDesc('is_pinned')
            ->orderBy('name')
            ->get()
            ->map(fn (SavedReport $report) => [
                'id' => $report->id,
                'name' => $report->name,
                'description' => $report->description,
                'definition' => $report->definition,
                'is_shared' => $report->is_shared,
                'is_pinned' => $report->is_pinned,
                'owner' => $report->owner?->name,
                'owner_id' => $report->user_id,
                'run_count' => $report->run_count,
                'last_run_at' => $report->last_run_at?->diffForHumans(),
                'can_edit' => $report->user_id === $user->id || $user->isInstitutionAdmin(),
            ]);

        return Inertia::render('Meals/Reports/Builder', [
            'catalogue' => ReportBuilder::catalogue(),
            'reports' => $reports,
            // Filter option sources, so the builder's pickers are populated from the
            // SAME tenant-scoped data the queries will run against.
            'options' => [
                'members' => Student::query()->orderBy('name')->get(['id', 'name', 'roll'])
                    ->map(fn (Student $s) => ['value' => $s->id, 'label' => $s->name.($s->roll ? " ({$s->roll})" : '')]),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name]),
                'categories' => Transaction::query()
                    ->where('type', 'expense')
                    ->whereNotNull('category')
                    ->distinct()
                    ->pluck('category')
                    ->map(fn ($c) => ['value' => $c, 'label' => $c]),
                'methods' => collect(MemberPayment::METHODS)
                    ->map(fn (array $meta, string $key) => ['value' => $key, 'label' => $meta['label']])
                    ->values(),
            ],
        ]);
    }

    /**
     * RUN an ad-hoc definition without saving it.
     *
     * A definition that fails validation returns a readable error rather than a
     * 500, because the user is composing it interactively.
     */
    public function run(Request $request)
    {
        $definition = (array) $request->input('definition', []);

        try {
            $result = (new ReportBuilder)->run($definition);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('reportResult', $result);
    }

    /** Save a definition as a reusable report. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'definition' => ['required', 'array'],
            'is_shared' => ['boolean'],
        ]);

        // Validate BEFORE persisting, so an unusable report can never be saved.
        try {
            $definition = (new ReportBuilder)->validateDefinition($data['definition']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['definition' => $e->getMessage()]);
        }

        // Only an Institution Admin (or the SSA) may publish to the whole workspace.
        $isShared = $request->boolean('is_shared')
            && ($request->user()->isInstitutionAdmin() || $request->user()->isSuperAdmin());

        $report = SavedReport::create([
            'institution_id' => Institution::current()?->id,
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'definition' => $definition,
            'is_shared' => $isShared,
        ]);

        AuditLogger::log('created', "saved the report \"{$report->name}\"", $report, [
            'dataset' => $definition['dataset'],
        ], ['subject_label' => $report->name, 'institution_id' => $report->institution_id]);

        return back()->with('success', "Report \"{$report->name}\" saved.");
    }

    /** Update a saved report (owner or an Institution Admin). */
    public function update(Request $request, SavedReport $savedReport)
    {
        $this->authoriseManage($request, $savedReport);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'definition' => ['required', 'array'],
            'is_shared' => ['boolean'],
            'is_pinned' => ['boolean'],
        ]);

        try {
            $definition = (new ReportBuilder)->validateDefinition($data['definition']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['definition' => $e->getMessage()]);
        }

        $isShared = $request->boolean('is_shared')
            && ($request->user()->isInstitutionAdmin() || $request->user()->isSuperAdmin());

        $savedReport->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'definition' => $definition,
            'is_shared' => $isShared,
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        return back()->with('success', "Report \"{$savedReport->name}\" updated.");
    }

    public function destroy(Request $request, SavedReport $savedReport)
    {
        $this->authoriseManage($request, $savedReport);

        $name = $savedReport->name;
        $savedReport->delete();

        return redirect()
            ->route('meals.report-builder.index')
            ->with('success', "Report \"{$name}\" deleted.");
    }

    /** Run a SAVED report and return its rows. */
    public function runSaved(Request $request, SavedReport $savedReport)
    {
        // A private report is readable only by its owner.
        abort_unless(
            $savedReport->user_id === $request->user()->id || $savedReport->is_shared || $request->user()->isInstitutionAdmin(),
            403
        );

        try {
            $result = (new ReportBuilder)->run((array) $savedReport->definition);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', 'This report can no longer be run: '.$e->getMessage());
        }

        $savedReport->forceFill([
            'last_run_at' => now(),
            'run_count' => $savedReport->run_count + 1,
        ])->save();

        return back()->with('reportResult', array_merge($result, ['report_name' => $savedReport->name]));
    }

    /** Owner or an Institution Admin may change or remove a saved report. */
    protected function authoriseManage(Request $request, SavedReport $report): void
    {
        $user = $request->user();

        abort_unless(
            $report->user_id === $user->id || $user->isInstitutionAdmin() || $user->isSuperAdmin(),
            403,
            'You can only manage reports you created.'
        );
    }
}
