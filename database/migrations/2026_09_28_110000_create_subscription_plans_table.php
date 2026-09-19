<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subscription plan catalogue (Software Super Admin pricing tiers).
     *
     * The SSA defines the tiers the SaaS is sold in - Free Trial, Standard,
     * Enterprise, etc. - with a monthly price and a set of feature flags and
     * limits. Institutions are then ASSIGNED a plan, which drives their
     * `subscription_plan` label and `subscription_amount`.
     *
     * Plans are PLATFORM-level records (not tenant-scoped), so the model does
     * NOT use the BelongsToInstitution trait.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();

            // Stable machine key (e.g. 'free_trial', 'standard', 'enterprise').
            $table->string('key', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Pricing. `is_free` short-circuits the amount for a trial/free tier.
            $table->decimal('monthly_price', 14, 2)->default(0);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_trial_default')->default(false);

            // Plan limits: -1 means unlimited (stored explicitly so a report can
            // distinguish "no cap set" from "unlimited").
            $table->integer('member_limit')->default(-1);
            $table->integer('manager_limit')->default(-1);

            // Feature flags the UI/entitlements can read.
            $table->json('features')->nullable();

            // Display + lifecycle.
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            // Marks the public default shown on the landing pricing section.
            $table->boolean('is_public')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Seed the sensible defaults so the module is useful immediately.
        $now = now();
        DB::table('subscription_plans')->insert([
            [
                'key' => 'free_trial',
                'name' => 'Free Trial',
                'description' => '7-day full access. No card required.',
                'monthly_price' => 0,
                'is_free' => true,
                'is_trial_default' => true,
                'member_limit' => 25,
                'manager_limit' => 3,
                'features' => json_encode(['All modules', '7-day access', 'Email support']),
                'sort_order' => 1,
                'is_active' => true,
                'is_public' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'standard',
                'name' => 'Standard',
                'description' => 'For a single dorm, mess or office canteen.',
                'monthly_price' => 2900,
                'is_free' => false,
                'is_trial_default' => false,
                'member_limit' => 500,
                'manager_limit' => 15,
                'features' => json_encode(['All modules', 'Vendor ledger', 'Reports & exports', 'Standard support']),
                'sort_order' => 2,
                'is_active' => true,
                'is_public' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Multi-campus groups with unlimited scale.',
                'monthly_price' => 9900,
                'is_free' => false,
                'is_trial_default' => false,
                'member_limit' => -1,
                'manager_limit' => -1,
                'features' => json_encode(['All modules', 'Unlimited members', 'Priority support', 'Custom terminology', 'Full analytics']),
                'sort_order' => 3,
                'is_active' => true,
                'is_public' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
