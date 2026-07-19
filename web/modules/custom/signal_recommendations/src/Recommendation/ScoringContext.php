<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

/**
 * Immutable, per-request scoring parameters shared across all candidates.
 *
 * Carries the configured weights and the normalisation inputs (the source
 * article's tag count and the current time) so the scorer stays a pure
 * function of its inputs.
 */
final class ScoringContext
{

  /**
   * Constructs a ScoringContext value object.
   *
   * @param int $sourceTagCount
   *   Number of tags on the source article; the denominator for tag overlap.
   * @param int $now
   *   The current Unix timestamp, used to compute candidate age.
   * @param float $tagWeight
   *   Relative weight of the shared-tags signal.
   * @param float $recencyWeight
   *   Relative weight of the recency signal.
   * @param float $viewWeight
   *   Relative weight of the popularity signal.
   * @param float $recencyDecayDays
   *   Age in days at which the recency signal falls to 1/e of its peak.
   */
  public function __construct(
    public readonly int $sourceTagCount,
    public readonly int $now,
    public readonly float $tagWeight,
    public readonly float $recencyWeight,
    public readonly float $viewWeight,
    public readonly float $recencyDecayDays,
  ) {}
}
