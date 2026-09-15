<?php

namespace App\Http\Controllers\Meals;

use App\Http\Controllers\Controller;
use App\Models\MealEntry;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MealEntryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:meals.entry');
    }

    public function index()
    {
        $entries = MealEntry::with('student')->orderBy('date', 'desc')->paginate(30);
        return Inertia::render('Meals/Entries/Index', ['entries' => $entries]);
    }

    public function create()
    {
        $students = Student::orderBy('name')->get();
        return Inertia::render('Meals/Entries/Create', ['students' => $students]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'breakfast' => 'nullable|integer|min:0|max:10',
            'lunch' => 'nullable|integer|min:0|max:10',
            'dinner' => 'nullable|integer|min:0|max:10',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            $entry = MealEntry::updateOrCreate(
                ['student_id' => $data['student_id'], 'date' => $data['date']],
                [
                    'breakfast' => $data['breakfast'] ?? 0,
                    'lunch' => $data['lunch'] ?? 0,
                    'dinner' => $data['dinner'] ?? 0,
                    'recorded_by' => auth()->id(),
                    'notes' => $data['notes'] ?? null,
                ]
            );

            return redirect()->route('meals.entries.index')->with('success', 'Meal entry saved.');
        });
    }
}
