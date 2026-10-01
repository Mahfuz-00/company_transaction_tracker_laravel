<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\MealSchedule;
use App\Support\AuditLogger;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MealScheduleController extends Controller
{
    /**
     * Member view of their meal notices/schedules.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $student = $user->studentRecord();

        if (! $student) {
            return redirect()->route('dashboard')->with('error', 'Your account is not linked to an active member record.');
        }

        $schedules = MealSchedule::query()
            ->where('student_id', $student->id)
            ->orderByDesc('starts_on')
            ->paginate(15);

        return Inertia::render('Meals/Schedules/Index', [
            'schedules' => $schedules,
            'student' => $student,
        ]);
    }

    /**
     * Submit a meal off/on schedule declaration.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $student = $user->studentRecord();

        if (! $student) {
            return back()->with('error', 'Your account is not linked to an active member record.');
        }

        $data = $request->validate([
            'status' => ['required', 'in:off,on'],
            'recurrence' => ['required', 'in:one_time,daily,weekly,custom'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'weekdays' => ['nullable', 'string'],
            'breakfast' => ['boolean'],
            'lunch' => ['boolean'],
            'dinner' => ['boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $schedule = MealSchedule::create([
            'institution_id' => $student->institution_id ?? Institution::current()?->id,
            'student_id' => $student->id,
            'user_id' => $user->id,
            'status' => $data['status'],
            'recurrence' => $data['recurrence'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? $data['starts_on'],
            'interval_days' => $data['interval_days'] ?? null,
            'weekdays' => $data['weekdays'] ?? null,
            'breakfast' => $request->boolean('breakfast', true),
            'lunch' => $request->boolean('lunch', true),
            'dinner' => $request->boolean('dinner', true),
            'reason' => $data['reason'] ?? null,
            'manager_status' => 'pending',
        ]);

        AuditLogger::log('created', "submitted meal schedule declaration ({$schedule->status})", $schedule, [
            'status' => $schedule->status,
            'starts_on' => $schedule->starts_on,
            'ends_on' => $schedule->ends_on,
        ]);

        // Notify assigned manager or institution admins
        $manager = Notifier::assignedManager($student);
        if ($manager) {
            Notifier::send([$manager], 'meal_schedule', 'New Member Meal Schedule Notice', "{$student->name} declared meal {$schedule->status} starting {$schedule->starts_on->format('Y-m-d')}.");
        }

        return back()->with('success', 'Meal schedule notice submitted to your meal manager.');
    }

    /**
     * Meal manager queue to review / acknowledge schedules.
     */
    public function review(Request $request)
    {
        $user = $request->user();
        $scopedIds = $user->scopedStudentIds();

        $schedules = MealSchedule::query()
            ->with(['student:id,name,roll', 'acknowledgedBy:id,name'])
            ->when($scopedIds !== null, fn ($q) => $q->whereIn('student_id', $scopedIds))
            ->orderByRaw("CASE WHEN manager_status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('starts_on')
            ->paginate(20);

        return Inertia::render('Meals/Schedules/Review', [
            'schedules' => $schedules,
        ]);
    }

    /**
     * Acknowledge a meal schedule notice.
     */
    public function acknowledge(Request $request, MealSchedule $mealSchedule)
    {
        $mealSchedule->update([
            'manager_status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        AuditLogger::log('updated', "acknowledged meal schedule notice #{$mealSchedule->id}", $mealSchedule);

        if ($mealSchedule->user) {
            Notifier::send([$mealSchedule->user], 'meal_schedule', 'Meal Schedule Acknowledged', 'Your meal manager acknowledged your meal notice.');
        }

        return back()->with('success', 'Meal schedule notice acknowledged.');
    }
}
