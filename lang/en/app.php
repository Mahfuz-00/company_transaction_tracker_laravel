<?php

/**
 * ENGLISH — the reference locale.
 *
 * ORGANISATION
 *   Keys are grouped by SURFACE, not by page component, so a translator works
 *   through one coherent area at a time:
 *
 *     common      - words used everywhere (save, cancel, search…)
 *     nav         - sidebar/navigation labels
 *     auth        - login, register, SSO
 *     language    - the language switcher itself
 *     hints       - the in-body help tooltips
 *     onboarding  - the first-login guided tour
 *     bug         - the Report a Bug module
 *     assistant   - the AI support assistant
 *     validation  - user-facing form errors
 *
 * Every OTHER locale file mirrors this key set. A key missing from a translation
 * falls back to this file (config/locales.php → `fallback`), so a partially
 * translated language degrades to readable English rather than showing a raw key.
 */
return [

    'common' => [
        'save' => 'Save',
        'saving' => 'Saving…',
        'cancel' => 'Cancel',
        'close' => 'Close',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'create' => 'Create',
        'update' => 'Update',
        'search' => 'Search',
        'filter' => 'Filter',
        'reset' => 'Reset',
        'back' => 'Back',
        'next' => 'Next',
        'finish' => 'Finish',
        'loading' => 'Loading…',
        'no_results' => 'Nothing to show',
        'confirm' => 'Are you sure?',
        'yes' => 'Yes',
        'no' => 'No',
        'optional' => 'optional',
        'required' => 'required',
        'all' => 'All',
    ],

    'nav' => [
        'dashboard' => 'Dashboard',
        'analytics' => 'Analytics',
        'members' => 'Members',
        'deposits' => 'Deposits',
        'expenses' => 'Expenses',
        'meals' => 'Meal Entries',
        'subsidies' => 'Subsidies',
        'vendors' => 'Vendors',
        'reports' => 'Reports',
        'forecasting' => 'AI Forecasting',
        'settings' => 'Settings',
        'profile' => 'Profile',
        'notifications' => 'Notifications',
    ],

    'auth' => [
        'login_heading' => 'Welcome back',
        'login_subheading' => 'Sign in to manage your meals, deposits, and expenses.',
        'register_heading' => 'Create your account',
        'email' => 'Email Address',
        'password' => 'Password',
        'confirm_password' => 'Confirm Password',
        'remember_me' => 'Remember me on this device',
        'forgot_password' => 'Forgot password?',
        'sign_in' => 'Sign In to Dashboard',
        'signing_in' => 'Signing in…',
        'create_account' => 'Create Account',
        'or_continue_with' => 'Or continue with',
        'invite_code' => 'Institution Invite Code',
        'invite_code_help' => 'Ask your institution admin for this code — it maps your account to the right workspace.',
        'invite_code_matched' => 'Matched: :institution',
        'sso_locked' => 'Enter a valid institution invite code above to unlock these options.',
        'no_account' => "Don't have an account?",
        'have_account' => 'Already registered?',
        'create_one' => 'Create one',
        'log_in_instead' => 'Log in instead',
    ],

    'language' => [
        'label' => 'Language',
        'choose' => 'Choose your language',
        'description' => 'This changes the language of the interface for your account only.',
        'saved' => 'Language updated.',
    ],

    'hints' => [
        'show' => 'Show help',
        'hide' => 'Hide help',
        'title' => 'Helpful tips',
        'toggle_label' => 'Show help hints',
        'toggle_description' => 'Turn the small (?) help badges on or off. Your choice is saved to your account.',
        'enabled' => 'Help hints turned on.',
        'disabled' => 'Help hints turned off.',
        'settings_title' => 'Help & hints',
    ],

    'onboarding' => [
        'step_of' => 'Step :current of :total',
        'get_started' => 'Get started',
        'skip' => 'Skip tour',
        'replay' => 'Replay the tour',
        'replay_description' => 'Watch the guided introduction again.',
        'welcome' => 'Welcome',
    ],

    'bug' => [
        'trigger' => 'Report a bug',
        'title' => 'Report a bug',
        'subtitle' => 'Tell us what went wrong. Your report goes straight to the platform team.',
        'what_went_wrong' => 'What went wrong?',
        'describe' => 'Describe the problem',
        'describe_placeholder' => 'What did you expect to happen, and what happened instead?',
        'steps' => 'Steps to reproduce',
        'severity' => 'How bad is it?',
        'screenshot' => 'Screenshot',
        'screenshot_help' => 'PNG or JPG, up to 5 MB.',
        'page' => 'Page',
        'send' => 'Send report',
        'sending' => 'Sending…',
        'sent' => 'Thanks — your report was sent to the platform team (ref #:id).',
    ],

    'assistant' => [
        'title' => 'Support Assistant',
        'open' => 'Ask the assistant',
        'placeholder' => 'Ask a question about NomNomytics…',
        'send' => 'Send',
        'thinking' => 'Looking that up…',
        'helpful' => 'Was this helpful?',
        'yes' => 'Yes',
        'no' => 'Not really',
        'escalated' => 'Thanks — we have passed this to the platform team and will get back to you.',
        'from_docs' => 'Answered from the NomNomytics documentation.',
    ],

    'validation' => [
        'required' => 'This field is required.',
        'email' => 'Please enter a valid email address.',
        'min' => 'This is too short.',
        'max' => 'This is too long.',
        'unique' => 'That is already taken.',
    ],

];
