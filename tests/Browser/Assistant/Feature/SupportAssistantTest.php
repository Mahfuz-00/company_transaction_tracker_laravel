<?php

namespace Tests\Browser\Assistant\Feature;

use App\Models\AssistantEscalation;
use App\Models\AssistantKnowledge;
use App\Models\AssistantThread;
use App\Support\SupportAssistant;
use Database\Seeders\AssistantKnowledgeSeeder;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * THE SELF-LEARNING SUPPORT ASSISTANT.
 *
 * WHAT THESE TESTS LOCK IN
 * ------------------------
 *   1. It answers from the corpus when it knows the answer.
 *   2. It ADMITS when it does not, rather than inventing a reply.
 *   3. An unanswered question is ESCALATED to the Software Super Admin.
 *   4. The SSA's reply becomes a new corpus row - and the SAME question is then
 *      answered autonomously with no human involvement. This is the whole
 *      "self-learning" requirement, and test #4 below is the proof of it.
 *   5. A flagged answer escalates too, carrying the bad answer with it.
 *   6. A guest may use it on the landing page, before having an account.
 *   7. The escalation queue is SSA-only.
 *
 * WHY THESE ASSERT AGAINST THE SERVICE, NOT THE MARKUP
 *   The retrieval engine and the learning loop are server-side behaviour. Asserting
 *   on rendered text would test the panel's markup instead, and would break every
 *   time the copy changed - while never actually proving that the assistant LEARNS.
 *   The browser tests below cover the parts that are genuinely about the UI.
 */
class SupportAssistantTest extends DuskTestCase
{
    use DuskSupport;

    /** The corpus must answer a documented question without a human. */
    public function test_it_answers_a_documented_question(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();

        $this->step('Guest', 'Assistant', 'answers a documented question', __LINE__);

        $assistant = new SupportAssistant($institution);

        $match = $assistant->search('How is the meal price calculated?');

        $this->assertNotNull($match, 'A documented question must match the corpus.');
        $this->assertStringContainsString(
            'subsidies',
            strtolower($match['knowledge']->answer),
            'The meal-price answer must explain the subsidy netting.'
        );
        $this->assertGreaterThan(
            SupportAssistant::CONFIDENT_THRESHOLD,
            $match['score'],
            'A near-verbatim question should score above the confident threshold.'
        );
    }

    /** A phrasing variant must resolve to the same answer as the canonical question. */
    public function test_it_matches_an_alternate_phrasing(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $assistant = new SupportAssistant($institution);

        $this->step('Guest', 'Assistant', 'matches an alternate phrasing', __LINE__);

        // "add a student" is a listed phrasing of "How do I add a member?".
        $match = $assistant->search('add a student');

        $this->assertNotNull($match, 'A listed phrasing must match its canonical answer.');
        $this->assertSame(
            'How do I add a member?',
            $match['knowledge']->question,
            'The phrasing must resolve to the member-creation answer.'
        );
    }

    /**
     * It must ADMIT ignorance rather than inventing an answer.
     *
     * This is the safety property: a support bot that fabricates a figure about
     * money is worse than no bot at all.
     */
    public function test_it_admits_when_it_does_not_know(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $assistant = new SupportAssistant($institution);

        $this->step('Guest', 'Assistant', 'declines an unknown question', __LINE__);

        $match = $assistant->search('what is the airspeed velocity of an unladen swallow');

        $this->assertNull($match, 'An unrelated question must NOT be matched to the nearest row.');
    }

    /**
     * THE LEARNING LOOP — the core requirement.
     *
     * Ask something unknown -> escalate -> SSA answers -> ask AGAIN -> answered with
     * no human involvement.
     */
    public function test_it_learns_from_an_ssa_answer_and_then_answers_autonomously(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $ssa = $this->makeSuperAdmin();
        $member = $this->makeMember($institution);

        $assistant = new SupportAssistant($institution);
        $thread = $assistant->openThread('dashboard', $member);

        $this->step('Member', 'Assistant', 'asks an unknown question', __LINE__);

        /*
         * THE QUESTION MUST BE GENUINELY OUTSIDE THE CORPUS.
         *
         * This test's whole point is the LEARNING LOOP, so the first turn has to
         * MISS. An earlier version asked "How do I export the vendor procurement
         * ledger?" - which shares the tokens `export` and `report` with the
         * seeded "How do I export a report?" entry and therefore scored above the
         * confidence floor. The assistant answered correctly, no escalation was
         * created, and a working feature failed its own test.
         *
         * The phrasing below shares no meaningful token with `AssistantKnowledge
         * Seeder`, so it reliably misses - and if the corpus later grows to cover
         * it, that is a signal to pick a different question, not to weaken the
         * assertion.
         */
        $unknown = 'Does the platform sync with an external residential hall booking service?';

        // 1. The assistant cannot answer, so it escalates.
        $result = $assistant->ask($thread, $unknown);

        $this->assertFalse($result['matched'], 'An undocumented question must not be answered.');
        $this->assertNotNull($result['escalation'], 'A failed answer must create an escalation.');

        $escalation = $result['escalation'];

        $this->assertTrue($escalation->isPending());
        $this->assertSame('unanswered', $escalation->reason);

        $this->step('SSA', 'Assistant', 'answers the escalation', __LINE__);

        // 2. The SSA answers it - which TEACHES the assistant.
        $knowledge = $escalation->resolveWith(
            'Not yet - the platform has no booking-service integration. Add the member manually.',
            $ssa
        );

        $this->assertInstanceOf(AssistantKnowledge::class, $knowledge);
        $this->assertSame('learned', $knowledge->source);
        $this->assertSame($escalation->id, $knowledge->source_escalation_id);
        $this->assertSame($ssa->id, $knowledge->authored_by);
        $this->assertFalse($escalation->fresh()->isPending(), 'The escalation must be closed.');

        $this->step('Member', 'Assistant', 'asks the SAME question again', __LINE__);

        // 3. The SAME question is now answered with NO human involvement.
        $second = $assistant->ask($thread, $unknown);

        $this->assertTrue(
            $second['matched'],
            'After the SSA answered, the same question must be handled autonomously.'
        );
        $this->assertNull($second['escalation'], 'A learned question must not escalate again.');
        $this->assertSame(
            $knowledge->id,
            $second['message']->assistant_knowledge_id,
            'The reply must cite the learned knowledge row.'
        );

        // The learning is also measurable: the row recorded a use.
        $this->assertSame(1, $knowledge->fresh()->times_used);
    }

    /** Flagging a wrong answer escalates AND carries the bad answer along. */
    public function test_flagging_a_wrong_answer_escalates_it_with_the_bad_answer(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $assistant = new SupportAssistant($institution);
        $thread = $assistant->openThread('dashboard', $member);

        // Ask something the corpus DOES answer, then reject the answer.
        $result = $assistant->ask($thread, 'How do I record a deposit?');

        $this->assertTrue($result['matched']);

        $answer = $result['message'];

        $this->step('Member', 'Assistant', 'flags a wrong answer', __LINE__);

        $escalation = $assistant->flag($answer, 'It did not mention reversing a deposit.');

        $this->assertNotNull($escalation);
        $this->assertSame('flagged', $escalation->reason);
        $this->assertNotNull(
            $escalation->previous_answer,
            'A flagged escalation must carry the answer that was rejected.'
        );

        /*
         * CONTAINS, NOT EQUALS.
         *
         * `flag()` deliberately APPENDS the user's comment to the question
         * (`"...\n\n[User added]: ..."`) so the SSA sees what the user objected to,
         * not merely that something was rejected. Asserting an exact match on the
         * bare question therefore fails against behaviour that is correct - and
         * would only pass if the comment were silently dropped.
         */
        $this->assertStringContainsString('How do I record a deposit?', $escalation->question);
        $this->assertStringContainsString(
            'It did not mention reversing a deposit.',
            $escalation->question,
            'The user\'s comment must travel with the flagged question.'
        );

        // The rating is persisted on the message, and the corpus row is penalised.
        $this->assertFalse($answer->fresh()->was_helpful);
        $this->assertSame(1, $result['message']->knowledge->fresh()->times_unhelpful);
    }

    /** Marking an answer helpful is recorded, and does NOT escalate. */
    public function test_marking_an_answer_helpful_does_not_escalate(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $assistant = new SupportAssistant($institution);
        $thread = $assistant->openThread('dashboard', $member);

        $result = $assistant->ask($thread, 'How do I record a deposit?');

        $this->step('Member', 'Assistant', 'marks an answer helpful', __LINE__);

        $assistant->markHelpful($result['message']);

        $this->assertTrue($result['message']->fresh()->was_helpful);
        $this->assertSame(0, AssistantEscalation::count(), 'A helpful answer must not escalate.');
    }

    /** The assistant is PUBLIC: a guest on the landing page can use it. */
    public function test_a_guest_can_ask_from_the_landing_page(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $this->step('Guest', 'Assistant', 'asks from the public landing page', __LINE__);

        // No authentication: this is the pre-signup case the feature exists for.
        $response = $this->postJson(route('assistant.ask'), [
            'question' => 'How is the meal price calculated?',
            'surface' => 'landing',
        ]);

        $response->assertOk()
            ->assertJson(['matched' => true])
            ->assertJsonStructure(['token', 'answer', 'matched', 'escalated']);

        // A guest's thread is keyed by a random token, never a sequential id.
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame(1, AssistantThread::whereNotNull('token')->count());
    }

    /** An unanswered guest question escalates rather than being silently dropped. */
    public function test_a_guest_question_is_escalated_when_unknown(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $this->step('Guest', 'Assistant', 'an unknown guest question escalates', __LINE__);

        $this->postJson(route('assistant.ask'), [
            'question' => 'Does the platform integrate with a turnstile access control system?',
            'surface' => 'landing',
        ])->assertOk()->assertJson(['matched' => false, 'escalated' => true]);

        $escalation = AssistantEscalation::first();

        $this->assertNotNull($escalation);
        $this->assertTrue($escalation->isPending());
        // A guest has no institution, so the escalation is platform-level.
        $this->assertNull($escalation->institution_id);
    }

    /** The question field is validated: an empty question is refused. */
    public function test_it_refuses_an_empty_question(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Assistant', 'refuses an empty question', __LINE__);

        $this->postJson(route('assistant.ask'), ['question' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');
    }

    /** The escalation queue is Software-Super-Admin only. */
    public function test_the_escalation_queue_is_ssa_only(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);
        $ssa = $this->makeSuperAdmin();

        $this->step('Member', 'Assistant', 'a member cannot reach the queue', __LINE__);

        // A tenant role must not read a cross-tenant queue.
        $this->httpAs($member)
            ->get(route('ssa.assistant.index'))
            ->assertForbidden();

        $this->step('SSA', 'Assistant', 'the SSA can reach the queue', __LINE__);

        $this->httpAs($ssa)
            ->get(route('ssa.assistant.index'))
            ->assertOk();
    }

    /** Resolving through the HTTP endpoint teaches the assistant. */
    public function test_answering_via_the_queue_endpoint_creates_knowledge(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);
        $ssa = $this->makeSuperAdmin();

        $assistant = new SupportAssistant($institution);
        $thread = $assistant->openThread('dashboard', $member);

        // Must MISS the corpus so an escalation is created - see the note in
        // test_it_learns_from_an_ssa_answer_and_then_answers_autonomously.
        $escalation = $assistant->ask($thread, 'Does the platform sync with an external residential hall booking service?')['escalation'];

        $this->assertNotNull($escalation);

        $this->step('SSA', 'Assistant', 'answers through the queue endpoint', __LINE__);

        $this->httpAs($ssa)
            ->patch(route('ssa.assistant.update', $escalation->id), [
                'action' => 'answer',
                'resolution' => 'Not currently. Assign the member to their primary department.',
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, AssistantKnowledge::where('source', 'learned')->count());
        $this->assertNotNull($escalation->fresh()->learned_knowledge_id);
    }

    /** A tenant's own answer takes precedence over the platform's for its users. */
    public function test_a_tenant_answer_is_visible_to_that_tenant(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();

        // A workspace-specific answer about its own process.
        AssistantKnowledge::create([
            'institution_id' => $institution->id,
            'question' => 'When is our monthly reconciliation?',
            'answer' => 'On the 3rd of each month, in the accounts office.',
            'phrasings' => [],
            'keywords' => ['reconciliation', 'monthly'],
            'source' => 'learned',
            'is_active' => true,
        ]);

        $this->step('IA', 'Assistant', 'a tenant answer is retrievable', __LINE__);

        $match = (new SupportAssistant($institution))->search('When is our monthly reconciliation?');

        $this->assertNotNull($match);
        $this->assertSame($institution->id, $match['knowledge']->institution_id);
    }

    /** The panel renders on the dashboard for a signed-in user. */
    public function test_the_panel_is_available_on_the_dashboard(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $institution = $this->makeInstitution();
        $member = $this->makeMember($institution);

        $this->step('Member', 'Assistant', 'the launcher renders in the app shell', __LINE__);

        $this->browse(function ($browser) use ($member) {
            $browser->loginAs($member)
                ->visit('/my/dashboard')
                ->waitFor('[data-testid="assistant-launcher"]', 20)
                ->assertVisible('[data-testid="assistant-launcher"]')
                // Open the panel.
                ->click('[data-testid="assistant-launcher"]')
                ->waitFor('[data-testid="assistant-panel"]', 20)
                ->assertVisible('[data-testid="assistant-panel"]')
                ->assertVisible('[data-testid="assistant-input"]');
        });
    }

    /** The panel is mounted publicly on the landing page. */
    public function test_the_launcher_is_publicly_available_on_the_landing_page(): void
    {
        $this->seedRbac();

        $this->step('Guest', 'Assistant', 'the launcher renders on the landing page', __LINE__);

        $this->browse(function ($browser) {
            $browser->visit('/')
                ->waitFor('[data-testid="assistant-launcher"]', 20)
                ->assertVisible('[data-testid="assistant-launcher"]');
        });
    }

    /** A guest can ask through the browser and get an answer. */
    public function test_a_guest_can_ask_through_the_browser(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $this->step('Guest', 'Assistant', 'asks and receives an answer in the browser', __LINE__);

        $this->browse(function ($browser) {
            $browser->visit('/')
                ->waitFor('[data-testid="assistant-launcher"]', 20)
                ->click('[data-testid="assistant-launcher"]')
                ->waitFor('[data-testid="assistant-input"]', 20)
                ->type('[data-testid="assistant-input"]', 'How is the meal price calculated?')
                ->click('[data-testid="assistant-send"]')
                // The answer bubble appears with the grounded answer.
                ->waitFor('[data-testid="assistant-answer"]', 20)
                ->assertVisible('[data-testid="assistant-answer"]')
                // The user's own turn is echoed too.
                ->assertVisible('[data-testid="assistant-user-message"]');
        });
    }

    /** The escalation queue screen renders for the SSA. */
    public function test_the_queue_screen_renders_for_the_ssa(): void
    {
        $this->seedRbac();
        $this->seed(AssistantKnowledgeSeeder::class);

        $ssa = $this->makeSuperAdmin();

        $this->step('SSA', 'Assistant', 'the queue screen renders', __LINE__);

        $this->browse(function ($browser) use ($ssa) {
            $browser->loginAs($ssa)
                ->visit(route('ssa.assistant.index'))
                // The VISIBLE heading. The page's <Head> title is "Support Assistant
                // Queue", but `waitForText` matches RENDERED text, and the on-screen
                // headings are "Support Assistant" (the H1) and "Assistant escalation
                // queue" (the panel). Waiting for the document title times out.
                ->waitForText('Assistant escalation queue', 20)
                ->assertSee('Assistant escalation queue');
        });
    }

    /** Corpus quality flags an answer users repeatedly reject. */
    public function test_a_repeatedly_rejected_answer_looks_unreliable(): void
    {
        $this->seedRbac();

        $knowledge = AssistantKnowledge::create([
            'institution_id' => null,
            'question' => 'How do I archive a member?',
            'answer' => 'Use the Archive button.',
            'phrasings' => [],
            'keywords' => [],
            'source' => 'docs',
            'is_active' => true,
        ]);

        // Four uses, three of them rejected.
        $knowledge->forceFill(['times_used' => 4, 'times_unhelpful' => 3])->save();

        $this->assertTrue(
            $knowledge->fresh()->looksUnreliable(),
            'An answer rejected in most of its uses must be flagged for rewriting.'
        );
    }
}
