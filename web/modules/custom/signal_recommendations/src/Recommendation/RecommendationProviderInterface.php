<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

use Drupal\node\NodeInterface;

/**
 * Produces ranked article recommendations for a given article.
 */
interface RecommendationProviderInterface {

  /**
   * Returns recommended articles related to the given node, best first.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The article to find related content for.
   * @param int|null $limit
   *   Maximum number to return. Defaults to the configured max_results.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Published, access-checked article nodes ordered by descending score.
   */
  public function getRecommendations(NodeInterface $node, ?int $limit = NULL): array;

}
