<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

/**
 * A candidate paired with its computed score and component sub-scores.
 *
 * The normalised sub-scores are retained alongside the final score to make the
 * ranking transparent and directly assertable in tests.
 */
final class ScoredCandidate {

  public function __construct(
    public readonly RecommendationCandidate $candidate,
    public readonly float $score,
    public readonly float $tagScore,
    public readonly float $recencyScore,
    public readonly float $viewScore,
  ) {}

}
