<?php

declare(strict_types=1);

namespace Drupal\Tests\signal_recommendations\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\signal_recommendations\ViewCountStorageInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests recording and reading article view counts.
 *
 * @group signal_recommendations
 * @covers \Drupal\signal_recommendations\ViewCountStorage
 */
#[RunTestsInSeparateProcesses]
final class ViewCountStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'taxonomy',
    'signal_recommendations',
  ];

  /**
   * The service under test.
   */
  private ViewCountStorageInterface $storage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('signal_recommendations', ['signal_recommendations_view_count']);
    $this->storage = $this->container->get('signal_recommendations.view_count_storage');
  }

  /**
   * An unseen node reports a zero count.
   */
  public function testUnseenNodeReturnsZero(): void {
    $this->assertSame(0, $this->storage->getCount(42));
  }

  /**
   * Each recorded view increments the running total for that node only.
   */
  public function testRecordViewIncrements(): void {
    $this->storage->recordView(1);
    $this->storage->recordView(1);
    $this->storage->recordView(2);

    $this->assertSame(2, $this->storage->getCount(1));
    $this->assertSame(1, $this->storage->getCount(2));
    $this->assertSame(0, $this->storage->getCount(3));
  }

  /**
   * Counts for many nodes are returned in one batch, omitting unseen nodes.
   */
  public function testGetCountsBatch(): void {
    $this->storage->recordView(1);
    $this->storage->recordView(1);
    $this->storage->recordView(2);

    $this->assertSame([1 => 2, 2 => 1], $this->storage->getCounts([1, 2, 3]));
    $this->assertSame([], $this->storage->getCounts([]));
  }

}
