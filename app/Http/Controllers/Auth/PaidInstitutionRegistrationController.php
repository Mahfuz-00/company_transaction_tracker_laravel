<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PaidInstitutionRegistrationController extends Controller
{
    /**
     * Show the paid registration form.
     */
    public function create(Request $request)
    {
        $planSlug = $request->query('plan', 'standard');
        $plan = SubscriptionPlan::where('key', $planSlug)->first() ?? SubscriptionPlan::first();

        return Inertia::render('Auth/PaidInstitutionRegister', [
            'selectedPlan' => $plan,
            'plans' => SubscriptionPlan::where('is_active', true)->get(),
        ]);
    }

    /**
     * Store institution & admin account, with duplicate prevention.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'institution_name' => ['required', 'string', 'max:255'],
            'institution_type' => ['required', 'string'],
            'admin_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'payment_gateway' => ['nullable', 'string', 'in:stripe,sslcommerz,bkash,manual'],
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);

        // DUPLICATE PREVENTION:
        // Check if an existing institution or user with this email or name exists.
        $existingUser = User::where('email', $data['email'])->first();
        if ($existingUser) {
            $institution = $existingUser->institution;
            // If incomplete/awaiting payment, resume that record
            if ($institution && $institution->onboarding_status === 'awaiting_payment') {
                $ref = $institution->signup_reference ?: ('SIGNUP-'.Str::upper(Str::random(10)));
                $institution->update([
                    'signup_reference' => $ref,
                    'payment_gateway' => $data['payment_gateway'] ?? 'stripe',
                ]);

                return redirect()->route('onboarding.payment.gateway', ['reference' => $ref, 'resumed' => 1])
                    ->with('resumed_warning', true);
            }

            return back()->withErrors(['email' => 'An account with this email address already exists. Please log in.']);
        }

        $existingInst = Institution::where('name', $data['institution_name'])->first();
        if ($existingInst && $existingInst->onboarding_status === 'awaiting_payment') {
            $ref = $existingInst->signup_reference ?: ('SIGNUP-'.Str::upper(Str::random(10)));
            $existingInst->update([
                'signup_reference' => $ref,
                'payment_gateway' => $data['payment_gateway'] ?? 'stripe',
            ]);

            return redirect()->route('onboarding.payment.gateway', ['reference' => $ref, 'resumed' => 1])
                ->with('resumed_warning', true);
        }

        $ref = 'SIGNUP-'.Str::upper(Str::random(10));

        $institution = DB::transaction(function () use ($data, $plan, $ref) {
            $inst = Institution::create([
                'name' => $data['institution_name'],
                'slug' => Str::slug($data['institution_name']).'-'.Str::lower(Str::random(5)),
                'invite_code' => Str::upper(Str::random(8)),
                'type' => $data['institution_type'],
                'onboarding_status' => 'awaiting_payment',
                'signup_reference' => $ref,
                'payment_gateway' => $data['payment_gateway'] ?? 'stripe',
                'is_active' => false,
                'subscription_plan' => $plan->name,
                'subscription_amount' => $plan->monthly_price,
            ]);

            $admin = User::create([
                'name' => $data['admin_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'institution_id' => $inst->id,
                'status' => 'active',
            ]);

            $admin->assignRole('Institution Admin');

            AuditLogger::log('created', "self-registered paid institution {$inst->name} (ref: {$ref})", $inst);

            return $inst;
        });

        return redirect()->route('onboarding.payment.gateway', ['reference' => $ref]);
    }

    /**
     * Simulated Payment Gateway landing page.
     */
    public function gateway(Request $request, string $reference)
    {
        $institution = Institution::where('signup_reference', $reference)->firstOrFail();
        $isResumed = $request->boolean('resumed') || (bool) session('resumed_warning');

        return Inertia::render('Auth/PaymentGatewayMock', [
            'institution' => $institution,
            'reference' => $reference,
            'resumedWarning' => $isResumed,
        ]);
    }

    /**
     * Complete payment callback from gateway.
     */
    public function completePayment(Request $request, string $reference)
    {
        $institution = Institution::where('signup_reference', $reference)->firstOrFail();

        $institution->update([
            'onboarding_status' => 'active',
            'is_active' => true,
            'payment_reference' => 'TXN-'.Str::upper(Str::random(12)),
            'subscription_status' => 'active',
            'subscription_started_at' => now(),
            'subscription_ends_at' => now()->addMonth(),
        ]);

        AuditLogger::log('updated', "completed subscription payment for institution {$institution->name}", $institution);

        return redirect()->route('login')->with('status', 'Payment completed successfully! You may now log in to your admin dashboard.');
    }
}
