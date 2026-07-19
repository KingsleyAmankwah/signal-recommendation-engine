# Signal — Drupal 11 Publication with a Plugin-Based Recommendation Engine

A custom-themed blog/publication site whose "related content" rail is powered by
a **bespoke recommendation engine** — no contrib recommendation module. Built to
demonstrate senior-level Drupal 11 practice: Single Directory Components (SDC),
custom plugins with dependency injection, cache-metadata correctness, and a
tested scoring algorithm.

> **Status:** in active development. This README grows with each milestone.

## What this project demonstrates

- A clean **content model** (Article + Tags taxonomy) shipped as a re-applicable
  **Drupal 11 recipe**.
- A **custom theme** (`signal_theme`) built entirely from **Single Directory
  Components** with typed prop schemas, logical CSS properties (RTL-safe), and
  no base-theme inheritance.
- A **cache-safe view-count mechanism** decoupled from the node entity, recorded
  via an async beacon so it counts page-cached (anonymous) views without
  invalidating the node's cache.
- A **`RecommendationBlock`** plugin that scores related articles by shared
  taxonomy terms, recency, and popularity — with the scoring logic isolated in a
  pure, unit-tested service.
- **Precise cache metadata** (tags + contexts + max-age) so the recommendation
  rail invalidates per-node without over-invalidating the page cache.

## Architecture

_High-level component and data-flow description — added in the recommendation
milestone._

## Local development (DDEV)

This project uses [DDEV](https://ddev.readthedocs.io/) for local development.

```bash
# Clone, then from the project root:
ddev start
ddev composer install
ddev drush site:install --account-name=admin --account-pass=admin -y
# Apply the content-model recipe (added in Milestone 1):
# ddev drush recipe ../recipes/signal_content_model
ddev launch
```

> Drupal core, contrib, and the `vendor/` directory are Composer-managed and are
> not committed — `ddev composer install` restores them.

## Coding standards & tests

```bash
# Lint custom code against Drupal / DrupalPractice (config in phpcs.xml.dist):
ddev exec vendor/bin/phpcs

# Run the test suite:
ddev exec vendor/bin/phpunit web/modules/custom
```

## Skills demonstrated

_CV-ready bullet points, appended at the end of each milestone._

## License

GPL-2.0-or-later, consistent with Drupal core.
