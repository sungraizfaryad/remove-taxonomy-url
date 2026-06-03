# Remove Taxonomy URL 3.1 — Hierarchy Resolution, Diagnostics & Settings UX

**Date:** 2026-06-04
**Version target:** 3.1.0 (minor — turns a broken feature into a working one, adds diagnostics + UX)
**Status:** Approved design, ready for implementation plan

---

## 1. Background & Motivation

3.0.x shipped a "Hierarchical term URLs" option (`rtu_enable_hierarchy`). When ON, for a child
term it walked the parent chain and set the request query var to the nested *path string*
(`property-city = "portugal/lisbon"`). But nothing registered a rewrite rule to resolve a nested
path, and `term_link` still emitted the flat `/lisbon/`. The result on a live site (Fine Luxury
Property):

- Top-level terms (`/portugal/`, `/spain/`) → **200** (no parent to prepend; query stays `portugal`).
- Child terms (`/lisbon/`, `/cascais/`, `/algarve/`, `/porto/`, …) → **404** (query became
  `portugal/lisbon`, which matches no term; with hierarchy ON the child had **no working URL at
  all** — neither flat `/lisbon/` nor nested `/portugal/lisbon/`).

This "half the site works" pattern (top-level fine, every sub-page 404) is the signature of the
hierarchy setting, not slug collisions. The Health Check did **not** surface it because it only
checks slug collisions — it had no awareness of URL resolution.

### Grounding data (FLP, the integration test bed)

`property-city`: **444 terms, 25 top-level, 419 children, 81 distinct parents** — genuine
multi-level nesting (e.g. `portugal → algarve → <town>`). `property-type`: 21 terms (17 children).
`property-status`: 1 (flat). Heavy archives: Portugal 8448 posts, Lisbon 3606, Algarve 1224.

**Key correctness fact:** WordPress enforces **unique term slugs per taxonomy** (a second term
with slug `lisbon` in `property-city` is auto-suffixed `lisbon-2`). Therefore resolving a nested
path down to its **leaf slug** (`?property-city=lisbon`) is unambiguous regardless of nesting
depth. The fix relies on this guarantee.

---

## 2. Goal

When "Hierarchical term URLs" is ON:

1. Child-term links and sitemaps emit the full nested stripped URL (`/portugal/lisbon/`,
   `/portugal/algarve/<town>/`).
2. Those nested URLs **resolve** (HTTP 200, correct term archive) at arbitrary depth.
3. Old flat URLs (`/lisbon/`) **301-redirect** to the canonical nested URL (link/SEO consolidation).
4. Top-level terms are unaffected.

Plus: a diagnostic that would have **caught the live 404s** (per-term URL resolution self-test),
clearer per-setting help text, and explicit save/flush feedback.

When hierarchy is OFF, behavior is exactly 3.0.1 (flat `/lisbon/` resolves; no nesting).

## 3. Non-Goals

- Re-registering the theme's taxonomies as hierarchical-rewrite (theme owns `register_taxonomy`;
  out of scope, fragile).
- Changing flat (non-hierarchy) behavior.
- Supporting per-taxonomy hierarchy toggles (single global flag, as today).
- Built-in taxonomies (`category`, `post_tag`) remain out of scope.

---

## 4. Architecture

Five units, each with one responsibility. New code is additive; existing modules are extended
along their current seams (the Loader-orchestrated module pattern is unchanged).

```
RTU_Url_Rewriter          (extended)  term_link: prepend ancestors when hierarchy ON
RTU_Hierarchy_Rules       (NEW)       generate + inject explicit per-child rewrite rules
RTU_Redirect_Handler      (extended)  canonical 301: flat child URL -> nested URL (hierarchy ON)
RTU_Conflict_Detector     (extended)  + URL resolution self-test + hierarchy status in audit
RTU_Options               (extended)  rtu_last_flushed timestamp; descriptions live in the partial
settings partial + admin  (extended)  richer descriptions, save/flush feedback, hierarchy notice
```

### 4.1 Hierarchy approach: explicit per-child rewrite rules (A2)

Rejected alternatives: A1 (greedy `^(.+?)/?$` catch-all + leaf resolution) fights WordPress's core
page catch-all for rule ordering and is fragile across varied installs; A3 (re-register taxonomy as
hierarchical) is not ours to do.

**Chosen (A2):** for each child term of each active taxonomy, register one specific rule:

```
^portugal/lisbon/?$                  => index.php?property-city=lisbon
^portugal/lisbon/page/([0-9]{1,})/?$ => index.php?property-city=lisbon&paged=$matches[1]
^portugal/algarve/<town>/?$          => index.php?property-city=<town>           (multi-level)
```

The path is the term's **full ancestor chain + own slug** (deepest-first walk, reusing the existing
`prepend_parents()` cycle-guard logic). The target query var is the **leaf slug only** — unique per
taxonomy, so it always resolves to exactly the right term. Because the rules are *specific* (not
greedy), they intercept the nested path before WordPress's generic page rule (which otherwise sets
`pagename=portugal/lisbon` → no page → 404), and they never shadow unrelated URLs. The one URL they
*can* legitimately collide with is a real page/post living at the same path — which is exactly what
the slug-collision detector already flags.

Why this is needed (traced against FLP's live rules): a 2-segment `/portugal/lisbon/` currently
matches the core page rule `(.?.+?)(?:/([0-9]+))?/?$ => pagename`, never the single-segment name
rule `([^/]+)/?$`, so the existing `request` filter (which keys on `name`) never sees it. The
explicit rule is the only thing that routes the nested path to a taxonomy query var.

### 4.2 term_link ancestor prepend

`RTU_Url_Rewriter::filter_term_link()`, hierarchy ON: after stripping the base slug, prepend the
ancestor slug path so the public/canonical link is `/portugal/lisbon/`. Today it emits flat
`/lisbon/` (the FLP `property-city` rewrite is flat `[^/]+`), which is half the original bug — the
link side never nested. Reuse the same ancestor walk used for rule generation so link and rule agree
exactly. Hierarchy OFF: unchanged (flat strip).

### 4.3 Canonical redirect

`RTU_Redirect_Handler`, hierarchy ON: when a request resolves to a child term via the **flat**
single-segment URL (`/lisbon/` — still matched by the core name rule + existing request filter),
issue a 301 to the canonical nested URL (`/portugal/lisbon/`). This keeps pre-existing flat links
alive and consolidates SEO. This is **independent** of the existing "301 redirect old URLs" option
(which redirects `/taxonomy/term/` → `/term/`); the canonical nested redirect is part of the
hierarchy feature and active whenever hierarchy is ON. Guard against redirect loops (only redirect
flat→nested, never nested→nested) and reuse the existing `template_redirect` priority-99 +
`$already_ran` guard.

### 4.4 Rule lifecycle / flush

`RTU_Hierarchy_Rules` injects rules via `rewrite_rules_array` (like the pagination module). Rules are
regenerated whenever the term tree changes: hook `created_term`, `edited_term`, `delete_term` for the
active taxonomies, and on settings save — each arms the existing `rtu_needs_flush` transient, which
`admin_init` (priority 99) consumes to call `flush_rewrite_rules(false)` once. No change to the flush
mechanism itself, just more triggers.

**Cap:** if an active taxonomy has more than **2000** child terms, skip rule generation for that
taxonomy and surface an admin notice + a Health Check warning naming the taxonomy and count (no
silent truncation). FLP's 419 is far under this; the cap is a pathological-site backstop only.

### 4.5 URL resolution self-test (the diagnostic that would have caught the live bug)

Extend the Health Check audit (`RTU_Conflict_Detector::ajax_run_audit` + a new method, or a new
collaborator `RTU_Url_Selftest` if the detector grows too large — decide at plan time by file size):

- For each active taxonomy, **sample** its terms: all top-level (capped) + a spread of children
  across different parents, **capped at 25 terms total per audit**, and report the cap ("tested 25
  of 444").
- For each sampled term, compute its stripped permalink (via `get_term_link` → our `term_link`
  filter) and perform a **loopback request** (`wp_remote_get`, short timeout ~5s,
  `sslverify=false` for local) to that URL. PASS = HTTP 200 and not the 404 template; FAIL
  otherwise. Report PASS/FAIL per term with the tested URL and status code.
- **Loopback fallback:** if loopback is blocked/errors (common on locked-down hosts), say so
  explicitly and fall back to an internal check: confirm a rewrite rule matches the stripped path
  AND a term with the resolved leaf slug exists. Clearly label which mode ran.
- This check is cause-agnostic: it flags 404s from hierarchy misconfiguration, stale rewrite
  rules, slug collisions, or pagination — exactly what was invisible on live.

### 4.6 Hierarchy status in audit

Add an informational block to the audit output: current `rtu_enable_hierarchy` state, the URL shape
children use under that state (`/parent/child/` vs `/child/`), and the count of child terms across
active taxonomies. Keep the existing slug-collision rows.

---

## 5. Settings UX

### 5.1 Per-setting descriptions

Adopt the user-supplied wording (escaped via `esc_html__`, i18n text-domain `remove-taxonomy-url`),
edited in `get_settings_fields()` in `admin/partials/remove-taxonomy-url-settings.php`. Verbatim
intent, lightly fitted to existing patterns:

- **Taxonomies List:** "Tick the taxonomies whose base slug you want removed. Example: with 'Genre'
  ticked, /genre/rock/ becomes /rock/. Only tick taxonomies you actually want shortened — each one
  changes its public URLs site-wide."
- **301 redirect old URLs:** "When ON, the old /genre/rock/ address permanently (301) redirects to
  the new /rock/. Turn this ON for SEO so search engines and old links follow to the new URL. Only
  turn ON once the short URLs are confirmed working — if a short URL is broken, this will redirect
  visitors straight into a 404."
- **Pagination support:** "Turn ON if your taxonomy archives split across multiple pages (page 2,
  3…). Keeps /rock/page/2/ working after the base slug is removed. Safe to leave ON."
- **Hierarchical term URLs (reworded for 3.1's now-working behavior):** "Controls how CHILD terms
  look. OFF: a child term uses a flat URL — /punk/. ON: a child term uses its full parent path —
  /rock/punk/ — and the flat /punk/ permanently redirects to it. Top-level terms are unaffected
  either way. If you turn this ON, child-term URLs change site-wide; existing flat links keep
  working via redirect."
- **Conflict detection on save:** "Leave ON. When you save, it checks whether a shortened term URL
  (e.g. /rock/) clashes with an existing page, post, or another term with the same slug, and warns
  you. It won't block the save — it just tells you before a clash silently breaks a URL. Use the
  Health Check tab to scan everything at once."

### 5.2 General note (top of settings page)

One line above the form: "After changing any setting here, the plugin re-flushes your permalinks
automatically. If a URL still shows 404, go to Settings → Permalinks and click Save once." Plus a
status line: active plugin version and "Last flushed: <date>".

### 5.3 Save + flush feedback

- On successful save, add a settings notice: "Settings saved — permalinks flushed ✓" (shown after
  the `admin_init` flush actually runs; if the flush is deferred to the transient, show "Settings
  saved — permalinks will flush on the next admin page load").
- Store `rtu_last_flushed` (unix timestamp) when `maybe_flush_rewrite_rules()` runs; render it
  human-readable on the settings page.

### 5.4 Hierarchy save-time notice

When a save flips `rtu_enable_hierarchy` from off→on (detected in `pre_update_option_rtu_basics`,
alongside the existing collision warn), add an informational settings notice: "Hierarchical term
URLs enabled. N child-term URLs will change to /parent/child/; existing flat URLs will 301-redirect."
Compute N from the active taxonomies' child counts.

---

## 6. Data model

No new option keys for the feature flags (unchanged: `rtu_post_types`, `rtu_enable_*`,
`rtu_db_version` in `rtu_basics`). Add:

- `rtu_last_flushed` (standalone option, unix timestamp) — set on flush, deleted in `uninstall.php`.

`DB_VERSION` → `3.1.0`. `maybe_migrate()` runs (idempotent); the existing normalization (3.0.1)
stays. No data shape change required for 3.1 — the migration bump exists to re-arm the one-time
rewrite-rule flush so the new hierarchy rules are generated on upgrade. Add
`rtu_last_flushed` to the uninstall cleanup.

---

## 7. Error handling & edge cases

- **Orphan / circular parents:** reuse the existing `prepend_parents()` visited-set + 25-iteration
  guard for both term_link prepend and rule generation. An orphan chain stops at the highest
  resolvable ancestor.
- **Duplicate leaf slugs:** impossible within a taxonomy (WP guarantee) — leaf-slug resolution is
  safe. Document the assumption inline.
- **Term moved to a new parent:** `edited_term` re-arms the flush; rules + canonical target update.
- **Cap exceeded (>2000 children):** skip + notice, no silent truncation.
- **Loopback blocked:** explicit fallback + labeled mode in the self-test.
- **Redirect loops:** canonical redirect only fires flat→nested; nested requests are never
  redirected.
- **Hierarchy OFF:** none of the new rules/redirects are registered; 3.0.1 behavior exactly.

---

## 8. Testing strategy

### 8.1 Unit (PHPUnit, canonical repo, PHP 8.2 + wp-phpunit socket setup)

- Rule generation: single-level child, multi-level grandchild, top-level (no rule), orphan chain,
  circular guard, the 2000 cap (skips + flags).
- `term_link` ancestor prepend: flat strip (hierarchy OFF) vs nested (ON), multi-level depth.
- Canonical redirect target computation (pure function): flat `/lisbon/` → `/portugal/lisbon/`;
  no redirect for top-level; no redirect when already nested.
- Self-test sampling: cap reported, mix of top-level + children selected.
- Migration: `DB_VERSION` 3.1.0 bump arms flush; 3.0.1 normalization preserved; idempotent.

### 8.2 Integration (FLP — the real bed, big theme, 444 deep terms)

Reproduce the live failure and prove the fix, mirroring canonical → FLP:

1. Hierarchy ON: `/portugal/lisbon/` → 200 (Lisbon archive); `/portugal/algarve/<town>/` → 200
   (multi-level); flat `/lisbon/` → 301 → `/portugal/lisbon/`; top-level `/portugal/`, `/spain/`
   → 200 unchanged.
2. Hierarchy OFF: flat `/lisbon/` → 200; no nested rules present.
3. Health Check self-test: reports PASS for sampled terms in the ON (fixed) state; if rules are
   intentionally left unflushed, reports FAIL for child terms (proves the diagnostic catches it).
4. Settings UX: save shows "flushed ✓" + Last-flushed timestamp; flipping hierarchy on shows the
   child-count notice.

### 8.3 Gates

Full PHPUnit green, PHPCS clean (`.phpcs.xml.dist`), Plugin Check, then a pre-ship adversarial
review workflow (as done for 3.0.1) before SVN deploy.

---

## 9. Release & rollout

- Version: header + `REMOVE_TAXONOMY_URL_VERSION` + README Stable tag + `DB_VERSION` all → `3.1.0`.
- README changelog entry describing the working hierarchy, the diagnostic, and the UX.
- Deploy via `deploy.sh` off the canonical git repo (Q2 = canonical path), `.svnignore` strips dev
  files — same proven path as 3.0.1.
- Live update steps for the site owner: update plugin → it re-flushes on first admin load → confirm
  with Health Check self-test → if anything 404s, Settings → Permalinks → Save once.

---

## 10. Open implementation decisions (resolved at plan time)

- Whether the self-test lives as new methods on `RTU_Conflict_Detector` or a new `RTU_Url_Selftest`
  collaborator — decide by resulting file size / single-responsibility; the detector is already
  ~170 lines.
- Exact loopback PASS heuristic for "not the 404 template" (status 200 is necessary but a theme may
  return 200 on its 404 page; consider also checking `is_404` via a lightweight marker the plugin
  can detect, or comparing the canonical body title). Lock the heuristic during implementation
  against FLP's actual 404 response.
