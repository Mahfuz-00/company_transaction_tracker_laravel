<?php

/**
 * BENGALI (বাংলা) — the first non-English locale.
 *
 * This file mirrors `lang/en/app.php` key for key. Any key omitted here falls back
 * to English, so a partial translation is always readable rather than showing a
 * raw key like `auth.invite_code`.
 *
 * TRANSLATION NOTES (for whoever adds the next language)
 * ------------------------------------------------------
 *   - "Mess" has no single English equivalent. `mess` is rendered as মেস, which is
 *     the ordinary word in Bangladesh for a shared dining arrangement.
 *   - Meal names are the everyday terms: সকালের নাশতা (breakfast),
 *     দুপুরের খাবার (lunch), রাতের খাবার (dinner).
 *   - Money is not translated — amounts come from the institution's currency
 *     settings, and the numeral system is handled by the formatter, not here.
 */
return [

    'common' => [
        'save' => 'সংরক্ষণ করুন',
        'saving' => 'সংরক্ষণ হচ্ছে…',
        'cancel' => 'বাতিল',
        'close' => 'বন্ধ করুন',
        'delete' => 'মুছুন',
        'edit' => 'সম্পাদনা',
        'create' => 'তৈরি করুন',
        'update' => 'হালনাগাদ করুন',
        'search' => 'খুঁজুন',
        'filter' => 'ফিল্টার',
        'reset' => 'রিসেট',
        'back' => 'পেছনে',
        'next' => 'পরবর্তী',
        'finish' => 'শেষ করুন',
        'loading' => 'লোড হচ্ছে…',
        'no_results' => 'কিছু নেই',
        'confirm' => 'আপনি কি নিশ্চিত?',
        'yes' => 'হ্যাঁ',
        'no' => 'না',
        'optional' => 'ঐচ্ছিক',
        'required' => 'আবশ্যক',
        'all' => 'সব',
    ],

    'nav' => [
        'dashboard' => 'ড্যাশবোর্ড',
        'analytics' => 'বিশ্লেষণ',
        'members' => 'সদস্যবৃন্দ',
        'deposits' => 'জমা',
        'expenses' => 'খরচ',
        'meals' => 'খাবারের হিসাব',
        'subsidies' => 'ভর্তুকি',
        'vendors' => 'সরবরাহকারী',
        'reports' => 'রিপোর্ট',
        'forecasting' => 'এআই পূর্বাভাস',
        'settings' => 'সেটিংস',
        'profile' => 'প্রোফাইল',
        'notifications' => 'বিজ্ঞপ্তি',
    ],

    'auth' => [
        'login_heading' => 'আবার স্বাগতম',
        'login_subheading' => 'আপনার খাবার, জমা ও খরচ পরিচালনা করতে সাইন ইন করুন।',
        'register_heading' => 'আপনার অ্যাকাউন্ট তৈরি করুন',
        'email' => 'ইমেইল ঠিকানা',
        'password' => 'পাসওয়ার্ড',
        'confirm_password' => 'পাসওয়ার্ড নিশ্চিত করুন',
        'remember_me' => 'এই ডিভাইসে মনে রাখুন',
        'forgot_password' => 'পাসওয়ার্ড ভুলে গেছেন?',
        'sign_in' => 'ড্যাশবোর্ডে সাইন ইন করুন',
        'signing_in' => 'সাইন ইন হচ্ছে…',
        'create_account' => 'অ্যাকাউন্ট তৈরি করুন',
        'or_continue_with' => 'অথবা চালিয়ে যান',
        'invite_code' => 'প্রতিষ্ঠানের ইনভাইট কোড',
        'invite_code_help' => 'আপনার প্রতিষ্ঠানের অ্যাডমিনের কাছ থেকে এই কোডটি নিন — এটি আপনার অ্যাকাউন্ট সঠিক ওয়ার্কস্পেসে যুক্ত করে।',
        'invite_code_matched' => 'মিলে গেছে: :institution',
        'sso_locked' => 'এই বিকল্পগুলি চালু করতে উপরে একটি সঠিক প্রতিষ্ঠান ইনভাইট কোড লিখুন।',
        'no_account' => 'অ্যাকাউন্ট নেই?',
        'have_account' => 'আগেই নিবন্ধিত?',
        'create_one' => 'একটি তৈরি করুন',
        'log_in_instead' => 'বরং লগ ইন করুন',
    ],

    'language' => [
        'label' => 'ভাষা',
        'choose' => 'আপনার ভাষা বেছে নিন',
        'description' => 'এটি শুধুমাত্র আপনার অ্যাকাউন্টের ইন্টারফেসের ভাষা পরিবর্তন করবে।',
        'saved' => 'ভাষা হালনাগাদ হয়েছে।',
    ],

    'hints' => [
        'show' => 'সহায়তা দেখান',
        'hide' => 'সহায়তা লুকান',
        'title' => 'সহায়ক টিপস',
        'toggle_label' => 'সহায়তা টিপস দেখান',
        'toggle_description' => 'ছোট (?) সহায়তা চিহ্নগুলি চালু বা বন্ধ করুন। আপনার পছন্দ আপনার অ্যাকাউন্টে সংরক্ষিত হবে।',
        'enabled' => 'সহায়তা টিপস চালু হয়েছে।',
        'disabled' => 'সহায়তা টিপস বন্ধ হয়েছে।',
        'settings_title' => 'সহায়তা ও টিপস',
    ],

    'onboarding' => [
        'step_of' => 'ধাপ :current / :total',
        'get_started' => 'শুরু করুন',
        'skip' => 'ট্যুর বাদ দিন',
        'replay' => 'ট্যুর আবার দেখুন',
        'replay_description' => 'পরিচিতিমূলক ট্যুরটি আবার দেখুন।',
        'welcome' => 'স্বাগতম',
    ],

    'bug' => [
        'trigger' => 'সমস্যা জানান',
        'title' => 'সমস্যা জানান',
        'subtitle' => 'কী ভুল হয়েছে বলুন। আপনার রিপোর্ট সরাসরি প্ল্যাটফর্ম টিমের কাছে যাবে।',
        'what_went_wrong' => 'কী ভুল হয়েছে?',
        'describe' => 'সমস্যাটি বর্ণনা করুন',
        'describe_placeholder' => 'আপনি কী আশা করেছিলেন, এবং বাস্তবে কী হয়েছে?',
        'steps' => 'পুনরুত্পাদনের ধাপ',
        'severity' => 'কতটা গুরুতর?',
        'screenshot' => 'স্ক্রিনশট',
        'screenshot_help' => 'PNG বা JPG, সর্বোচ্চ ৫ মেগাবাইট।',
        'page' => 'পৃষ্ঠা',
        'send' => 'রিপোর্ট পাঠান',
        'sending' => 'পাঠানো হচ্ছে…',
        'sent' => 'ধন্যবাদ — আপনার রিপোর্ট প্ল্যাটফর্ম টিমের কাছে পাঠানো হয়েছে (রেফ #:id)।',
    ],

    'assistant' => [
        'title' => 'সহায়তা সহকারী',
        'open' => 'সহকারীকে জিজ্ঞাসা করুন',
        'placeholder' => 'প্ল্যাটফর্ম সম্পর্কে একটি প্রশ্ন করুন…',
        'send' => 'পাঠান',
        'thinking' => 'খোঁজা হচ্ছে…',
        'helpful' => 'এটি কি সহায়ক ছিল?',
        'yes' => 'হ্যাঁ',
        'no' => 'বেশ নয়',
        'escalated' => 'প্ল্যাটফর্ম টিমের কাছে পাঠানো হয়েছে। তারা উত্তর দিলে আমি পরের বার এটি জানব।',
        'from_docs' => 'প্ল্যাটফর্মের ডকুমেন্টেশন থেকে উত্তর দেওয়া হয়েছে।',
        'uncertain' => 'আমি সম্পূর্ণ নিশ্চিত নই যে এটিই আপনার প্রয়োজনীয় উত্তর।',
        'empty_state' => 'খাবার, জমা, রিপোর্ট বা কোনো হিসাব কীভাবে করা হয় — যা কিছু জানতে চান জিজ্ঞাসা করুন।',
        'queue_title' => 'সহায়তা সহকারীর সারি',
        'queue_subtitle' => 'একটি প্রশ্নের উত্তর দিলে সহকারী পরবর্তীতে নিজে থেকেই তা সামলাতে শিখে যায়।',
        'answer_and_teach' => 'উত্তর দিন ও সহকারীকে শেখান',
        'dismiss' => 'বাদ দিন',
        'pending' => 'অপেক্ষমাণ',
        'flagged' => 'ভুল উত্তর রিপোর্ট',
        'answered' => 'উত্তর দেওয়া হয়েছে',
        'learned_answers' => 'শেখা উত্তর',
        'needs_rewrite' => 'যে উত্তরগুলি নতুন করে লেখা দরকার',
        'reason_unanswered' => 'উত্তর দিতে পারেনি',
        'reason_flagged' => 'উত্তরটি ভুল ছিল',
    ],

    'validation' => [
        'required' => 'এই ঘরটি পূরণ করা আবশ্যক।',
        'email' => 'একটি সঠিক ইমেইল ঠিকানা লিখুন।',
        'min' => 'এটি খুব ছোট।',
        'max' => 'এটি খুব বড়।',
        'unique' => 'এটি ইতিমধ্যে ব্যবহৃত।',
    ],

];
