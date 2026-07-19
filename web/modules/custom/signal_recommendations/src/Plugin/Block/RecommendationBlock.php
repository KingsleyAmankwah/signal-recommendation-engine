<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\signal_recommendations\Recommendation\RecommendationProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays articles related to the article being viewed.
 *
 * A context-aware block: it declares an entity:node context so core supplies
 * the current node on article routes and the block is simply unavailable
 * elsewhere. Rendering degrades gracefully — a failure in the recommendation
 * query is logged and yields an empty (but correctly cached) block rather than
 * a broken page.
 *
 * Cache strategy (the crux of the feature):
 *  - Cache tags invalidate the rail precisely when a relevant node changes:
 *    the current article, any recommended article, or the set of articles
 *    (node_list:article) so newly published content can appear.
 *  - Cache contexts vary the rail by route (which article) and by the viewer's
 *    node-view grants (results are access-filtered).
 *  - A bounded max-age folds in the continuously-drifting view-count signal
 *    without invalidating on every view, which would defeat the page cache.
 */
#[Block(
  id: 'signal_recommendations_rail',
  admin_label: new TranslatableMarkup('Recommendation rail'),
  category: new TranslatableMarkup('Signal'),
  context_definitions: [
    'node' => new EntityContextDefinition(
      data_type: 'entity:node',
      label: new TranslatableMarkup('Current article'),
      required: TRUE,
    ),
  ],
)]
final class RecommendationBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  /**
   * Constructs a RecommendationBlock.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\signal_recommendations\Recommendation\RecommendationProviderInterface $recommendationProvider
   *   The recommendation provider.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to render recommended nodes.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for the cache lifetime.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module's logger channel.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly RecommendationProviderInterface $recommendationProvider,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('signal_recommendations.provider'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('logger.channel.signal_recommendations'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array
  {
    return [
      'heading' => 'Related articles',
      'items_limit' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array
  {
    $form['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Heading'),
      '#default_value' => $this->configuration['heading'],
      '#required' => TRUE,
    ];
    $form['items_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of recommendations'),
      '#min' => 0,
      '#default_value' => $this->configuration['items_limit'],
      '#description' => $this->t('Set to 0 to use the site-wide default.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void
  {
    $this->configuration['heading'] = $form_state->getValue('heading');
    $this->configuration['items_limit'] = (int) $form_state->getValue('items_limit');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array
  {
    $cache = new CacheableMetadata();
    // The rail varies per article and by the viewer's node-view access, and is
    // refreshed on a timer so the volatile view-count signal never triggers an
    // invalidation. New or edited articles still invalidate via cache tags.
    $cache->addCacheContexts(['route', 'user.node_grants:view']);
    $cache->addCacheTags(['node_list:article']);
    $max_age = (int) $this->configFactory->get('signal_recommendations.settings')->get('cache_max_age');
    $cache->setCacheMaxAge($max_age > 0 ? $max_age : Cache::PERMANENT);

    $build = [];
    $node = $this->getContextValue('node');
    if ($node instanceof NodeInterface && $node->bundle() === 'article') {
      // The current article's tags drive the results, so its changes matter.
      $cache->addCacheableDependency($node);
      $limit = $this->configuration['items_limit'] > 0 ? $this->configuration['items_limit'] : NULL;

      $recommendations = $this->getRecommendations($node, $limit);
      if ($recommendations !== []) {
        $view_builder = $this->entityTypeManager->getViewBuilder('node');
        $items = [];
        foreach ($recommendations as $recommendation) {
          $cache->addCacheableDependency($recommendation);
          $items[] = $view_builder->view($recommendation, 'teaser');
        }
        $build = [
          '#type' => 'component',
          '#component' => 'signal_theme:recommendation-rail',
          '#props' => ['heading' => $this->configuration['heading']],
          '#slots' => ['items' => $items],
        ];
      }
    }

    $cache->applyTo($build);
    return $build;
  }

  /**
   * Fetches recommendations, failing safe if the query errors.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The article to recommend against.
   * @param int|null $limit
   *   Maximum recommendations, or NULL for the configured default.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Recommended nodes, or an empty array on failure.
   */
  private function getRecommendations(NodeInterface $node, ?int $limit): array
  {
    try {
      return $this->recommendationProvider->getRecommendations($node, $limit);
    } catch (\Throwable $e) {
      $this->logger->error('Unable to build recommendations for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }
}
