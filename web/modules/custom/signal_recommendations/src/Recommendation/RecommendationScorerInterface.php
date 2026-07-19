<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

/**
 * Ranks candidate articles for recommendation.
 */
interface RecommendationScorerInterface
{

  /**
   * Scores and ranks candidates, best first.
   *
   * @param \Drupal\signal_recommendations\Recommendation\RecommendationCandidate[] $candidates
   *   The candidates to score.
   * @param \Drupal\signal_recommendations\Recommendation\ScoringContext $context
   *   The weights and normalisation inputs for this scoring run.
   *
   * @return \Drupal\signal_recommendations\Recommendation\ScoredCandidate[]
   *   The candidates wrapped with their scores, sorted by score descending.
   */
  public function rank(array $candidates, ScoringContext $context): array;
}
