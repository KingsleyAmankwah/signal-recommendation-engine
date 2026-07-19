<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations;

/**
 * Reads and records article view counts.
 *
 * View counts are stored independently of the node entity so that recording a
 * view is a cheap write that never invalidates the node's render cache.
 */
interface ViewCountStorageInterface
{

  /**
   * Records a single view of a node, incrementing its running total.
   *
   * @param int $nid
   *   The node ID that was viewed.
   */
  public function recordView(int $nid): void;

  /**
   * Returns the recorded view count for a single node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   The number of recorded views, or 0 if the node has never been viewed.
   */
  public function getCount(int $nid): int;

  /**
   * Returns view counts for multiple nodes in a single query.
   *
   * @param int[] $nids
   *   The node IDs to look up.
   *
   * @return array<int, int>
   *   View counts keyed by node ID. Nodes with no recorded views are omitted.
   */
  public function getCounts(array $nids): array;
}
