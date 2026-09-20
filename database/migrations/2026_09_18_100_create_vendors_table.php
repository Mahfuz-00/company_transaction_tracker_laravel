<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors / suppliers the institution buys from.
 *
 * Previously a payee was free text on the transaction, which made "Rahim Store"
 * and "rahim store " two different suppliers. Vendors are now rows that
 * expenses point at, so spend can be grouped per supplier.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('institution_id')->nullable()->index();

            $table->string('name');
            $table->string('slug')->unique();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();

            // groceries | vegetables | meat | grains | gas | supplies | utilities | other
            $table->string('category')->nullable();

            // Opening balance owed to the vendor (credit purchases).
            $table->decimal('opening_balance', 14, 2)->default(0);

            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('meal_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('meal_expenses', 'vendor_id')) {
                $table->foreignId('vendor_id')
                    ->nullable()
                    ->after('transaction_id')
                    ->constrained('vendors')
                    ->nullOnDelete();
            }

            // Cash paid at the time vs bought on credit.
            if (! Schema::hasColumn('meal_expenses', 'payment_status')) {
                $table->string('payment_status')->default('paid')->after('vendor_id');
            }

            if (! Schema::hasColumn('meal_expenses', 'amount')) {
                $table->decimal('amount', 14, 2)->nullable()->after('payment_status');
            }
        });

        // Keep a denormalised link on the ledger row too, so reports can group
        // spending by vendor without joining through meal_expenses.
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'vendor_id')) {
                $table->foreignId('vendor_id')
                    ->nullable()
                    ->after('student_id')
                    ->constrained('vendors')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'vendor_id')) {
                $table->dropConstrainedForeignId('vendor_id');
            }
        });

        Schema::table('meal_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('meal_expenses', 'vendor_id')) {
                $table->dropConstrainedForeignId('vendor_id');
            }
            foreach (['payment_status', 'amount'] as $column) {
                if (Schema::hasColumn('meal_expenses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('vendors');
    }
};
