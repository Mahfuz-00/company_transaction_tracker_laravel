# Agent Rule Files — How to Write and Structure Them

This folder holds the **instruction files for our IDE/CLI AI agents**, one file per
stack specialism. Each file is a self-contained "system prompt" that constrains an agent
to a single area of the codebase so it produces changes that fit our conventions.

This document is the **meta-guide**: read it before adding a new agent rule file.

---

## 1. WHAT AN AGENT RULE FILE IS (AND IS NOT)

- It **IS** a persistent, versioned brief: role, hard rules, real paths, and defensive
  guardrails an agent must follow every time it touches this repo.
- It **IS NOT** project documentation. Human-facing docs live in `docs/`. An agent rule
  file optimises for an LLM's behaviour, not for a newcomer's reading flow — though it
  must never contain anything false.

## 2. THE ONE-AGENT-ONE-SPECIALISM RULE

Create a **separate file per stack/concern**, each owning a disjoint slice of the repo:

| File | Owns | Must not touch |
|---|---|---|
| `.github/UI-designer.md` | Design system, tokens, i18n/RTL, component templates | PHP, business logic |
| `.github/agents/backend.rule.md` | Laravel, Eloquent, tenancy, API, PHP tests | React/CSS |
| `.github/agents/frontend.rule.md` | React, Inertia, Tailwind, theming, responsiveness | PHP |

If an agent starts needing rules for two specialisms, **split it** rather than writing a
sprawling prompt. A focused agent with a narrow brief makes fewer, better-scoped edits.

## 3. FILE LOCATION & NAMING

- **Location:** `.github/agents/<name>.rule.md` for engineering agents; the original
  design agent lives at `.github/UI-designer.md` and is kept as-is.
- **Naming:** lowercase, one word or kebab-case, role-oriented (`backend`, `frontend`,
  `mobile`, `data`). Avoid `prompt1`/`rules-final` style names.
- Treat these as code: they are reviewed in PRs and versioned with the repo.

## 4. THE REQUIRED SKELETON

Every agent rule file uses this exact shape (mirroring `.github/UI-designer.md`):

````markdown
# AGENT ROLE: <Title describing the specialism>

One short paragraph: "You are an expert <X> embedded inside an AI-driven software
development environment. Your sole duty is to <scope>. You do not <out of scope>."

---

## 1. FIRST SECTION — THE PRIME DIRECTIVE (the one rule that matters most)

1. Numbered, imperative directives.
2. Bold the KEY terms so they survive attention.
3. Reference REAL class/file paths from this repo.

## 2. …N. FURTHER SECTIONS (ALL-CAPS headings, numbered)

- Use bold lead-ins for rules: "**NEVER …**", "**ALWAYS …**".
- Include fenced code blocks for exact snippets, token maps or templates.

## N. DEFENSIVE RULES & HALLUCINATION PREVENTION

**Rule 1 (Short Name):** one sentence stating a hard prohibition and why.
**Rule 2 (Short Name):** …

```
<ElicitationsGroup message="Your <X> agent rule file is ready. What would you like to build next?">
  <Elicitation label="…" query="…" />
</ElicitationsGroup>
```
````

The `<ElicitationsGroup>` tail is the house convention: it offers the next logical agent
or task, so a human can keep the suite consistent.

## 5. WRITING RULES THAT ACTUALLY WORK

1. **Be specific and falsifiable.** "Use `@/` imports" beats "follow good practices".
2. **Cite real symbols.** Name `App\Support\TenantManager`, `resolveInitialTheme`,
   `Pages/${name}.jsx`, `throttle:api` — not vague descriptions. Vague rules are ignored.
3. **State the WHY.** LLMs (and humans) comply better with a reason: "NEVER move files
   under `Pages/` — the `Inertia::render` name contract breaks."
4. **Prefer NEVER/ALWAYS pairs** over "consider" or "try to".
5. **Front-load the highest-risk rule.** For us the two constants are: backend =
   tenant isolation; frontend = the Pages/`Inertia::render` contract.
6. **Keep it drivable.** Aim for ~120–180 lines. A 600-line prompt dilutes attention.

## 6. AVOIDING STALENESS (the failure mode of rule files)

A rule file rots the moment the code changes underneath it. Guard against this:

- **Reference stable anchors:** class names, route names, config keys. These churn less
  than line numbers or file listings.
- **Re-verify on structural change.** If you rename a model, move a `Pages/` file, or add
  a migration convention, **update the rule files in the same PR**.
- **Never copy large code samples verbatim** that will drift — quote the *shape* and point
  at the real file instead.
- **A rule that contradicts the code is worse than no rule**: the agent will confidently
  break the build. When in doubt, delete the stale claim.

## 7. WORKED EXAMPLES IN THIS FOLDER

- `.github/agents/backend.rule.md` — tenant isolation as the Prime Directive; Form
  Requests; money via `Money`/`FinanceCalculator`; the Role/Module test layout.
- `.github/agents/frontend.rule.md` — the Pages glob contract as the Prime Directive;
  `@/` imports; CSS-variable theming; fluid/rem typography; the `3xl` breakpoint.
- `.github/UI-designer.md` — the original design-system agent, and the style template
  these files follow.

## 8. CHECKLIST BEFORE COMMITTING A NEW AGENT RULE FILE

- [ ] H1 is `# AGENT ROLE: …` with a one-paragraph "You are an expert …" mandate.
- [ ] Scope is a single specialism; out-of-scope is stated explicitly.
- [ ] Numbered `## N.` sections with ALL-CAPS titles.
- [ ] Every path/class/route/command referenced actually exists in the repo.
- [ ] Imperative NEVER/ALWAYS rules with bold lead-ins.
- [ ] A final "DEFENSIVE RULES & HALLUCINATION PREVENTION" section.
- [ ] Closing `<ElicitationsGroup>` block.
- [ ] Length ~120–180 lines; the highest-risk rule appears first.
