#!/usr/bin/env node
/**
 * check-nav-icons.mjs — the sidebar icon-consistency gate.
 *
 * WHY THIS EXISTS
 *   The sidebar is rendered from `resources/js/Utils/navItems.js`, which names an
 *   icon per module, and `resources/js/Components/Icon.jsx`, which defines the
 *   glyphs. Nothing at runtime connects the two: an unknown icon key renders an
 *   EMPTY span (Icon.jsx fails quietly in production), and two modules naming the
 *   same key render IDENTICAL rows. Both are silent failures - the sidebar just
 *   looks wrong, and no test catches it.
 *
 *   This script makes both failures loud. It is deliberately dependency-free and
 *   parse-free (regex over the two source files), so it runs anywhere Node does
 *   and cannot drift from the real config.
 *
 * RULES ENFORCED
 *   1. Every `icon:` key in navItems.js must be defined in Icon.jsx.   -> ERROR
 *   2. Two DIFFERENT routes must not share one icon.                   -> ERROR
 *      (The same route may legitimately appear in two nav sections - e.g. the
 *      global and workspace Audit Log entries - so uniqueness is keyed on route,
 *      not on the icon alone.)
 *   3. An icon defined in Icon.jsx but used by no module.              -> WARN
 *
 * USAGE
 *   node scripts/check-nav-icons.mjs        # exit 1 on any ERROR
 *   npm run lint:nav-icons
 */

import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const NAV_FILE = join(ROOT, 'resources/js/Utils/navItems.js');
const ICON_FILE = join(ROOT, 'resources/js/Components/Icon.jsx');

/**
 * Walk a nav-item object literal, pairing its `icon` with the nearest `route`
 * (or the item's `label` when it is a group header with no route).
 *
 * navItems.js is hand-written and stable, so a small state machine over the
 * lines is more robust here than pulling in a JS parser: it reads each item
 * block, remembers the last `label`, and records the icon with whichever of
 * route/label identifies the item.
 */
function extractNavItems(source) {
    const items = [];
    let label = null;
    let route = null;
    let line = 1;

    for (const raw of source.split('\n')) {
        const labelMatch = raw.match(/label:\s*'([^']+)'/);
        if (labelMatch) {
            // A new item begins: flush nothing, just reset the identity fields.
            label = labelMatch[1];
            route = null;
        }

        const routeMatch = raw.match(/route:\s*'([^']+)'/);
        if (routeMatch) route = routeMatch[1];

        const iconMatch = raw.match(/icon:\s*'([^']+)'/);
        if (iconMatch) {
            items.push({
                icon: iconMatch[1],
                // Groups (Settings / Platform Settings) have an icon but no
                // route; their label is the identity.
                id: route ?? label ?? `line ${line}`,
                label: label ?? `line ${line}`,
                line,
            });
            // Reset so a following `icon:` cannot inherit this item's identity.
            label = null;
            route = null;
        }

        line += 1;
    }

    return items;
}

/** The icon keys defined in the ICONS map of Icon.jsx. */
function extractDefinedIcons(source) {
    const defined = new Set();

    for (const raw of source.split('\n')) {
        const match = raw.match(/^\s{4}(\w+):\s*\{/);
        if (match) defined.add(match[1]);
    }

    return defined;
}

const navItems = extractNavItems(readFileSync(NAV_FILE, 'utf8'));
const defined = extractDefinedIcons(readFileSync(ICON_FILE, 'utf8'));

const errors = [];
const warnings = [];

// ---- Rule 1: every referenced icon exists ---------------------------------
const missing = navItems.filter((item) => !defined.has(item.icon));

for (const item of missing) {
    errors.push(
        `navItems.js:${item.line} "${item.label}" uses icon '${item.icon}', ` +
        `which is not defined in Icon.jsx`
    );
}

// ---- Rule 2: two different routes must not share an icon -------------------
const byIcon = new Map();

for (const item of navItems) {
    if (!byIcon.has(item.icon)) byIcon.set(item.icon, []);
    byIcon.get(item.icon).push(item);
}

for (const [icon, items] of byIcon) {
    // Dedupe by identity: the SAME route appearing twice is intentional.
    const distinct = [...new Set(items.map((item) => item.id))];

    if (distinct.length > 1) {
        const detail = items.map((item) => `"${item.label}" (line ${item.line})`).join(', ');
        errors.push(
            `Icon '${icon}' is shared by ${distinct.length} different modules: ${detail}`
        );
    }
}

// ---- Rule 3: unused icons (informational) ---------------------------------
const used = new Set(navItems.map((item) => item.icon));

for (const icon of [...defined].sort()) {
    if (!used.has(icon)) warnings.push(`Icon '${icon}' is defined but no nav item uses it`);
}

/* ------------------------------------------------------------------ *
 * Report
 * ------------------------------------------------------------------ */

console.log(`Checked ${navItems.length} nav icons against ${defined.size} defined glyphs.\n`);

for (const warning of warnings) console.log(`  warn  ${warning}`);
if (warnings.length) console.log('');

for (const error of errors) console.error(`  ERROR ${error}`);

if (errors.length) {
    console.error(
        `\n${errors.length} icon problem(s). Every module must own a distinct, ` +
        `defined icon - see the ICON-UNIQUENESS RULE in Components/Icon.jsx.`
    );
    process.exit(1);
}

console.log(`OK - every nav module has its own defined icon.`);