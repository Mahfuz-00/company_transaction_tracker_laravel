<?php

namespace Tests\Feature\Api\Deposits;

use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\ApiTestCase;

/**
 * DEPOSITS API (Cash In) - GET/POST /api/deposits.
 *
 * The important invariant: recording a deposit writes BOTH the module row
 * (`deposits`) and its matching ledger row (`transactions`, type 'in'), inside
 * one transaction, so the pool balance and the module ledger can never diverge.
 */
class DepositApiTest extends ApiTestCase
{
    public function test_recording_a_deposit_writes_both_the_module_and_ledger_rows(): void
    {
        $institution = $this->makeInstitution();
        $staff = $this->makeStaff($institution);
        $member = $this->makeMember($institution);

        Sanctum::actingAs($staff);

        $this->postJson('/api/deposits', [
            'student_id' => $member->id,
            'amount' => 120,
            'kind' => 'personal',
            'payment_method' => 'cash',
        ])->assertCreated();

        // The module row...
        $this->assertDatabaseHas('deposits', [
            'student_id' => $member->id,
            'amount' => 120,
            'kind' => 'personal',
        ]);

        // ...and the mirrored ledger row the pool balance reads.
        $this->assertDatabaseHas('transactions', [
            'student_id' => $member->id,
            'type' => 'in',
            'amount' => 120,
            'source' => 'deposit',
        ]);
    }

    public function test_the_index_lists_the_ledger_with_month_totals(): void
    {
        $institution = $this->makeInstitution();
        $staff = $this->makeStaff($institution);
        $member = $this->makeMember($institution, ['name' => 'Ledger Member']);

        Sanctum::actingAs($staff);

        $this->postJson('/api/deposits', [
            'student_id' => $member->id,
            'amount' => 90,
        ])->assertCreated();

        $response = $this->getJson('/api/deposits')->assertOk();

        $response->assertJsonStructure([
            'data',
            'meta' => ['month', 'totals' => ['all', 'personal', 'subsidy'], 'kinds', 'members', 'pagination'],
        ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ledger Member', $response->json('data.0.member'));
        $this->assertEquals(90, $response->json('meta.totals.all'));
    }
}
