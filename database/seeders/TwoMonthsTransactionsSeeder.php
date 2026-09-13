<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class TwoMonthsTransactionsSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'user@example.com')->first();
        if (! $user) {
            // if no seeded user, pick first user
            $user = User::first();
        }
        if (! $user) {
            return;
        }

        // create ~60 transactions across the last two months
        $now = Carbon::now();
        $start = (clone $now)->subMonths(2)->startOfMonth();
        $end = (clone $now)->endOfMonth();

        $current = $start->copy();
        $entries = [];
        while ($current->lte($end)) {
            // create 1-2 transactions per day
            $count = rand(1, 2);
            for ($i = 0; $i < $count; $i++) {
                $type = rand(0,1) ? 'in' : 'out';
                $amount = rand(100, 10000) / 100; // 1.00 - 100.00
                $entries[] = [
                    'user_id' => $user->id,
                    'item' => ($type === 'in' ? 'Income' : 'Expense') . ' sample',
                    'type' => $type,
                    'amount' => $amount,
                    'category' => $type === 'in' ? 'salary' : 'misc',
                    'created_at' => $current->format('Y-m-d H:i:s'),
                    'updated_at' => $current->format('Y-m-d H:i:s'),
                ];
            }

            $current->addDay();
        }

        // insert in chunks
        foreach (array_chunk($entries, 100) as $chunk) {
            DB::table('transactions')->insert($chunk);
        }
    }
}
