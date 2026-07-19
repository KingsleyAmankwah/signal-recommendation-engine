<?php

declare(strict_types=1);

namespace Drupal\Tests\signal_recommendations\Unit;

use Drupal\signal_recommendations\Recommendation\RecommendationCandidate;
use Drupal\signal_recommendations\Recommendation\RecommendationScorer;
use Drupal\signal_recommendations\Recommendation\ScoringContext;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the pure recommendation scoring logic.
 */
#[Group('signal_recommendations')]
#[CoversClass(RecommendationScorer::class)]
final class RecommendationScorerTest extends UnitTestCase {

  /**
   * The scorer under test.
   */
  private RecommendationScorer $scorer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->scorer = new RecommendationScorer();
  }

  /**
   * An empty candidate list yields an empty result.
   */
  public function testEmptyReturnsEmpty(): void {
    $this->assertSame([], $this->scorer->rank([], $this->context()));
  }

  /**
   * Tag overlap is normalised by the source tag count and capped at 1.
   */
  public function testTagOverlapNormalisedAndCapped(): void {
    $context = $this->context(sourceTagCount: 2, tag: 1.0, recency: 0.0, view: 0.0);
    $two = new RecommendationCandidate(nid: 1, sharedTagCount: 2, created: 0, viewCount: 0);
    $one = new RecommendationCandidate(nid: 2, sharedTagCount: 1, created: 0, viewCount: 0);
    $over = new RecommendationCandidate(nid: 3, sharedTagCount: 5, created: 0, viewCount: 0);

    $ranked = $this->scorer->rank([$one, $two, $over], $context);

    $by_nid = $this->byNid($ranked);
    $this->assertEqualsWithDelta(1.0, $by_nid[1]->tagScore, 1e-9);
    $this->assertEqualsWithDelta(0.5, $by_nid[2]->tagScore, 1e-9);
    // Sharing more tags than the source has is still capped at 1.0.
    $this->assertEqualsWithDelta(1.0, $by_nid[3]->tagScore, 1e-9);
  }

  /**
   * Recency decays exponentially and future dates clamp to zero age.
   */
  public function testRecencyDecay(): void {
    $now = 2_000_000;
    $context = $this->context(now: $now, tag: 0.0, recency: 1.0, view: 0.0, decay: 30.0);
    $fresh = new RecommendationCandidate(nid: 1, sharedTagCount: 0, created: $now, viewCount: 0);
    $one_life = new RecommendationCandidate(nid: 2, sharedTagCount: 0, created: $now - 30 * 86400, viewCount: 0);
    $future = new RecommendationCandidate(nid: 3, sharedTagCount: 0, created: $now + 86400, viewCount: 0);

    $ranked = $this->scorer->rank([$one_life, $future, $fresh], $context);

    $by_nid = $this->byNid($ranked);
    $this->assertEqualsWithDelta(1.0, $by_nid[1]->recencyScore, 1e-9);
    $this->assertEqualsWithDelta(exp(-1), $by_nid[2]->recencyScore, 1e-9);
    // Future creation date is treated as age zero, not a negative age.
    $this->assertEqualsWithDelta(1.0, $by_nid[3]->recencyScore, 1e-9);
  }

  /**
   * Popularity is log-dampened and normalised against the busiest candidate.
   */
  public function testPopularityLogNormalised(): void {
    $context = $this->context(tag: 0.0, recency: 0.0, view: 1.0);
    $top = new RecommendationCandidate(nid: 1, sharedTagCount: 0, created: 0, viewCount: 99);
    $mid = new RecommendationCandidate(nid: 2, sharedTagCount: 0, created: 0, viewCount: 9);
    $none = new RecommendationCandidate(nid: 3, sharedTagCount: 0, created: 0, viewCount: 0);

    $ranked = $this->scorer->rank([$mid, $none, $top], $context);

    $by_nid = $this->byNid($ranked);
    $this->assertEqualsWithDelta(1.0, $by_nid[1]->viewScore, 1e-9);
    // log(1+9) / log(1+99) = log(10)/log(100) = 0.5.
    $this->assertEqualsWithDelta(0.5, $by_nid[2]->viewScore, 1e-9);
    $this->assertEqualsWithDelta(0.0, $by_nid[3]->viewScore, 1e-9);
    $this->assertSame(1, $ranked[0]->candidate->nid);
  }

  /**
   * With no views anywhere, the popularity signal contributes nothing.
   */
  public function testZeroViewsGivesZeroViewScore(): void {
    $context = $this->context(tag: 0.0, recency: 0.0, view: 1.0);
    $candidate = new RecommendationCandidate(nid: 1, sharedTagCount: 0, created: 0, viewCount: 0);

    $ranked = $this->scorer->rank([$candidate], $context);

    $this->assertEqualsWithDelta(0.0, $ranked[0]->viewScore, 1e-9);
    $this->assertEqualsWithDelta(0.0, $ranked[0]->score, 1e-9);
  }

  /**
   * The final score is the weighted sum of the three normalised signals.
   */
  public function testWeightedCombination(): void {
    $context = $this->context(sourceTagCount: 2, now: 100, tag: 0.6, recency: 0.25, view: 0.15, decay: 30.0);
    // Signals: tag 0.5, recency 1.0, view 1.0 (sole candidate is the max).
    $candidate = new RecommendationCandidate(nid: 1, sharedTagCount: 1, created: 100, viewCount: 5);

    $ranked = $this->scorer->rank([$candidate], $context);

    $expected = 0.6 * 0.5 + 0.25 * 1.0 + 0.15 * 1.0;
    $this->assertEqualsWithDelta($expected, $ranked[0]->score, 1e-9);
  }

  /**
   * Equal scores break ties toward newer articles, then higher node ID.
   */
  public function testTieBreakByRecencyThenNid(): void {
    $context = $this->context(sourceTagCount: 2, tag: 1.0, recency: 0.0, view: 0.0);

    $older = new RecommendationCandidate(nid: 1, sharedTagCount: 1, created: 100, viewCount: 0);
    $newer = new RecommendationCandidate(nid: 2, sharedTagCount: 1, created: 200, viewCount: 0);
    $ranked = $this->scorer->rank([$older, $newer], $context);
    $this->assertSame(2, $ranked[0]->candidate->nid);

    $low_nid = new RecommendationCandidate(nid: 5, sharedTagCount: 1, created: 100, viewCount: 0);
    $high_nid = new RecommendationCandidate(nid: 9, sharedTagCount: 1, created: 100, viewCount: 0);
    $ranked = $this->scorer->rank([$low_nid, $high_nid], $context);
    $this->assertSame(9, $ranked[0]->candidate->nid);
  }

  /**
   * Builds a scoring context with sensible test defaults.
   */
  private function context(
    int $sourceTagCount = 2,
    int $now = 0,
    float $tag = 0.6,
    float $recency = 0.25,
    float $view = 0.15,
    float $decay = 30.0,
  ): ScoringContext {
    return new ScoringContext($sourceTagCount, $now, $tag, $recency, $view, $decay);
  }

  /**
   * Re-keys ranked results by node ID for order-independent assertions.
   *
   * @param array $ranked
   *   The ranked ScoredCandidate objects.
   *
   * @return array
   *   The same objects, keyed by node ID.
   */
  private function byNid(array $ranked): array {
    $keyed = [];
    foreach ($ranked as $item) {
      $keyed[$item->candidate->nid] = $item;
    }
    return $keyed;
  }

}
