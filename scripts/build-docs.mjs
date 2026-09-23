#!/usr/bin/env node
/**
 * build-docs.mjs — convert the markdown docs in docs/ into self-contained HTML.
 *
 * WHY A HAND-ROLLED CONVERTER (no dependency):
 *   The output must be browsable OFFLINE by opening the file directly (file://).
 *   A CDN stylesheet would need the network, and every markdown library would
 *   add a dependency to package.json. This script therefore ships a tiny,
 *   dependency-free markdown subset renderer plus an inline stylesheet, so the
 *   HTML has ZERO external requests.
 *
 * SUPPORTED MARKDOWN (everything the docs actually use):
 *   headings (with GitHub-style ids so the in-page TOCs resolve), fenced code
 *   blocks, tables, unordered/ordered lists, blockquotes, horizontal rules, and
 *   inline code / bold / italic / links.
 *
 * DELIBERATE LIMITATIONS:
 *   - Nested lists are FLATTENED to one level (readable, and cannot emit invalid
 *     HTML). The docs' nested bullets lose their extra indent only.
 *   - No raw-HTML passthrough, no images (the docs contain none).
 *
 * USAGE:  node scripts/build-docs.mjs      (also: npm run docs:build)
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join, dirname, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const DOCS = join(ROOT, 'docs');

/** source markdown -> output html + nav label + one-line description. */
const PAGES = [
    { src: 'USER_MANUAL.md', out: 'user-manual.html', label: 'User Manual', blurb: 'Role-by-role guide for Super Admins, Institution Admins, Meal Managers and Members.' },
    { src: 'SOFTWARE_ARCHITECTURE.md', out: 'architecture.html', label: 'Architecture', blurb: 'Stack, multi-tenancy, domain model + schema, theme engine, API layer and testing.' },
    { src: 'API.md', out: 'api.html', label: 'API Reference', blurb: 'Mobile JSON API: auth, conventions, rate limits, every endpoint and the web-route appendix.' },
    { src: 'DUSK_TESTING.md', out: 'dusk-testing.html', label: 'Dusk Testing', blurb: 'The role/module browser-test hierarchy, bootstrap, and the debugging playbook.' },
    { src: 'DYNAMISM_ROADMAP.md', out: 'dynamism-roadmap.html', label: 'Dynamism Roadmap', blurb: 'Where the platform is dynamic today, and the hooks for form-builder fields and dashboard widgets.' },
];

/** Any `.md` link inside a doc is retargeted at the generated HTML. */
const DOC_LINK_MAP = {
    'api.md': 'api.html',
    'user_manual.md': 'user-manual.html',
    'user-manual.md': 'user-manual.html',
    'software_architecture.md': 'architecture.html',
    'software.md': 'architecture.html',
    'dusk_testing.md': 'dusk-testing.html',
};

/* ------------------------------------------------------------------ *
 * Inline + block rendering
 * ------------------------------------------------------------------ */

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** GitHub's heading-slug algorithm, so authored `#anchor` TOC links resolve. */
function slugify(text) {
    return String(text)
        .toLowerCase()
        .trim()
        .replace(/[\u2000-\u206F\u2E00-\u2E7F\\'!"#$%&()*+,./:;<=>?@[\]^`{|}~]/g, '')
        .replace(/\s/g, '-');
}

function rewriteHref(href) {
    if (!href) return href;
    if (/^(https?:)?\/\//i.test(href) || href.startsWith('#') || href.startsWith('mailto:')) {
        return href;
    }

    const mapped = DOC_LINK_MAP[basename(href).toLowerCase()];
    return mapped ?? href;
}

/** Inline markdown: code, links, bold, italic. */
function inline(text) {
    let out = escapeHtml(text);

    // Protect inline code first so its contents are not re-parsed.
    const codes = [];
    out = out.replace(/`([^`]+)`/g, (_, code) => {
        codes.push(code);
        return `\u0000${codes.length - 1}\u0000`;
    });

    out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, href) => {
        const external = /^(https?:)?\/\//i.test(href) ? ' target="_blank" rel="noopener"' : '';
        return `<a href="${rewriteHref(href)}"${external}>${label}</a>`;
    });

    out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    out = out.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');

    out = out.replace(/\u0000(\d+)\u0000/g, (_, index) => `<code>${codes[Number(index)]}</code>`);

    return out;
}

function splitRow(line) {
    return line
        .trim()
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .split('|')
        .map((cell) => cell.trim());
}

function renderTable(header, body) {
    const head = header.map((cell) => `<th>${inline(cell)}</th>`).join('');
    const rows = body
        .map((row) => `<tr>${row.map((cell) => `<td>${inline(cell)}</td>`).join('')}</tr>`)
        .join('\n');

    return `<div class="table-wrap">\n<table>\n<thead><tr>${head}</tr></thead>\n<tbody>\n${rows}\n</tbody>\n</table>\n</div>`;
}

const isFence = (line) => /^```/.test(line);
const isHeading = (line) => /^#{1,6}\s/.test(line);
const isHr = (line) => /^\s*(-{3,}|\*{3,}|_{3,})\s*$/.test(line);
const isQuote = (line) => /^\s*>/.test(line);
const isList = (line) => /^\s*([-*+]|\d+\.)\s+/.test(line);
const isTableRow = (line) => /^\s*\|/.test(line);
const isBlank = (line) => /^\s*$/.test(line);

/** Render a block of markdown to HTML. */
function render(markdown) {
    const lines = String(markdown).replace(/\r\n/g, '\n').split('\n');
    const output = [];
    const slugCounts = new Map();
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        // ---- fenced code block ------------------------------------------
        if (isFence(line)) {
            const lang = (line.match(/^```(\w+)?/) || [])[1] || '';
            const buffer = [];
            i += 1;
            while (i < lines.length && !isFence(lines[i])) {
                buffer.push(lines[i]);
                i += 1;
            }
            i += 1; // closing fence
            const cls = lang ? ` class="language-${lang}"` : '';
            output.push(`<pre class="code"><code${cls}>${escapeHtml(buffer.join('\n'))}</code></pre>`);
            continue;
        }

        // ---- table ------------------------------------------------------
        if (isTableRow(line) && i + 1 < lines.length && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1])) {
            const header = splitRow(line);
            i += 2;
            const body = [];
            while (i < lines.length && isTableRow(lines[i])) {
                body.push(splitRow(lines[i]));
                i += 1;
            }
            output.push(renderTable(header, body));
            continue;
        }

        // ---- heading ----------------------------------------------------
        const heading = line.match(/^(#{1,6})\s+(.*)$/);
        if (heading) {
            const level = heading[1].length;
            const text = heading[2].trim();
            const base = slugify(text);
            const seen = slugCounts.get(base) ?? 0;
            slugCounts.set(base, seen + 1);
            const id = seen === 0 ? base : `${base}-${seen}`;
            output.push(`<h${level} id="${id}">${inline(text)}</h${level}>`);
            i += 1;
            continue;
        }

        // ---- horizontal rule --------------------------------------------
        if (isHr(line)) {
            output.push('<hr>');
            i += 1;
            continue;
        }

        // ---- blockquote -------------------------------------------------
        if (isQuote(line)) {
            const buffer = [];
            while (i < lines.length && isQuote(lines[i])) {
                buffer.push(lines[i].replace(/^\s*>\s?/, ''));
                i += 1;
            }
            output.push(`<blockquote>${render(buffer.join('\n'))}</blockquote>`);
            continue;
        }

        // ---- list (flattened to one level - see file header) ------------
        if (isList(line)) {
            const ordered = /^\s*\d+\.\s+/.test(line);
            const items = [];
            while (i < lines.length && isList(lines[i])) {
                items.push(lines[i].replace(/^\s*([-*+]|\d+\.)\s+/, ''));
                i += 1;
            }
            const tag = ordered ? 'ol' : 'ul';
            output.push(`<${tag}>${items.map((item) => `<li>${inline(item)}</li>`).join('')}</${tag}>`);
            continue;
        }

        // ---- blank ------------------------------------------------------
        if (isBlank(line)) {
            i += 1;
            continue;
        }

        // ---- paragraph --------------------------------------------------
        const buffer = [];
        while (
            i < lines.length &&
            !isBlank(lines[i]) &&
            !isFence(lines[i]) &&
            !isHeading(lines[i]) &&
            !isHr(lines[i]) &&
            !isQuote(lines[i]) &&
            !isList(lines[i]) &&
            !isTableRow(lines[i])
        ) {
            buffer.push(lines[i]);
            i += 1;
        }
        output.push(`<p>${inline(buffer.join(' '))}</p>`);
    }

    return output.join('\n');
}

/* ------------------------------------------------------------------ *
 * Page shell (inline CSS - no external requests)
 * ------------------------------------------------------------------ */

const STYLES = `
:root { --bg:#f8fafc; --surface:#ffffff; --border:#e2e8f0; --text:#0f172a; --muted:#64748b; --accent:#4f46e5; --code-bg:#f1f5f9; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0b1120; --surface:#111827; --border:#1f2937; --text:#f1f5f9; --muted:#94a3b8; --accent:#818cf8; --code-bg:#1e293b; }
}
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--text);
  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  line-height:1.65; -webkit-text-size-adjust:100%; }
.wrap { max-width: 60rem; margin: 0 auto; padding: 0 1.25rem 4rem; }
nav.topbar { position:sticky; top:0; z-index:10; background:color-mix(in srgb, var(--surface) 92%, transparent);
  backdrop-filter: blur(8px); border-bottom:1px solid var(--border); }
nav.topbar .inner { max-width:60rem; margin:0 auto; padding:.7rem 1.25rem; display:flex; flex-wrap:wrap; gap:.35rem .9rem; align-items:center; }
nav.topbar a { color:var(--muted); text-decoration:none; font-size:.86rem; font-weight:600; padding:.25rem .5rem; border-radius:.5rem; }
nav.topbar a:hover { color:var(--accent); background:var(--code-bg); }
nav.topbar a.active { color:var(--accent); background:var(--code-bg); }
nav.topbar .brand { font-weight:800; color:var(--text); margin-right:auto; font-size:.9rem; }
header.page { padding:2.5rem 0 1rem; border-bottom:1px solid var(--border); margin-bottom:2rem; }
header.page h1 { margin:0; font-size:2rem; letter-spacing:-.02em; }
header.page p { margin:.5rem 0 0; color:var(--muted); font-size:.9rem; }
main h1, main h2, main h3, main h4, main h5, main h6 { line-height:1.3; margin:2rem 0 .75rem; scroll-margin-top:4.5rem; }
main h1 { font-size:1.75rem; }
main h2 { font-size:1.35rem; padding-bottom:.3rem; border-bottom:1px solid var(--border); }
main h3 { font-size:1.1rem; }
main h4 { font-size:1rem; }
main p, main li { font-size:.95rem; }
main a { color:var(--accent); text-decoration:none; }
main a:hover { text-decoration:underline; }
main code { background:var(--code-bg); padding:.12em .35em; border-radius:.3rem; font-size:.85em;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
main pre.code { background:var(--code-bg); border:1px solid var(--border); border-radius:.6rem;
  padding:1rem; overflow-x:auto; }
main pre.code code { background:none; padding:0; font-size:.83rem; line-height:1.55; white-space:pre; }
.table-wrap { overflow-x:auto; margin:1rem 0; border:1px solid var(--border); border-radius:.6rem; }
main table { border-collapse:collapse; width:100%; font-size:.87rem; }
main th, main td { text-align:left; padding:.55rem .75rem; border-bottom:1px solid var(--border); vertical-align:top; }
main thead th { background:var(--code-bg); font-weight:700; white-space:nowrap; }
main tbody tr:last-child td { border-bottom:none; }
main blockquote { margin:1rem 0; padding:.4rem 1rem; border-left:3px solid var(--accent); background:var(--code-bg); border-radius:0 .5rem .5rem 0; }
main blockquote p { margin:.4rem 0; }
main ul, main ol { padding-left:1.4rem; }
main li { margin:.25rem 0; }
main hr { border:none; border-top:1px solid var(--border); margin:2rem 0; }
footer.foot { border-top:1px solid var(--border); margin-top:3rem; padding-top:1rem; color:var(--muted); font-size:.8rem; }
.cards { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(15rem,1fr)); margin:1.5rem 0; }
.card { display:block; border:1px solid var(--border); background:var(--surface); border-radius:.75rem; padding:1.1rem;
  text-decoration:none; color:inherit; transition:border-color .15s ease, transform .15s ease; }
.card:hover { border-color:var(--accent); transform:translateY(-2px); }
.card h3 { margin:0 0 .35rem; font-size:1rem; color:var(--text); }
.card p { margin:0; color:var(--muted); font-size:.85rem; }
`.trim();

function navbar(activeOut) {
    const links = [
        `<a class="brand" href="index.html">Transaction Tracker · Docs</a>`,
        ...PAGES.map(
            (page) =>
                `<a href="${page.out}"${page.out === activeOut ? ' class="active"' : ''}>${page.label}</a>`
        ),
    ];
    return `<nav class="topbar"><div class="inner">${links.join('')}</div></nav>`;
}

function shell({ out, title, blurb, body }) {
    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(title)} — Transaction Tracker</title>
<meta name="generator" content="scripts/build-docs.mjs (do not edit this file by hand)">
<style>\n${STYLES}\n</style>
</head>
<body>
${navbar(out)}
<div class="wrap">
<header class="page">
  <h1>${escapeHtml(title)}</h1>
  ${blurb ? `<p>${escapeHtml(blurb)}</p>` : ''}
</header>
<main>
${body}
</main>
<footer class="foot">
  Generated from <code>docs/${escapeHtml(out.replace(/\.html$/, '.md'))}</code> by
  <code>node scripts/build-docs.mjs</code>. Edit the markdown, not this file.
</footer>
</div>
</body>
</html>
`;
}

/* ------------------------------------------------------------------ *
 * Build
 * ------------------------------------------------------------------ */

const built = [];

for (const page of PAGES) {
    const sourcePath = join(DOCS, page.src);

    if (!existsSync(sourcePath)) {
        console.warn(`! skip ${page.src} (not found)`);
        continue;
    }

    const markdown = readFileSync(sourcePath, 'utf8');
    const html = shell({
        out: page.out,
        title: page.label,
        blurb: page.blurb,
        body: render(markdown),
    });

    writeFileSync(join(DOCS, page.out), html, 'utf8');
    built.push({ ...page, bytes: Buffer.byteLength(html) });
}

// The hub.
const indexBody = `
<div class="cards">
${PAGES.map(
    (page) => `  <a class="card" href="${page.out}">
    <h3>${page.label}</h3>
    <p>${page.blurb}</p>
  </a>`
).join('\n')}
</div>
<h2 id="about">About</h2>
<p>Transaction Tracker is a multi-institution SaaS for tracking shared meals,
deposits, expenses and subsidies across isolated workspaces.</p>
<h2 id="markdown-sources">Markdown sources</h2>
<p>These pages are generated from the markdown files in <code>docs/</code>, which remain
the source of truth:</p>
<ul>
${PAGES.map((page) => `  <li><code>docs/${page.src}</code></li>`).join('\n')}
  <li><code>README.md</code> (repo root)</li>
</ul>
`;

writeFileSync(
    join(DOCS, 'index.html'),
    shell({
        out: 'index.html',
        title: 'Documentation',
        blurb: 'Human-readable docs for the Transaction Tracker platform.',
        body: indexBody.trim(),
    }),
    'utf8'
);

console.log(`Built ${built.length + 1} HTML file(s) in docs/:`);
for (const page of built) {
    console.log(`  ${page.out.padEnd(22)} ${(page.bytes / 1024).toFixed(1)} KB  <- ${page.src}`);
}
console.log(`  ${'index.html'.padEnd(22)} (hub)`);
