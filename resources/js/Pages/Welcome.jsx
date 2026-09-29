import React from 'react';
import { Head } from '@inertiajs/react';

import ThemeProvider from '@/Components/ThemeProvider';
import { LandingAnimationStyles } from '@/Components/Landing/LandingPrimitives';
import LandingHeader from '@/Components/Landing/LandingHeader';
import HeroSection from '@/Components/Landing/HeroSection';
import InstitutionTypes from '@/Components/Landing/InstitutionTypes';
import FeatureShowcase from '@/Components/Landing/FeatureShowcase';
import WorkflowAnimation from '@/Components/Landing/WorkflowAnimation';
import PricingGrid from '@/Components/Landing/PricingGrid';
import CTASection from '@/Components/Landing/CTASection';
import LandingFooter from '@/Components/Landing/LandingFooter';
import SupportAssistant from '@/Components/Assistant/SupportAssistant';

/**
 * Public landing page.
 *
 * Deliberately a thin COMPOSITION shell: each section is its own module under
 * `Components/Landing`, so this file only declares the page order and hands the
 * server-provided data to the two sections that need it (hero stat + pricing).
 *
 * The shared animation styles are mounted once here, at the page root, so every
 * section's `wa-rise` / `wa-fade` / `wa-float` entrance animations fire
 * correctly after the split (a single keyframe source is what keeps them in
 * sync - scattering them per section caused the animation-trigger breakage).
 *
 * Wrapped in ThemeProvider so the public page receives the LIVE theme context
 * (not only the global CSS variables) - the same reason GuestLayout is wrapped.
 * A visitor who saved a theme on this browser therefore sees the landing page
 * painted to match, instantly.
 */
export default function Welcome({ plans = [], institutionCount = 0 }) {
    return (
        <ThemeProvider>
            <Head title="Multi-institution meal & expense management" />
            <LandingAnimationStyles />

            <div className="min-h-screen bg-white text-slate-900 antialiased selection:bg-indigo-100 selection:text-indigo-900">
                <LandingHeader />

                <main>
                    <HeroSection institutionCount={institutionCount} />
                    <InstitutionTypes />
                    <FeatureShowcase />
                    <WorkflowAnimation />
                    <PricingGrid plans={plans} />
                    <CTASection />
                </main>

                <LandingFooter />

                {/*
                  * THE SUPPORT ASSISTANT — PUBLIC SURFACE.
                  *
                  * Mounted here, on the page a visitor reaches BEFORE they have an
                  * account, because the moment someone wants to know "what does this
                  * cost?" or "does it handle subsidies?" is the moment they are
                  * reading this page. Requiring a sign-up to ask would lose the
                  * question - and probably the visitor.
                  *
                  * `surface="landing"` scopes the conversation: a guest thread is
                  * keyed by a random token the client holds, never a sequential id.
                  */}
                <SupportAssistant surface="landing" />
            </div>
        </ThemeProvider>
    );
}
