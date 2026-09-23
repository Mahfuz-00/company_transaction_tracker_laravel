<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One subsidy row (GET /api/subsidies).
 *
 * `scope` resolves to the department, else the member, else the whole
 * institution - the same precedence the web UI shows.
 *
 * @property \App\Models\Subsidy $resource
 */
class SubsidyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_name' => $this->source_name,
            'amount' => (float) $this->amount,
            'percentage' => $this->percentage !== null ? (float) $this->percentage : null,
            'apply_mode' => $this->apply_mode,
            'period_month' => $this->period_month,
            'status' => $this->status,
            'scope' => $this->department?->name ?? $this->student?->name ?? 'Whole institution',
            'recorded_by' => $this->recorder?->name,
            'notes' => $this->notes,
            'date' => $this->created_at->toIso8601String(),
        ];
    }
}
