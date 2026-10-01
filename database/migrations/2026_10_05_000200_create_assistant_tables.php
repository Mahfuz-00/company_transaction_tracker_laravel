<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SELF-LEARNING AI SUPPORT ASSISTANT.
 *
 * THREE TABLES, THREE DISTINCT JOBS
 * ---------------------------------
 *   `assistant_knowledge`  - the ANSWERS the assistant is allowed to give, each
 *                            with the question phrasings that should retrieve it.
 *   `assistant_threads`    - one conversation, with its messages.
 *   `assistant_escalations`- questions the assistant could NOT answer (or that the
 *                            user FLAGGED), routed to the Software Super Admin.
 *
 * WHY THE ASSISTANT IS NOT A BLACK BOX
 * ------------------------------------
 * A support bot that invents an answer about a financial figure is worse than no
 * bot at all: the user acts on a wrong number and blames the platform. So the
 * assistant is a RETRIEVAL system over a curated corpus, and it is explicitly
 * allowed to say "I don't know" - which is the trigger for escalation.
 *
 * HOW IT LEARNS (the whole point of the feature)
 * ----------------------------------------------
 *   1. A user asks something the corpus does not cover -> the assistant says so
 *      and offers to escalate.
 *   2. The question lands in `assistant_escalations` for the SSA.
 *   3. The SSA writes an answer.
 *   4. That answer is stored as a NEW `assistant_knowledge` row (`source =
 *      'learned'`), keyed to the question that produced it.
 *   5. The SAME question next time is answered autonomously.
 *
 * The learning is therefore auditable: every learned answer can be traced back to
 * the escalation that created it (`source_escalation_id`), and to the operator who
 * wrote it (`authored_by`). Nothing is inferred silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * THE CORPUS. One row per answerable question.
         */
        Schema::create('assistant_knowledge', function (Blueprint $table) {
            $table->id();

            // Which workspace this answer belongs to, or NULL for the PLATFORM
            // corpus that every institution shares (product-level help). A tenant
            // can add answers specific to its own terminology and process.
            $table->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();

            // The canonical question, as a human would phrase it.
            $table->string('question', 500);

            // The answer shown to the user. Supports simple markdown-ish line breaks.
            $table->text('answer');

            /*
             * ALTERNATE PHRASINGS.
             *
             * A JSON array of strings. Retrieval matches against these as well as
             * the canonical question, which is what makes "how do I add a member"
             * and "add student" resolve to the same answer. Storing them here
             * rather than as separate rows keeps one answer per topic, which is
             * what a curator actually maintains.
             */
            $table->json('phrasings')->nullable();

            // Keywords that boost a match, for terms the user types but that do not
            // appear in the question phrasing (e.g. "roll", "invite code").
            $table->json('keywords')->nullable();

            // docs   - shipped with the platform
            // learned- written by the SSA in response to an escalation
            $table->string('source', 20)->default('docs');

            // Which escalation produced this learned answer (null for docs).
            $table->foreignId('source_escalation_id')->nullable();

            // Who wrote it, for the learned rows.
            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * HOW OFTEN THIS ANSWER HAS BEEN USED, and how often users said it was
             * NOT helpful. Together these are the corpus's quality signal: a row
             * with many uses and no complaints is trustworthy, while one with
             * repeated "not helpful" flags is a candidate for rewriting - which is
             * itself a form of learning from real use.
             */
            $table->unsignedInteger('times_used')->default(0);
            $table->unsignedInteger('times_unhelpful')->default(0);

            // A curator can retire an answer without deleting its history.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['institution_id', 'is_active']);
        });

        /*
         * CONVERSATIONS.
         */
        Schema::create('assistant_threads', function (Blueprint $table) {
            $table->id();

            // A guest on the public landing page has no account, so this is
            // nullable. It is what lets the assistant work BEFORE signup.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            // A random public identifier so a guest's thread can be continued
            // without exposing sequential ids to the browser.
            $table->string('token', 64)->unique();

            // landing | dashboard
            $table->string('surface', 20)->default('dashboard');

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assistant_thread_id')->constrained()->cascadeOnDelete();

            // user | assistant
            $table->string('role', 12);

            $table->text('body');

            /*
             * Which knowledge row produced an assistant message, so a user can see
             * WHY they got that answer and the platform can trace a bad answer back
             * to its source. Null when the assistant could not answer.
             */
            $table->foreignId('assistant_knowledge_id')->nullable()->constrained('assistant_knowledge')->nullOnDelete();

            // How confident the retrieval was (0..1). Surfaced so a low-confidence
            // answer is visibly hedged rather than stated flatly.
            $table->decimal('confidence', 5, 4)->nullable();

            // Did the user mark this answer helpful? null = not yet rated.
            $table->boolean('was_helpful')->nullable();

            $table->timestamps();

            $table->index(['assistant_thread_id', 'created_at']);
        });

        /*
         * ESCALATIONS - the queue that drives the learning loop.
         */
        Schema::create('assistant_escalations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assistant_thread_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();

            // Snapshotted so the queue stays readable after an account is removed.
            $table->string('asked_by_name')->nullable();
            $table->string('asked_by_email')->nullable();

            // The question the assistant could not answer.
            $table->text('question');

            /*
             * WHY it was escalated, which is not always "it did not know":
             *   unanswered - no corpus match above the confidence floor
             *   flagged    - the assistant answered, but the user said it was wrong
             */
            $table->string('reason', 20)->default('unanswered');

            // The answer the assistant DID give, when the reason is `flagged`. Kept
            // so the SSA can see what was wrong, not just that something was.
            $table->text('previous_answer')->nullable();

            // pending | answered | dismissed
            $table->string('status', 20)->default('pending');

            // The SSA's reply - which becomes a learned answer on resolution.
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // The knowledge row created from this resolution, closing the loop.
            $table->foreignId('learned_knowledge_id')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_escalations');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_threads');
        Schema::dropIfExists('assistant_knowledge');
    }
};
