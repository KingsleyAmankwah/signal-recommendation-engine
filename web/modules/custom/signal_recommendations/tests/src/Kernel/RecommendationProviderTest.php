<?php

declare(strict_types=1);

namespace Drupal\Tests\signal_recommendations\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\signal_recommendations\Recommendation\RecommendationProvider;
use Drupal\signal_recommendations\Recommendation\RecommendationProviderInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests candidate selection, ranking and filtering by the provider.
 */
#[Group('signal_recommendations')]
#[CoversClass(RecommendationProvider::class)]
#[RunTestsInSeparateProcesses]
final class RecommendationProviderTest extends KernelTestBase {

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
    'signal_recommendations',
  ];

  /**
   * The provider under test.
   */
  private RecommendationProviderInterface $provider;

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

    // An admin user so node view access passes during the test.
    $this->setUpCurrentUser([], [], TRUE);

    foreach (['A', 'B', 'C'] as $name) {
      $term = Term::create(['vid' => 'tags', 'name' => $name]);
      $term->save();
      $this->terms[$name] = (int) $term->id();
    }

    $this->provider = $this->container->get('signal_recommendations.provider');
  }

  /**
   * More shared tags rank higher; unrelated articles and self are excluded.
   */
  public function testRanksBySharedTagsAndExcludesUnrelatedAndSelf(): void {
    $source = $this->createArticle('Source', ['A', 'B']);
    $this->createArticle('Shares two', ['A', 'B']);
    $this->createArticle('Shares one', ['A']);
    $this->createArticle('Shares none', ['C']);

    $titles = $this->titles($this->provider->getRecommendations($source));

    $this->assertSame(['Shares two', 'Shares one'], $titles);
  }

  /**
   * Unpublished candidates are never recommended.
   */
  public function testExcludesUnpublished(): void {
    $source = $this->createArticle('Source', ['A', 'B']);
    $this->createArticle('Unpublished', ['A', 'B'], published: FALSE);

    $this->assertSame([], $this->provider->getRecommendations($source));
  }

  /**
   * The min_shared_tags setting filters out weak matches.
   */
  public function testRespectsMinSharedTags(): void {
    $source = $this->createArticle('Source', ['A', 'B']);
    $this->createArticle('Shares two', ['A', 'B']);
    $this->createArticle('Shares one', ['A']);

    $this->config('signal_recommendations.settings')->set('min_shared_tags', 2)->save();

    $this->assertSame(['Shares two'], $this->titles($this->provider->getRecommendations($source)));
  }

  /**
   * The result set is capped at the requested limit.
   */
  public function testRespectsLimit(): void {
    $source = $this->createArticle('Source', ['A', 'B']);
    for ($i = 0; $i < 6; $i++) {
      $this->createArticle("Candidate $i", ['A']);
    }

    $this->assertCount(3, $this->provider->getRecommendations($source, 3));
  }

  /**
   * An article with no tags has nothing to match against.
   */
  public function testReturnsEmptyWhenSourceHasNoTags(): void {
    $source = $this->createArticle('No tags', []);

    $this->assertSame([], $this->provider->getRecommendations($source));
  }

  /**
   * Creates a published-by-default article with the given tags.
   *
   * @param string $title
   *   The article title.
   * @param string[] $tag_names
   *   Tag names to attach; must exist in $this->terms.
   * @param bool $published
   *   Whether the article is published.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  private function createArticle(string $title, array $tag_names, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'status' => $published,
      'field_tags' => array_map(fn (string $name): int => $this->terms[$name], $tag_names),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Extracts node titles in order.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   The nodes.
   *
   * @return string[]
   *   The titles, in the same order.
   */
  private function titles(array $nodes): array {
    return array_map(static fn (NodeInterface $node): string => $node->getTitle(), $nodes);
  }

}
