<?php

namespace Database\Seeders;

use App\Models\AssistantKnowledge;
use Illuminate\Database\Seeder;

/**
 * THE PLATFORM DOCUMENTATION CORPUS.
 *
 * Every row here is `institution_id = NULL`, which makes it PLATFORM-WIDE: every
 * workspace's assistant can retrieve it. Tenant-specific answers are added later by
 * the learning loop (see AssistantEscalation::resolveWith).
 *
 * WHY SEED CONTENT AT ALL
 * -----------------------
 * An assistant with an empty corpus answers nothing, escalates everything, and the
 * SSA's queue fills with questions whose answers were already written down. Seeding
 * the shipped documentation is what makes the feature useful on day one, and it
 * gives the learning loop a baseline to extend rather than replace.
 *
 * `phrasings` carry the synonyms a real user types; `keywords` carry the domain
 * nouns that do not appear in the phrasing but do appear in questions ("roll",
 * "invite code", "dues"). Both feed the lexical scorer in SupportAssistant.
 *
 * IDEMPOTENT: keyed on the canonical question, so re-running updates rather than
 * duplicating. That matters because this runs on every deploy via the baseline
 * seeder.
 */
class AssistantKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->entries() as $entry) {
            AssistantKnowledge::updateOrCreate(
                [
                    'institution_id' => null,
                    'question' => $entry['question'],
                ],
                [
                    'answer' => $entry['answer'],
                    'phrasings' => $entry['phrasings'] ?? [],
                    'keywords' => $entry['keywords'] ?? [],
                    'source' => 'docs',
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * The shipped answers.
     *
     * Deliberately written in plain language: the reader is a mess manager or a
     * member, not an administrator. Each answer says what to do AND why it works
     * that way, because "why" is what turns a support question into understanding.
     */
    protected function entries(): array
    {
        return [
            [
                'question' => 'How is the meal price calculated?',
                'answer' => "The meal rate is an accounting figure, not an estimate:\n\n"
                    ."(total expenses − total subsidies) ÷ total meals consumed\n\n"
                    .'Subsidies are subtracted BEFORE dividing, because a subsidy reduces what '
                    .'members themselves have to cover. If a month has no meals recorded, the rate '
                    ."is 0 — the platform never substitutes a guess.\n\n"
                    .'You can check this by hand from the Expenses and Meal Entries pages, which is '
                    .'exactly why it is computed this way.',
                'phrasings' => [
                    'how do you work out the cost per meal',
                    'what is the meal rate',
                    'how much does one meal cost',
                    'where does the per meal price come from',
                ],
                'keywords' => ['rate', 'price', 'cost', 'formula', 'calculation'],
            ],
            [
                'question' => 'How do I add a member?',
                'answer' => 'Go to Members and choose Add Member. You will need their name and, '
                    ."optionally, a roll number and department.\n\n"
                    .'Adding a member creates their roster record. To let them sign in, either '
                    ."send them an invitation from the member's row (they set their own password), "
                    .'or share your workspace invite code from Settings → Invite Code so they can '
                    .'register themselves.',
                'phrasings' => [
                    'add a student',
                    'create a new member',
                    'how do i register a member',
                    'add someone to the roster',
                ],
                'keywords' => ['member', 'student', 'roster', 'add', 'create'],
            ],
            [
                'question' => 'Where do I find my institution invite code?',
                'answer' => 'Open Settings → Invite Code. You will see the current code and a '
                    ."button to rotate it.\n\n"
                    .'Share that code with your members: they enter it on the registration page, '
                    .'which places their account in your workspace. Rotating the code invalidates '
                    .'the old one, so anyone with the previous code can no longer sign up.',
                'phrasings' => [
                    'what is my invite code',
                    'how do members join my workspace',
                    'find the signup code',
                ],
                'keywords' => ['invite', 'code', 'signup', 'register', 'join'],
            ],
            [
                'question' => 'How do I record a deposit?',
                'answer' => 'Go to Deposits and choose Record Deposit, then pick the member and '
                    ."enter the amount.\n\n"
                    ."A deposit increases the member's balance. If you make a mistake, use Reverse "
                    ."on the deposit's row — the platform keeps the original entry and posts a "
                    .'counter-entry, so the history stays honest rather than the row vanishing.',
                'phrasings' => [
                    'take a payment',
                    'record money received',
                    'add a deposit for a member',
                ],
                'keywords' => ['deposit', 'payment', 'money', 'receive', 'balance'],
            ],
            [
                'question' => 'How do I reverse a wrong deposit or expense?',
                'answer' => 'Open the entry and choose Reverse. Nothing is deleted — the platform '
                    ."flags the original as reversed and posts a counter-entry.\n\n"
                    .'That is deliberate: a finance record that can be silently erased is a record '
                    .'nobody can audit. Reversed rows are excluded from every total, so your '
                    .'figures stay correct while the history stays complete.',
                'phrasings' => [
                    'undo a deposit',
                    'correct a mistake in the ledger',
                    'cancel an expense',
                ],
                'keywords' => ['reverse', 'undo', 'cancel', 'correct', 'mistake'],
            ],
            [
                'question' => 'What does the AI forecasting page show?',
                'answer' => 'It predicts how many meals your members will eat, and what that will '
                    ."cost, over the next days and months.\n\n"
                    .'The MEAL COUNT is learned from your own history — weekday patterns, season '
                    .'and roster size genuinely vary, so that part is modelled. The PRICE is not '
                    .'predicted at all: it is your ledger rate, (expenses − subsidies) ÷ meals, so '
                    ."it always agrees with your reports page.\n\n"
                    .'Every forecast states its basis. If you have under three months of history, '
                    .'it falls back to country benchmarks and says so, rather than presenting a '
                    .'guess as your data.',
                'phrasings' => [
                    'how does forecasting work',
                    'what is the prediction based on',
                    'why is my forecast using benchmarks',
                ],
                'keywords' => ['forecast', 'prediction', 'ai', 'benchmark', 'projection'],
            ],
            [
                'question' => 'Why is my forecast showing zero?',
                'answer' => 'A forecast shows zero when there is no ledger data to price it with — '
                    ."specifically, no meals recorded for the month being priced.\n\n"
                    .'This is intentional. Rather than invent a plausible-looking number, the '
                    .'platform reports 0 and labels the basis. Record some meal entries and '
                    ."expenses and the figures fill in automatically.\n\n"
                    .'If you have meals recorded and still see zero, check that your meal entries '
                    .'have a date in the current or previous month.',
                'phrasings' => [
                    'forecast is showing 0',
                    'no forecast data',
                    'prediction says zero',
                ],
                'keywords' => ['forecast', 'zero', 'empty', 'no data'],
            ],
            [
                'question' => 'How do I change the language?',
                'answer' => 'Use the globe icon in the top bar (or on the sign-in screen, if you '
                    ."have not signed in yet) and pick your language.\n\n"
                    .'Your choice is saved to your account, so it follows you to any device. '
                    .'On a shared computer it will not leak to the next person who signs in.',
                'phrasings' => [
                    'switch to bengali',
                    'change the interface language',
                    'how do i read this in my own language',
                ],
                'keywords' => ['language', 'bengali', 'english', 'translate', 'locale'],
            ],
            [
                'question' => 'How do I turn off the help hints?',
                'answer' => 'Go to your Profile and use the Help & hints toggle. Turning it off '
                    ."hides every (?) badge across the platform.\n\n"
                    .'The preference is saved to your account, so it applies everywhere you sign '
                    .'in. Turning it back on restores them — useful when you are learning a new '
                    .'part of the system.',
                'phrasings' => [
                    'hide the question marks',
                    'disable tooltips',
                    'turn off help badges',
                ],
                'keywords' => ['hints', 'help', 'tooltip', 'badge', 'disable', 'hide'],
            ],
            [
                'question' => 'Can I see the tour again?',
                'answer' => "Yes. Open your Profile and choose Replay guide.\n\n"
                    .'The guided tour normally appears once, on your first sign-in, and is tailored '
                    .'to your role. Replaying it does not affect anything else — it simply shows '
                    .'the walkthrough again.',
                'phrasings' => [
                    'show the onboarding again',
                    'repeat the intro tour',
                    'i missed the guide',
                ],
                'keywords' => ['tour', 'onboarding', 'guide', 'replay', 'walkthrough'],
            ],
            [
                'question' => 'How do I record meals for my members?',
                'answer' => 'Go to Meal Entries and pick the date, then mark breakfast, lunch and '
                    ."dinner for each member who ate.\n\n"
                    .'Each meal is counted individually — a member eating all three in a day counts '
                    .'as three. The per-meal rate is applied automatically, so you never calculate '
                    ."a member's cost by hand.",
                'phrasings' => [
                    'enter today\'s meals',
                    'log breakfast lunch dinner',
                    'mark who ate',
                ],
                'keywords' => ['meal', 'entry', 'breakfast', 'lunch', 'dinner', 'record'],
            ],
            [
                'question' => 'Why does a member have dues?',
                'answer' => 'A member has dues when their meal cost for the period is greater than '
                    ."what they have deposited.\n\n"
                    ."The dashboard's \"With Dues\" count shows how many members are in that state, "
                    .'and Total Outstanding is the sum owed. Recording a deposit against a member '
                    .'reduces their dues immediately.',
                'phrasings' => [
                    'member owes money',
                    'negative balance',
                    'outstanding amount',
                ],
                'keywords' => ['dues', 'balance', 'outstanding', 'owe', 'negative'],
            ],
            [
                'question' => 'What is a subsidy and how does it affect prices?',
                'answer' => 'A subsidy is money contributed by the institution (or a donor) toward '
                    ."meal costs, so members pay less.\n\n"
                    .'Subsidies reduce the numerator in the meal rate: (expenses − subsidies) ÷ '
                    .'meals. So a 20,000 subsidy on a month with 1,000 meals reduces every '
                    ."member's per-meal cost by 20.\n\n"
                    .'Only ACTIVE subsidies for the period count — a cancelled subsidy must not '
                    .'reduce what members owe.',
                'phrasings' => [
                    'how do subsidies work',
                    'institution paying part of the meal cost',
                    'why did my meal rate go down',
                ],
                'keywords' => ['subsidy', 'subsidies', 'donation', 'funding', 'contribution'],
            ],
            [
                'question' => 'How do I export a report?',
                'answer' => 'Open the relevant page (Members, Deposits, or Reports) and use the '
                    ."Export button. You will get a CSV you can open in Excel or Google Sheets.\n\n"
                    .'Exports respect the filters currently applied, so narrow the view first if '
                    .'you want a specific month or department.',
                'phrasings' => [
                    'download a csv',
                    'get my data out',
                    'print a report',
                ],
                'keywords' => ['export', 'download', 'csv', 'report', 'excel'],
            ],
        ];
    }
}
