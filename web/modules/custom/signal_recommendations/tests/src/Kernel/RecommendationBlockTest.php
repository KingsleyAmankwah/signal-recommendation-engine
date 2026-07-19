<?php

declare(strict_types=1);

namespace Drupal\Tests\signal_recommendations\Kernel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\signal_recommendations\Plugin\Block\RecommendationBlock;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the recommendation block's output and cache metadata.
 *
 * The cache-metadata assertions are the point of this test: they pin down the
 * per-node invalidation strategy that keeps the rail fresh without
 * over-invalidating the page cache.
 */
#[Group('signal_recommendations')]
#[CoversClass(RecommendationBlock::class)]
#[RunTestsInSeparateProcesses]
final class RecommendationBlockTest extends KernelTestBase {

  use UserCreationTrait;

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
    'block',
    'signal_recommendations',
  ];

  /**
   * The block plugin manager.
   */
  private BlockManagerInterface $blockManager;

  /**
   * Tag term IDs keyed by name.
   *
   * @var array<string, int>
   */
  private array $terms = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('signal_recommendations', ['signal_recommendations_view_count']);
    $this->installConfig(['field', 'signal_recommendations']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'article',
      'settings' => [
        'handler' => 'default',
        'handler_settings' => ['target_bundles' => ['tags' => 'tags']],
      ],
    ])->save();

    $this->setUpCurrentUser([], [], TRUE);

    foreach (['A', 'B'] as $name) {
      $term = Term::create(['vid' => 'tags', 'name' => $name]);
      $term->save();
      $this->terms[$name] = (int) $term->id();
    }

    $this->blockManager = $this->container->get('plugin.manager.block');
  }

  /**
   * With related content, the block renders the rail and tags every source.
   */
  public function testRendersRailWithPreciseCacheTags(): void {
    $source = $this->createArticle('Source', ['A', 'B']);
    $recommended = $this->createArticle('Recommended', ['A', 'B']);

    $build = $this->buildBlockFor($source);

    $this->assertSame('signal_theme:recommendation-rail', $build['#component']);
    $this->assertCount(1, $build['#slots']['items']);

    $metadata = CacheableMetadata::createFromRenderArray($build);
    $tags = $metadata->getCacheTags();
    $this->assertContains('node:' . $source->id(), $tags);
    $this->assertContains('node:' . $recommended->id(), $tags);
    $this->assertContains('node_list:article', $tags);

    $contexts = $metadata->getCacheContexts();
    $this->assertContains('route', $contexts);
    $this->assertContains('user.node_grants:view', $contexts);

    // Bounded max-age carries the volatile view-count signal.
    $this->assertSame(3600, $metadata->getCacheMaxAge());
  }

  /**
   * With no related content, the block is empty but still correctly cached.
   */
  public function testEmptyRailStillCarriesInvalidationMetadata(): void {
    $source = $this->createArticle('Lonely', ['A']);

    $build = $this->buildBlockFor($source);

    $this->assertArrayNotHasKey('#component', $build);

    $metadata = CacheableMetadata::createFromRenderArray($build);
    // node_list:article ensures the empty rail re-evaluates when a matching
    // article is later published; node:source when the source changes.
    $this->assertContains('node_list:article', $metadata->getCacheTags());
    $this->assertContains('node:' . $source->id(), $metadata->getCacheTags());
    $this->assertSame(3600, $metadata->getCacheMaxAge());
  }

  /**
   * Builds the block plugin for a given article context.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The article to set as the block's node context.
   *
   * @return array
   *   The block build render array.
   */
  private function buildBlockFor(NodeInterface $node): array {
    /** @var \Drupal\signal_recommendations\Plugin\Block\RecommendationBlock $block */
    $block = $this->blockManager->createInstance('signal_recommendations_rail', []);
    $block->setContextValue('node', $node);
    return $block->build();
  }

  /**
   * Creates a published article with the given tags.
   *
   * @param string $title
   *   The article title.
   * @param string[] $tag_names
   *   Tag names to attach.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  private function createArticle(string $title, array $tag_names): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'status' => NodeInterface::PUBLISHED,
      'field_tags' => array_map(fn (string $name): int => $this->terms[$name], $tag_names),
    ]);
    $node->save();
    return $node;
  }

}
