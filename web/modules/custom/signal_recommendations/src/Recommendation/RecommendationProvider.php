<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Recommendation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\signal_recommendations\ViewCountStorageInterface;

/**
 * Finds and ranks articles related to a given article.
 *
 * Candidate selection is a single grouped query over the tag field table: it
 * gathers every published article that shares at least the configured minimum
 * number of tags with the source, along with each candidate's shared-tag count
 * and creation time. View counts are then fetched in one batch and the pure
 * scorer ranks the result. Only nodes the current user may view are returned.
 */
final class RecommendationProvider implements RecommendationProviderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly ViewCountStorageInterface $viewCountStorage,
    private readonly RecommendationScorerInterface $scorer,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRecommendations(NodeInterface $node, ?int $limit = NULL): array {
    $source_tags = array_column($node->get('field_tags')->getValue(), 'target_id');
    if ($source_tags === []) {
      return [];
    }

    $config = $this->configFactory->get('signal_recommendations.settings');
    $min_shared = max(1, (int) $config->get('min_shared_tags'));
    $limit ??= max(1, (int) $config->get('max_results'));

    $rows = $this->queryCandidates($source_tags, (int) $node->id(), $min_shared);
    if ($rows === []) {
      return [];
    }

    $nids = array_map(static fn (object $row): int => (int) $row->nid, $rows);
    $view_counts = $this->viewCountStorage->getCounts($nids);

    $candidates = [];
    foreach ($rows as $row) {
      $nid = (int) $row->nid;
      $candidates[] = new RecommendationCandidate(
        $nid,
        (int) $row->shared,
        (int) $row->created,
        $view_counts[$nid] ?? 0,
      );
    }

    $context = new ScoringContext(
      sourceTagCount: count($source_tags),
      now: $this->time->getRequestTime(),
      tagWeight: (float) $config->get('weight_tags'),
      recencyWeight: (float) $config->get('weight_recency'),
      viewWeight: (float) $config->get('weight_views'),
      recencyDecayDays: (float) $config->get('recency_decay_days'),
    );

    return $this->loadRanked($this->scorer->rank($candidates, $context), $limit);
  }

  /**
   * Selects candidate articles sharing tags with the source.
   *
   * @param int[] $source_tags
   *   Term IDs on the source article.
   * @param int $source_nid
   *   The source node ID, excluded from the results.
   * @param int $min_shared
   *   Minimum number of shared tags a candidate must have.
   *
   * @return object[]
   *   Rows with nid, shared (tag count) and created (timestamp).
   */
  private function queryCandidates(array $source_tags, int $source_nid, int $min_shared): array {
    $query = $this->database->select('node__field_tags', 'ft');
    $query->addField('ft', 'entity_id', 'nid');
    $query->addExpression('COUNT(ft.field_tags_target_id)', 'shared');
    $query->fields('n', ['created']);
    $query->join('node_field_data', 'n', 'n.nid = ft.entity_id');
    $query->condition('ft.deleted', 0);
    $query->condition('ft.field_tags_target_id', $source_tags, 'IN');
    $query->condition('ft.entity_id', $source_nid, '<>');
    $query->condition('n.status', NodeInterface::PUBLISHED);
    $query->groupBy('ft.entity_id');
    $query->groupBy('n.created');
    $query->having('COUNT(ft.field_tags_target_id) >= :min', [':min' => $min_shared]);

    return $query->execute()->fetchAll();
  }

  /**
   * Loads the ranked candidates as nodes, access-checked and limited.
   *
   * @param \Drupal\signal_recommendations\Recommendation\ScoredCandidate[] $scored
   *   Candidates in descending score order.
   * @param int $limit
   *   Maximum number of nodes to return.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Viewable nodes in score order.
   */
  private function loadRanked(array $scored, int $limit): array {
    $ordered_nids = array_map(
      static fn (ScoredCandidate $item): int => $item->candidate->nid,
      $scored,
    );
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ordered_nids);

    $result = [];
    foreach ($scored as $item) {
      $candidate_node = $nodes[$item->candidate->nid] ?? NULL;
      if ($candidate_node !== NULL && $candidate_node->access('view')) {
        $result[] = $candidate_node;
        if (count($result) >= $limit) {
          break;
        }
      }
    }

    return $result;
  }

}
