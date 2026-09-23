<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the deposit ledger (GET /api/deposits).
 *
 * Expects `student` and `recorder` to be eager-loaded by the controller; the
 * resource only formats them, it never triggers its own queries.
 *
 * @property \App\Models\Deposit $resource
 */
class DepositResource extends JsonResource
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
            // Money always goes over the wire as a number; currency metadata is
            // served once by /api/meta so the client formats it locally.
            'amount' => (float) $this->amount,
            'kind' => $this->kind,
            'payment_method' => $this->payment_method,
            'recorded_by' => $this->recorder?->name,
            'notes' => $this->notes,
            'date' => $this->created_at->toIso8601String(),
        ];
    }
}
