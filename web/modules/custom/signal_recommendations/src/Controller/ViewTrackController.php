<?php

declare(strict_types=1);

namespace Drupal\signal_recommendations\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\signal_recommendations\ViewCountStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint for the client-side view-tracking beacon.
 *
 * Access (node view) and CSRF protection are enforced by the route; this
 * controller only records the view and returns an empty 204 response so the
 * beacon stays lightweight.
 */
final class ViewTrackController extends ControllerBase {

  public function __construct(
    private readonly ViewCountStorageInterface $viewCountStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('signal_recommendations.view_count_storage'),
    );
  }

  /**
   * Records a view of the given node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being viewed, resolved and access-checked by the router.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An empty, uncacheable 204 response.
   */
  public function track(NodeInterface $node): Response {
    $this->viewCountStorage->recordView((int) $node->id());
    return new Response('', Response::HTTP_NO_CONTENT);
  }

}
