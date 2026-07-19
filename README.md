# Signal Recommendation Engine

A custom-themed blog/publication site whose "related content" rail is powered by
a **bespoke recommendation engine** — no contrib recommendation module. Drupal 11 practice: Single Directory Components (SDC), custom plugins with dependency injection, cache-metadata correctness, and a tested scoring algorithm.

## What it does

Signal is a small publication. Editors write **Articles** — a title, body,
featured image, and one or more **tags**. Readers browse a grid of articles on
the front page and read them on a clean, component-built page.

The headline feature is what happens _beside_ each article: a **"Related
articles" rail** that recommends other posts the reader is likely to want next.
Those recommendations are chosen by a custom engine that scores every candidate
article on three signals and shows the best few:

1. **Shared tags** — how much the topics overlap (the strongest signal).
2. **Recency** — newer articles are favoured, with the effect fading over time.
3. **Popularity** — how often an article has actually been read, counted in a
   way that keeps working even when pages are served from cache.

Everything that could reasonably change — the weight of each signal, how fast
recency fades, how many articles to show — is configuration, not code. And the
rail is cached precisely: it refreshes the moment a relevant article is edited,
added, or unpublished, without throwing away the rest of the page's cache.

## What this project demonstrates

- A clean **content model** (Article + Tags taxonomy) shipped as an idempotent,
  re-applicable **Drupal 11 recipe**.
- A **custom theme** (`signal_theme`) built entirely from **Single Directory
  Components** with typed prop/slot schemas, logical CSS properties (RTL-safe),
  a token-driven design system, and automatic dark mode — no base theme.
- A **cache-safe view-count mechanism** decoupled from the node entity, recorded
  via an async beacon so it counts page-cached (anonymous) views without
  invalidating the node.
- A **`RecommendationBlock`** plugin that scores related articles by shared
  taxonomy terms, recency, and popularity — with the scoring logic isolated in a
  pure, unit-tested service.
- **Precise cache metadata** (tags + contexts + max-age) so the recommendation
  rail invalidates per-node without over-invalidating the page cache.

## Architecture

The engine is split into small, single-responsibility pieces so the algorithm
can be reasoned about and tested in isolation from Drupal's plumbing.

```mermaid
flowchart TD
  subgraph tracking [View tracking]
    A[Full article view] -->|async beacon JS| B[ViewTrackController<br/>CSRF + node.view access]
    B --> C[(signal_recommendations_view_count<br/>decoupled table)]
  end

  subgraph recommend [Recommendation rail]
    D[RecommendationBlock<br/>context-aware: entity:node] --> E[RecommendationProvider]
    E -->|one grouped query:<br/>shared tags| F[(node__field_tags)]
    E -->|batched read| C
    E --> G[RecommendationScorer<br/>pure, no container]
    G -->|ranked| E
    E -->|access-checked nodes| D
    D -->|SDC + teaser view mode| H[recommendation-rail<br/>› article-card teasers]
  end
```

### Content model

`recipes/signal_content_model/` provisions the **Article** content type
(`body`, `field_tags` → Tags vocabulary, `field_featured_image`, core `uid`
author) plus form and view displays. The recipe mirrors Drupal's normalized
config exactly, so it re-applies with zero drift.

### Scoring

`RecommendationScorer` is a pure function of value objects — no services, no
database, no clock — which keeps it fully unit-testable. Each signal is
normalised to 0..1 before weighting so the weights are directly comparable:

```
score = w_tag · min(1, sharedTags / sourceTags)
      + w_recency · exp(-ageDays / decay)
      + w_views · ln(1 + views) / ln(1 + maxViews)
```

Each signal is normalised to the same **0..1 range** before weighting, so the
three weights are directly comparable. The defaults make relevance the driver and
the other two tie-breakers — **relevance 0.60, recency 0.25, popularity 0.15** —
and every one of them is editable in `signal_recommendations.settings`.

#### 1. Relevance — shared tags (weight 0.60, the strongest signal)

How much the candidate's topics overlap with the article being read.

- **Measured as** `sharedTags / sourceTags`, capped at `1.0`. The denominator is
  the _source_ article's tag count, so the score answers "how much of _this_
  article's subject matter does the candidate cover?"
- **Example:** if the source has 3 tags, a candidate sharing 2 scores
  `2 / 3 ≈ 0.67`; one sharing all 3 scores `1.0`. Sharing more tags than the
  source even has is still capped at `1.0`.
- **Why normalise by the source's tag count:** it keeps the signal fair between
  articles with few and many tags, turning a raw overlap count into a true
  _proportion_ of overlap.
- Candidates must share at least `min_shared_tags` (default `1`) to be considered
  at all. That filter runs in the SQL query, before any scoring.

#### 2. Recency — how fresh the article is (weight 0.25)

Newer articles are favoured, with the boost fading smoothly rather than dropping
off a cliff.

- **Measured as** `exp(-ageDays / decay)`, where `decay` is `recency_decay_days`
  (default `30`).
- **Example:** a brand-new article scores `1.0`; at 30 days it has decayed to
  `exp(-1) ≈ 0.37`; at 60 days `exp(-2) ≈ 0.14`. It approaches — but never
  reaches — zero, so an older-but-relevant article is never fully excluded.
- **Edge case:** a future publish date (scheduled content) is clamped to age
  zero, scoring `1.0` rather than a nonsensical negative age.
- **Why exponential, not linear:** it models "interest fades gradually" far
  better than a hard cutoff, and `decay` is a single intuitive knob — the age at
  which freshness is worth about a third (1/e) of its peak.

#### 3. Popularity — how often it's been read (weight 0.15)

A gentle nudge from real reader behaviour, using the stored view counts (see
[How view tracking feeds recommendations](#how-view-tracking-feeds-recommendations)).

- **Measured as** `ln(1 + views) / ln(1 + maxViews)`, where `maxViews` is the
  highest count among the _current_ candidate set.
- **Example:** with the busiest candidate at 100 views, an article with 100
  scores `1.0`, one with 9 scores `ln(10) / ln(101) ≈ 0.5`, and one with 0
  scores `0.0`.
- **Why logarithmic:** the jump from 10 → 100 views should matter far more than
  1,000 → 1,090. Log dampening compresses the scale so a single viral article
  cannot dominate on traffic alone.
- **Why normalise to the set:** dividing by the busiest candidate keeps the
  signal in `0..1` whether the site has hundreds of views or millions. If nothing
  in the set has been viewed, the whole signal contributes `0`.
- The `+1` inside each `ln` avoids `ln(0)` and makes "never viewed" score exactly
  zero.

The three sub-scores are combined as the weighted sum above, and ties (equal
totals) break toward the **newer** article, then by node ID, so the ordering is
always deterministic. Every weight, the decay constant, the minimum shared tags,
and the result count live in `signal_recommendations.settings` — nothing is
hardcoded.

`RecommendationProvider` selects candidates sharing at least the configured
minimum tags in a single grouped query, batches in view counts, delegates to the
scorer, and returns only published nodes the current user may view.

### How view tracking feeds recommendations

The popularity signal is a genuine feedback loop from real readers, and it is
**stored**, not derived on the fly:

1. A reader opens a full article. `js/view-tracker.js` fires an async beacon to
   `POST /signal-recommendations/track/{node}`.
2. `ViewTrackController` records the hit via `ViewCountStorage::recordView()`,
   which upserts `count = count + 1` on that article's row in the
   `signal_recommendations_view_count` table — a permanent write that works even
   when the page itself was served from cache.
3. When the rail is (re)built, `RecommendationProvider` reads every candidate's
   count back in one batched query and passes it to the scorer as the popularity
   term.

So two things are stored versus cached, and it is worth keeping them separate:

- **View counts** — the raw reader data — live in their own database table and
  are **persisted permanently**.
- **The rendered rail** — the computed result — is only **cached**, and is
  always rebuilt from the live tags, dates and counts.

The bounded max-age means a brand-new view is counted instantly but only
influences the rail's ordering after the cache window elapses (see below). The
count is never lost; only its effect on the ordering is eventually consistent.

### Caching strategy

The `RecommendationBlock` carries deliberately precise cache metadata:

| Signal                                | Mechanism                                           | Why                                                                                          |
| ------------------------------------- | --------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| Current / recommended articles change | Cache **tags** `node:{id}`                          | Invalidate the exact rails affected, nothing else.                                           |
| A new article is published            | Cache **tag** `node_list:article`                   | Let new content enter the rail.                                                              |
| Which article / who is viewing        | Cache **contexts** `route`, `user.node_grants:view` | Per-article and access-filtered results.                                                     |
| View counts (high churn)              | Bounded **max-age**                                 | Refresh on a timer instead of invalidating on every view, which would defeat the page cache. |

View counts are stored in a **dedicated table**, not on the node, so recording a
view is a cheap upsert that never touches the node's cache tags. They are
recorded via an async JavaScript beacon — the only way to count views served
from Drupal's page cache, where no PHP runs for the page.

## Module & theme layout

```
recipes/signal_content_model/     # Article + Tags content model (recipe)
web/modules/custom/signal_recommendations/
  src/ViewCountStorage.php         # decoupled counter service
  src/Controller/                  # beacon endpoint
  src/Recommendation/             # pure scorer + provider + value objects
  src/Plugin/Block/               # RecommendationBlock (context-aware)
  config/                          # settings + schema + block placement
  tests/                           # Unit (scorer) + Kernel (storage, provider, block)
web/themes/custom/signal_theme/    # SDC theme, no base theme
  components/{tag-pill,article-card,hero,recommendation-rail}/
scripts/seed_content.php           # development sample content
```

## Local development (DDEV)

This project uses [DDEV](https://ddev.readthedocs.io/) for local development.
Drupal core, contrib, and `vendor/` are Composer-managed and not committed.

```bash
ddev start
ddev composer install

# Install Drupal (standard profile).
ddev drush site:install standard -y

# Apply the content-model recipe.
ddev drush recipe recipes/signal_content_model

# Enable the theme first, then the module: the module's config/optional block
# placement targets signal_theme, so the theme must exist when the module is
# installed for the rail to be placed automatically.
ddev drush theme:enable signal_theme -y
ddev drush config:set system.theme default signal_theme -y
ddev drush en signal_recommendations -y

# Optional: load sample cross-tagged articles with placeholder images.
ddev drush php:script scripts/seed_content.php

ddev drush cr
ddev launch
```

## Coding standards & tests

```bash
# Lint custom code against Drupal / DrupalPractice (config in phpcs.xml.dist):
ddev exec vendor/bin/phpcs

# Run the test suite (Unit + Kernel):
ddev exec "SIMPLETEST_DB=mysql://db:db@db/db vendor/bin/phpunit -c web/core web/modules/custom/signal_recommendations/tests"
```

Current suite: **17 tests, 151 assertions** — pure scoring maths (Unit),
plus view-count storage, candidate selection, and block cache metadata (Kernel).

## Skills demonstrated

- **Drupal 11 site building as code** — modelled a content domain as an
  idempotent, version-controlled recipe (Article type, taxonomy tagging, media,
  form/view displays) that reproduces from code on any environment.
- **Single Directory Components** — built a front-end theme with no base theme
  from reusable card/hero/tag/rail components with typed prop-and-slot schemas, a
  token-driven design system with automatic dark mode, and RTL-safe logical-property CSS.
- **Cache-safe analytics** — engineered view tracking decoupled from the entity
  so increments never invalidate the render cache, recorded via an access- and
  CSRF-gated async beacon that counts page-cached anonymous traffic.
- **Algorithm design & testing** — implemented a normalised, weighted
  recommendation score (tag overlap, exponential recency decay, log-dampened
  popularity) as a pure, unit-tested service separated from a database-backed
  provider.
- **Render-cache correctness** — authored a context-aware block with per-node
  and list cache tags, access-aware contexts, and a bounded max-age to absorb a
  high-churn signal without defeating the page cache — verified by Kernel tests
  asserting the exact cacheability metadata.
- **Dependency injection & plugin APIs** — constructor DI throughout (no static
  `\Drupal::` calls in classes), a dedicated logger channel, graceful external
  failure handling, and correct plugin types/interfaces.
- **Engineering hygiene** — Composer-managed build, a committed PHPCS ruleset,
  small reviewable commits, and PHPUnit coverage of all custom logic.

## License

GPL-2.0-or-later, consistent with Drupal core.
