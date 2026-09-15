<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'roll', 'department_id', 'join_date', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    public function entries()
    {
        return $this->hasMany(MealEntry::class);
    }
}
