<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One meal-entry row (GET /api/meals).
 *
 * `total` is computed server-side so the client never re-adds the columns.
 *
 * @property \App\Models\MealEntry $resource
 */
class MealEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member' => $this->student?->name,
            'member_id' => $this->student_id,
            'roll' => $this->student?->roll,
            'date' => $this->date?->toDateString(),
            'breakfast' => $this->breakfast,
            'lunch' => $this->lunch,
            'dinner' => $this->dinner,
            'total' => (int) $this->breakfast + (int) $this->lunch + (int) $this->dinner,
        ];
    }
}
