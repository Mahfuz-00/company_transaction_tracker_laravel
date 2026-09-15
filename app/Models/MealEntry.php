<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealEntry extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'date', 'breakfast', 'lunch', 'dinner', 'recorded_by', 'notes'];

    protected $casts = [
        'date' => 'date',
        'breakfast' => 'integer',
        'lunch' => 'integer',
        'dinner' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getTotalMealsAttribute()
    {
        return ($this->breakfast ?? 0) + ($this->lunch ?? 0) + ($this->dinner ?? 0);
    }
}
