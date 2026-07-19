<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

/**
 * Scores related articles by shared tags, recency and popularity.
 *
 * Each signal is normalised to the 0..1 range before weighting so the weights
 * are directly comparable:
 *  - Tags: shared tags divided by the source article's tag count (capped at 1).
 *  - Recency: exponential decay of the candidate's age, exp(-ageDays / decay).
 *  - Popularity: log-dampened view count normalised against the most-viewed
 *    candidate in the set, so a single runaway article cannot dominate.
 *
 * The class is a pure function of its inputs — no services, no database, no
 * clock — which keeps the ranking fully unit-testable.
 */
final class RecommendationScorer implements RecommendationScorerInterface
{

  private const SECONDS_PER_DAY = 86400;

  /**
   * {@inheritdoc}
   */
  public function rank(array $candidates, ScoringContext $context): array
  {
    if ($candidates === []) {
      return [];
    }

    // Popularity is normalised against the busiest candidate in this set.
    $maxViews = 0;
    foreach ($candidates as $candidate) {
      $maxViews = max($maxViews, $candidate->viewCount);
    }

    $scored = [];
    foreach ($candidates as $candidate) {
      $tag_score = $context->sourceTagCount > 0
        ? min(1.0, $candidate->sharedTagCount / $context->sourceTagCount)
        : 0.0;

      $age_days = max(0.0, ($context->now - $candidate->created) / self::SECONDS_PER_DAY);
      $recency_score = $context->recencyDecayDays > 0
        ? exp(-$age_days / $context->recencyDecayDays)
        : 0.0;

      $view_score = $maxViews > 0
        ? log(1 + $candidate->viewCount) / log(1 + $maxViews)
        : 0.0;

      $score = $context->tagWeight * $tag_score
        + $context->recencyWeight * $recency_score
        + $context->viewWeight * $view_score;

      $scored[] = new ScoredCandidate($candidate, $score, $tag_score, $recency_score, $view_score);
    }

    // Rank by score; break ties toward newer articles, then by node ID so the
    // ordering is always deterministic.
    usort(
      $scored,
      static fn(ScoredCandidate $a, ScoredCandidate $b): int =>
      [$b->score, $b->candidate->created, $b->candidate->nid]
        <=> [$a->score, $a->candidate->created, $a->candidate->nid]
    );

    return $scored;
  }
}
