<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

/**
 * Immutable inputs describing a single candidate article to be scored.
 *
 * Decoupling the scorer's inputs from Drupal entities keeps the scoring logic
 * pure and unit-testable: the scorer never loads a node or hits the database.
 */
final class RecommendationCandidate
{

  /**
   * Constructs a RecommendationCandidate value object.
   *
   * @param int $nid
   *   The candidate node ID.
   * @param int $sharedTagCount
   *   How many tags this candidate shares with the source article.
   * @param int $created
   *   The candidate's creation timestamp, used for the recency signal.
   * @param int $viewCount
   *   The candidate's recorded view count, used for the popularity signal.
   */
  public function __construct(
    public readonly int $nid,
    public readonly int $sharedTagCount,
    public readonly int $created,
    public readonly int $viewCount,
  ) {}
}
