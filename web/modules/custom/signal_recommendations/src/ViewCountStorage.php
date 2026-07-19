<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores article view counts in a dedicated table.
 *
 * Keeping the counter out of the node entity is deliberate: an increment is a
 * single upsert against one row and does not touch the node's cache tags, so a
 * burst of traffic cannot invalidate the page cache. Reads are batched so the
 * recommendation scorer can fetch every candidate's count in one query.
 */
final class ViewCountStorage implements ViewCountStorageInterface {

  /**
   * The name of the view-count table.
   */
  public const TABLE = 'signal_recommendations_view_count';

  public function __construct(
    private readonly Connection $connection,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function recordView(int $nid): void {
    $now = $this->time->getRequestTime();
    $this->connection->merge(self::TABLE)
      ->keys(['nid' => $nid])
      ->fields([
        'count' => 1,
        'last_view' => $now,
      ])
      ->expression('count', '[count] + :increment', [':increment' => 1])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getCount(int $nid): int {
    return $this->getCounts([$nid])[$nid] ?? 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCounts(array $nids): array {
    if ($nids === []) {
      return [];
    }

    $rows = $this->connection->select(self::TABLE, 'v')
      ->fields('v', ['nid', 'count'])
      ->condition('nid', $nids, 'IN')
      ->execute();

    $counts = [];
    foreach ($rows as $row) {
      $counts[(int) $row->nid] = (int) $row->count;
    }

    return $counts;
  }

}
