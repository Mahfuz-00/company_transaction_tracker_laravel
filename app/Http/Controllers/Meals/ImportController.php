<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\AuditLogger;
use App\Support\BulkImporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * BULK CSV / EXCEL IMPORT - the two-phase controller.
 *
 *   GET  /meals/import           : the upload screen (datasets + templates)
 *   POST /meals/import/analyse   : DRY RUN - validate and report, write nothing
 *   POST /meals/import/commit    : write the validated file
 *
 * The split is the entire safety story: `analyse` is a pure read, so an operator
 * can inspect exactly what a file would do before anything touches their roster.
 * `commit` re-validates the same upload server-side rather than trusting a preview
 * the client could have edited.
 */
class ImportController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Meals/Import/Index', [
            'datasets' => BulkImporter::definitions(),
            'institution' => Institution::current() ? [
                'id' => Institution::current()->id,
                'name' => Institution::current()->name,
            ] : null,
        ]);
    }

    /**
     * DRY RUN. Validates the whole file and returns the per-row report.
     */
    public function analyse(Request $request)
    {
        $data = $this->validateUpload($request);

        $file = $request->file('file');

        $report = (new BulkImporter)->analyse($file, $data['dataset'], Institution::current());

        AuditLogger::log('updated', "ran a dry-run import of {$report['total']} rows ({$data['dataset']})", null, [
            'dataset' => $data['dataset'],
            'total' => $report['total'],
            'valid' => $report['valid'],
            'invalid' => $report['invalid'],
        ], ['subject_label' => 'Bulk import', 'institution_id' => Institution::current()?->id]);

        // The report is shown on the SAME page, so the operator sees the preview
        // without losing their chosen dataset/file context.
        return back()->with('importPreview', $report);
    }

    /**
     * COMMIT. Writes the valid rows inside a single transaction.
     */
    public function commit(Request $request)
    {
        $data = $this->validateUpload($request);

        $result = (new BulkImporter)->commit(
            $request->file('file'),
            $data['dataset'],
            Institution::current(),
            $request->user()->id,
        );

        AuditLogger::log('created', "committed a bulk import ({$data['dataset']})", null, [
            'dataset' => $data['dataset'],
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'invalid' => $result['invalid'],
        ], ['subject_label' => 'Bulk import', 'institution_id' => Institution::current()?->id]);

        // Nothing valid to write is reported as an error, not a hollow success.
        if ($result['created'] === 0) {
            return back()->with(
                'error',
                $result['invalid'] > 0
                    ? "Nothing was imported - all {$result['invalid']} row(s) had errors. Run the dry run to see why."
                    : 'Nothing was imported - every row already exists.'
            );
        }

        $message = "Imported {$result['created']} row(s)";
        $message .= $result['skipped'] > 0 ? ", skipped {$result['skipped']} already present" : '';
        $message .= $result['invalid'] > 0 ? ", ignored {$result['invalid']} invalid" : '';
        $message .= '.';

        return redirect()
            ->route('meals.import.index')
            ->with('success', $message);
    }

    /**
     * Download a CSV TEMPLATE with the correct headers, so an operator starts from
     * a file that is guaranteed to pass header validation.
     */
    public function template(Request $request, string $dataset)
    {
        $definitions = collect(BulkImporter::definitions())->keyBy('value');

        abort_unless($definitions->has($dataset), 404);

        $definition = $definitions->get($dataset);

        $headers = array_map(fn (array $column) => $column['key'], $definition['columns']);

        // A single illustrative row makes the expected format obvious.
        $example = $this->exampleRow($dataset);

        $filename = "nomnomytics-{$dataset}-template.csv";

        return response()->streamDownload(function () use ($headers, $example) {
            $out = fopen('php://output', 'w');

            // Excel needs the BOM to read UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);
            fputcsv($out, $example);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One illustrative row per dataset. */
    protected function exampleRow(string $dataset): array
    {
        return match ($dataset) {
            'members' => ['Ayesha Rahman', 'NSU-1001', 'Computer Science', 'active', 'manager@institution.test', '+8801700000000'],
            'opening_balances' => ['NSU-1001', '5000', 'bank', 'Carried over from the previous system'],
            'historical_meals' => ['NSU-1001', now()->subDay()->toDateString(), '1', '1', '1'],
            default => [],
        };
    }

    /** Shared validation for the analyse + commit endpoints. */
    protected function validateUpload(Request $request): array
    {
        return $request->validate([
            'dataset' => ['required', 'string', Rule::in(array_keys(BulkImporter::DATASETS))],
            // 8 MB is generous for a roster/ledger export and keeps a bad upload
            // from exhausting memory when parsed.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
        ], [
            'file.mimes' => 'Upload a CSV file (in Excel choose File > Save As > CSV UTF-8).',
        ]);
    }
}
