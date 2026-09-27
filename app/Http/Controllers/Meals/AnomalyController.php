<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use App\Models\Institution;
use App\Support\AnomalyDetector;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * ANOMALY MONITOR - the admin review queue.
 *
 *   GET    /meals/anomalies          : the findings, filtered and ranked
 *   POST   /meals/anomalies/scan     : run the detectors now
 *   PATCH  /meals/anomalies/{a}/review : acknowledge / dismiss / resolve
 *
 * The scan is idempotent (see AnomalyDetector), so "Scan now" is safe to press as
 * often as an admin likes - it refreshes open findings and leaves anything already
 * actioned alone.
 */
class AnomalyController extends Controller
{
    public function index(Request $request)
    {
        $institution = Institution::current();

        $status = (string) $request->query('status', 'open');
        $kind = (string) $request->query('kind', '');
        $severity = (string) $request->query('severity', '');

        $query = Anomaly::query()
            ->with(['student:id,name,roll', 'reviewer:id,name'])
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($kind !== '', fn ($q) => $q->where('kind', $kind))
            ->when($severity !== '', fn ($q) => $q->where('severity', $severity))
            // Worst first: severity, then how far past the threshold it went.
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('score')
            ->orderByDesc('detected_for')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Anomaly $anomaly) => [
                'id' => $anomaly->id,
                'kind' => $anomaly->kind,
                'kind_label' => $anomaly->kindLabel(),
                'severity' => $anomaly->severity,
                'severity_tone' => $anomaly->severityTone(),
                'title' => $anomaly->title,
                'detail' => $anomaly->detail,
                'amount' => $anomaly->amount !== null ? (float) $anomaly->amount : null,
                'score' => $anomaly->score !== null ? (float) $anomaly->score : null,
                'detected_for' => $anomaly->detected_for?->format('j M Y'),
                'status' => $anomaly->status,
                'student' => $anomaly->student ? [
                    'id' => $anomaly->student->id,
                    'name' => $anomaly->student->name,
                    'roll' => $anomaly->student->roll,
                ] : null,
                'reviewed_by' => $anomaly->reviewer?->name,
                'reviewed_at' => $anomaly->reviewed_at?->format('j M Y'),
                'review_note' => $anomaly->review_note,
            ]);

        return Inertia::render('Meals/Anomalies/Index', [
            'anomalies' => $query,
            'kinds' => collect(Anomaly::KINDS)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'filters' => ['status' => $status, 'kind' => $kind, 'severity' => $severity],
            'summary' => [
                'open' => Anomaly::query()->where('status', 'open')->count(),
                'critical' => Anomaly::query()->where('status', 'open')->where('severity', 'critical')->count(),
                'acknowledged' => Anomaly::query()->where('status', 'acknowledged')->count(),
                'dismissed' => Anomaly::query()->where('status', 'dismissed')->count(),
                'last_scan' => Anomaly::query()->max('updated_at'),
            ],
            'institution' => $institution ? ['id' => $institution->id, 'name' => $institution->name] : null,
        ]);
    }

    /** Run every detector now and report what was found. */
    public function scan(Request $request)
    {
        $institution = Institution::current();

        $result = (new AnomalyDetector)->scan($institution?->id);

        AuditLogger::log('updated', 'ran the anomaly monitor', null, [
            'findings' => $result['findings'],
            'by_kind' => $result['by_kind'],
        ], ['subject_label' => 'Anomaly monitor', 'institution_id' => $institution?->id]);

        if ($result['findings'] === 0) {
            return back()->with('success', 'Scan complete. Nothing unusual was found.');
        }

        return back()->with(
            'success',
            "Scan complete. {$result['findings']} finding(s) await review."
        );
    }

    /** Acknowledge, dismiss or resolve a finding. */
    public function review(Request $request, Anomaly $anomaly)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'acknowledged', 'dismissed', 'resolved'])],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $anomaly->forceFill([
            'status' => $data['status'],
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        AuditLogger::log('updated', "marked an anomaly as {$data['status']}", $anomaly, [
            'kind' => $anomaly->kind,
        ], ['subject_label' => $anomaly->title, 'institution_id' => $anomaly->institution_id]);

        return back()->with('success', 'Finding updated.');
    }
}
