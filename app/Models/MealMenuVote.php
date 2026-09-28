<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ONE MEMBER'S VOTE on a menu option.
 *
 * The (meal_menu_id, user_id) UNIQUE index in the migration is the integrity rule
 * of the module: a member holds exactly ONE vote per menu. Changing your mind
 * UPDATES this row, it does not add a second vote.
 */
class MealMenuVote extends Model
{
    protected $fillable = [
        'meal_menu_id',
        'meal_menu_option_id',
        'user_id',
        'student_id',
        'comment',
    ];

    public function menu()
    {
        return $this->belongsTo(MealMenu::class, 'meal_menu_id');
    }

    public function option()
    {
        return $this->belongsTo(MealMenuOption::class, 'meal_menu_option_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
