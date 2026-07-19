<?php

/**
 * @file
 * Development seed script: creates sample Tags and Article content.
 *
 * Generates a small, cross-tagged corpus so the recommendation engine has
 * something to score, plus GD-generated placeholder featured images and
 * staggered publication dates (recency is a scoring signal). Safe to re-run:
 * articles are keyed by title and skipped if they already exist.
 *
 * Usage:
 *   ddev drush php:script scripts/seed_content.php
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Returns the term id for a tag name, creating the term if needed.
 */
$get_term = static function (string $name) use (&$term_cache): int {
  if (isset($term_cache[$name])) {
    return $term_cache[$name];
  }
  $existing = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties(['vid' => 'tags', 'name' => $name]);
  $term = $existing ? reset($existing) : Term::create(['vid' => 'tags', 'name' => $name]);
  if ($term->isNew()) {
    $term->save();
  }
  return $term_cache[$name] = (int) $term->id();
};

/**
 * Generates a placeholder PNG and returns a saved file entity id, or NULL.
 */
$make_image = static function (string $title, string $slug, array $rgb): ?int {
  if (!function_exists('imagecreatetruecolor')) {
    return NULL;
  }
  $width = 1200;
  $height = 675;
  $img = imagecreatetruecolor($width, $height);

  // Vertical gradient from the topic colour to a darker shade.
  [$r, $g, $b] = $rgb;
  for ($y = 0; $y < $height; $y++) {
    $factor = 1 - ($y / $height) * 0.55;
    $line = imagecolorallocate(
      $img,
      (int) ($r * $factor),
      (int) ($g * $factor),
      (int) ($b * $factor),
    );
    imagefilledrectangle($img, 0, $y, $width, $y, $line);
  }

  // Title text, wrapped, using the built-in bitmap font.
  $white = imagecolorallocate($img, 255, 255, 255);
  $lines = str_split($title, 34);
  $font = 5;
  $line_height = imagefontheight($font) * 2;
  $y = (int) ($height / 2 - (count($lines) * $line_height) / 2);
  foreach ($lines as $line) {
    imagestring($img, $font, 60, $y, $line, $white);
    $y += $line_height;
  }

  ob_start();
  imagepng($img);
  $data = ob_get_clean();
  imagedestroy($img);

  $dir = 'public://articles';
  \Drupal::service('file_system')->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
  $file = \Drupal::service('file.repository')
    ->writeData($data, $dir . '/seed-' . $slug . '.png', FileExists::Replace);

  return (int) $file->id();
};

// Topic palette used for placeholder images.
$palette = [
  'Drupal' => [37, 69, 211],
  'PHP' => [79, 91, 147],
  'DevOps' => [16, 122, 87],
  'Frontend' => [201, 76, 76],
  'Accessibility' => [138, 79, 175],
  'Performance' => [201, 141, 40],
];

// The seed corpus. `days_ago` staggers created dates for the recency signal.
$articles = [
  [
    'title' => 'Getting Started with Drupal 11 Recipes',
    'tags' => ['Drupal', 'DevOps'],
    'days_ago' => 2,
    'body' => 'Recipes are Drupal 11\'s answer to reproducible site building. Instead of clicking a content model together in the UI, you describe it as configuration and apply it on any environment. This article walks through authoring a recipe, from the recipe.yml manifest to the exported config it ships. We also cover idempotency: why a recipe must match existing config exactly, and how the apply step validates dependencies before writing anything.',
  ],
  [
    'title' => 'Dependency Injection in Drupal Plugins',
    'tags' => ['Drupal', 'PHP'],
    'days_ago' => 5,
    'body' => 'Static \\Drupal:: calls are convenient and untestable. This piece shows how to inject services into plugins through the container: implement ContainerFactoryPluginInterface, pull your dependencies in the static create() factory, and assign them to typed constructor-promoted properties. The payoff is unit-testable classes and a clear, honest list of what each plugin actually depends on.',
  ],
  [
    'title' => 'Modern PHP 8.3 Features Every Developer Should Know',
    'tags' => ['PHP'],
    'days_ago' => 9,
    'body' => 'Typed class constants, readonly properties, first-class callable syntax, and the json_validate() function: PHP 8.3 continues a steady march toward safer, more expressive code. We look at each feature with small before-and-after examples and note where they change how you model immutable value objects and configuration.',
  ],
  [
    'title' => 'Building Themes with Single Directory Components',
    'tags' => ['Drupal', 'Frontend'],
    'days_ago' => 12,
    'body' => 'Single Directory Components co-locate a component\'s schema, template, styles, and behaviour in one folder. This article builds a card component from scratch: a typed prop-and-slot schema, logical-property CSS for RTL safety, and slot conventions that work with both the #slots render property and Twig embed overrides. No base theme required.',
  ],
  [
    'title' => 'Accessible Color Systems and Logical CSS',
    'tags' => ['Frontend', 'Accessibility'],
    'days_ago' => 16,
    'body' => 'A design token system is only as good as its contrast ratios. We build a palette that passes WCAG AA in both light and dark modes, driven entirely by CSS custom properties, and lean on logical properties like margin-inline and inset so layouts mirror correctly for right-to-left languages without a second stylesheet.',
  ],
  [
    'title' => 'Caching Strategies for High-Traffic Drupal Sites',
    'tags' => ['Drupal', 'Performance'],
    'days_ago' => 21,
    'body' => 'Cache tags, contexts, and max-age are the three levers of Drupal\'s render cache. This article explains when to reach for each: tags for discrete content changes, contexts for per-request variation, and max-age for signals that drift continuously, like a view counter. Get them right and you invalidate precisely instead of clearing the whole page.',
  ],
  [
    'title' => 'Automating Deployments with DDEV and CI',
    'tags' => ['DevOps', 'Performance'],
    'days_ago' => 27,
    'body' => 'A reproducible local environment is the first half of a reliable pipeline. We wire DDEV into CI so the same PHP version, database, and Composer state run everywhere, then layer in config import, database updates, and a cache rebuild as ordered, fail-fast steps.',
  ],
  [
    'title' => 'Writing Kernel Tests for Custom Services',
    'tags' => ['Drupal', 'PHP', 'DevOps'],
    'days_ago' => 34,
    'body' => 'Unit tests are fast but blind to the container; Kernel tests boot just enough of Drupal to exercise real services against a real database. This article sets up a KernelTestBase, enables the modules under test, installs entity schema, and asserts against a service\'s behaviour, striking the balance between speed and realism.',
  ],
];

$now = time();
$created = 0;
$skipped = 0;

foreach ($articles as $data) {
  $existing = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['type' => 'article', 'title' => $data['title']]);
  if ($existing) {
    $skipped++;
    continue;
  }

  $tids = array_map($get_term, $data['tags']);
  $primary = $data['tags'][0];
  $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $data['title']));
  $fid = $make_image($data['title'], $slug, $palette[$primary]);

  $values = [
    'type' => 'article',
    'title' => $data['title'],
    'uid' => 1,
    'status' => 1,
    'promote' => 1,
    'created' => $now - ($data['days_ago'] * 86400),
    'body' => ['value' => '<p>' . $data['body'] . '</p>', 'format' => 'basic_html'],
    'field_tags' => $tids,
  ];
  if ($fid !== NULL) {
    $values['field_featured_image'] = ['target_id' => $fid, 'alt' => $data['title']];
  }

  Node::create($values)->save();
  $created++;
}

echo sprintf("Seed complete: %d created, %d skipped (already present).\n", $created, $skipped);
