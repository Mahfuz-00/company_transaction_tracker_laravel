<?php

namespace Tests\Browser\Ai;

use App\Models\AssistantKnowledge;
use App\Support\SupportAssistant;
use Database\Seeders\AssistantKnowledgeSeeder;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * Point 3: Fully Autonomous Self-Learning AI Support Assistant & Documentation.
 *
 * Verifies:
 * - Autonomous Software Learning: AI Assistant answers software codebase/feature questions.
 * - Smart Fallback to SSA: unresolvable query routes to SSA dashboard escalation.
 * - Self-learning loop: Once SSA resolves, AI permanently learns and handles identical future queries autonomously.
 */
class SelfLearningAssistantTest extends DuskTestCase
{
    use DuskSupport;

    public function test_autonomous_assistant_answers_codebase_knowledge(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $assistant = new SupportAssistant($institution);

        $this->step('Member', 'AI', 'queries meal price calculation', __LINE__);

        $match = $assistant->search('How is the meal price calculated?');

        $this->assertNotNull($match, 'Documented question must match corpus autonomously.');
        $this->assertStringContainsString('subsidies', strtolower($match['knowledge']->answer));
        $this->assertGreaterThan(SupportAssistant::CONFIDENT_THRESHOLD, $match['score']);
    }

    public function test_smart_fallback_to_ssa_and_autonomous_learning(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $ssa = $this->makeSuperAdmin();
        $member = $this->makeMember($institution);

        $assistant = new SupportAssistant($institution);
        $thread = $assistant->openThread('dashboard', $member);

        $unknownQuery = 'Does the platform sync with an external residential hall booking service?';

        $this->step('Member', 'AI', 'asks completely outside query to trigger fallback', __LINE__);

        // 1. Initial query fails and escalates
        $result = $assistant->ask($thread, $unknownQuery);
        $this->assertFalse($result['matched'], 'Unknown query should not match.');
        $this->assertNotNull($result['escalation'], 'Query must fall back to SSA escalation.');

        $escalation = $result['escalation'];
        $this->assertTrue($escalation->isPending());

        $this->step('SSA', 'AI', 'SSA resolves escalation to teach assistant', __LINE__);

        // 2. SSA resolves escalation
        $knowledge = $escalation->resolveWith(
            'The third-party automated meal subsidy API is planned for Q3 and can be configured under Settings > Integrations.',
            $ssa
        );

        $this->assertInstanceOf(AssistantKnowledge::class, $knowledge);
        $this->assertSame('learned', $knowledge->source);
        $this->assertFalse($escalation->fresh()->isPending());

        $this->step('Member', 'AI', 'asks identical query again to verify autonomous answer', __LINE__);

        // 3. Asking again should now be resolved autonomously
        $secondResult = $assistant->ask($thread, $unknownQuery);
        $this->assertTrue($secondResult['matched'], 'AI must now answer autonomously from learned knowledge.');
        $this->assertNull($secondResult['escalation'], 'No escalation should be created.');
        $this->assertSame($knowledge->id, $secondResult['message']->assistant_knowledge_id);
    }
}
