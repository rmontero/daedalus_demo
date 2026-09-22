<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe\Functional;

use Drupal\Core\Recipe\Recipe;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\layout_builder\Section;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves every Daedalus ingredient can be re-applied safely.
 *
 * Every ingredient is retry-safe by contract: a site must be able to
 * re-run one (after a mid-run failure, or on a site where it already
 * fully applied) without crashing or changing anything. This
 * test enforces the contract for the case that actually breaks in
 * practice: re-apply after complete success. Config that does not
 * survive a Drupal config save verbatim (recalculated dependency lists,
 * pruned langcode display components, normalized view role references)
 * crashes core's pre-existing-config equality check on re-apply unless
 * the ingredient's `config.strict` list is curated to exclude it — so
 * any newly shipped non-round-tripping config object fails here, in CI,
 * instead of on a user's site.
 *
 * The verification is behavioral and total: apply the umbrella and the
 * demo content fresh (the umbrella ships configuration only; every
 * shipped content entity is the opt-in demo content ingredient),
 * snapshot all active config AND every shipped content entity's stored
 * field values, re-apply every ingredient individually, and assert both
 * snapshots are byte-for-byte unchanged — re-applying a converged
 * ingredient must be a no-op. For content that means neither crashing
 * on an existing entity, duplicating it, nor overwriting its values.
 *
 * This is intentionally a single self-contained class: recipe projects
 * live in recipes/, which Drupal's PHPUnit bootstrap does not scan for
 * test namespaces, so PHPUnit discovers this class only by including
 * the *Test.php file directly (via --directory). See
 * DaedalusBehaviorTest for the same constraint.
 *
 * @group daedalus_recipe
 */
#[RunTestsInSeparateProcesses]
class RecipeRetrySafetyTest extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * Every ingredient, in apply order: the umbrella's, then demo content's.
   *
   * Kept explicit rather than globbed so a missing directory fails
   * loudly here instead of being silently skipped. Blueprints
   * (daedalus, daedalus_base) are excluded: they are composition
   * aliases whose parts are all covered individually below. Demo content
   * is listed because it carries content of its own (the Druplicon).
   */
  private const INGREDIENTS = [
    'daedalus_themes',
    'daedalus_sample_values',
    'daedalus_blueprint',
    'daedalus_engine',
    'daedalus_navigation',
    'daedalus_media_image',
    'daedalus_media_vector_type',
    'daedalus_styling',
    'daedalus_image_block',
    'daedalus_text_block',
    'daedalus_formatted_text_block',
    'daedalus_layout_block',
    'daedalus_page',
    'daedalus_editor_roles',
    'daedalus_header_block',
    'daedalus_footer_block',
    'daedalus_front_page',
    'daedalus_demo_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The EXISTING-SITE contract: fresh installs get the recipe with no
   * profile (`drush si <recipe>`, covered by DaedalusBehaviorTest's
   * fixture), but the suite must also apply — and re-apply — cleanly on a
   * site that already exists. 'standard' is the faithful stand-in for that
   * site: it ships the baseline (node Body field storage, text formats,
   * media) an established site carries, so applying the ingredients here
   * proves the convergence path, not the install path.
   */
  protected $profile = 'standard';

  /**
   * Applies the suite fresh, then re-applies every ingredient.
   */
  public function testEveryIngredientReappliesAsNoOp(): void {
    $ingredients_dir = dirname(__DIR__, 3) . '/ingredients';

    // Fresh convergence: the umbrella applies every product ingredient
    // once, in the proven order, then the demo content ingredient adds
    // the starter header, footer, welcome page and media, exactly as the
    // install script gives a new site the suite.
    $this->applyRecipe($ingredients_dir . '/daedalus');
    $this->applyRecipe($ingredients_dir . '/daedalus_demo_content');

    // The only place the recipe's system.theme action is provable: this
    // fixture installed with stark as the default, so daedalus_theme
    // being the default now can only have come from the action. The
    // fresh-install path cannot show it (the FJS fixture's $defaultTheme
    // is applied after the recipe and wins; see DaedalusBehaviorTest).
    $this->assertSame('daedalus_theme', $this->config('system.theme')->get('default'), "The umbrella's system.theme action made daedalus_theme the default theme.");

    $before = $this->activeConfig();
    $this->assertNotEmpty($before, 'The fresh apply produced active configuration.');
    $this->assertEmpty(
      $this->shippedContent([$ingredients_dir . '/daedalus']),
      'The umbrella ships configuration only; every content entity is demo content.'
    );
    $shipped = $this->shippedContent([
      $ingredients_dir . '/daedalus_demo_content',
    ]);
    $this->assertNotEmpty($shipped, 'The demo content graph ships content entities.');
    $content_before = $this->shippedContentSnapshot($shipped);

    foreach (self::INGREDIENTS as $ingredient) {
      $this->applyRecipe($ingredients_dir . '/' . $ingredient);
    }

    $this->assertSame(
      $before,
      $this->activeConfig(),
      'Re-applying every ingredient on a converged site left active configuration byte-for-byte unchanged.'
    );

    $this->assertSame(
      $content_before,
      $this->shippedContentSnapshot($shipped),
      'Re-applying the suite left every shipped content entity byte-for-byte unchanged.'
    );
  }

  /**
   * Lists every content entity the given recipes ship, walking includes.
   *
   * Read from the recipe graph itself rather than kept by hand, so shipped
   * content is covered the moment any ingredient (or a nested content
   * recipe) adds it. A hand-kept list had already drifted: the demo
   * media's file entity was missing from it.
   *
   * @param string[] $recipe_paths
   *   Recipe directories to walk, includes and all.
   *
   * @return array<string, string>
   *   Entity type IDs keyed by entity UUID.
   */
  private function shippedContent(array $recipe_paths): array {
    $shipped = [];
    $collect = function (Recipe $recipe) use (&$collect, &$shipped): void {
      foreach ($recipe->recipes->recipes as $included) {
        $collect($included);
      }
      foreach ($recipe->content->data as $uuid => $data) {
        $shipped[$uuid] = $data['_meta']['entity_type'];
      }
    };
    foreach ($recipe_paths as $path) {
      $collect(Recipe::createFromDirectory($path));
    }
    return $shipped;
  }

  /**
   * Snapshots every shipped content entity's stored field values.
   *
   * Loads each entity by UUID, asserting it exists exactly once, and
   * captures its full field data. Comparing two snapshots proves a
   * re-apply neither crashed on, duplicated, nor overwrote shipped
   * content; a count-only check would pass a re-apply that rewrote the
   * front page body or the footer copy.
   *
   * @param array<string, string> $shipped
   *   Entity type IDs keyed by UUID, from shippedContent().
   *
   * @return array<string, array<string, mixed>>
   *   Field values keyed by entity UUID.
   */
  private function shippedContentSnapshot(array $shipped): array {
    $snapshot = [];
    foreach ($shipped as $uuid => $entity_type) {
      $entities = \Drupal::entityTypeManager()->getStorage($entity_type)->loadByProperties(['uuid' => $uuid]);
      $this->assertCount(1, $entities, "The shipped $entity_type $uuid exists exactly once.");
      $values = reset($entities)->toArray();
      // Layout fields hydrate to Section objects, which can never be
      // identical across the environment rebuilds applyRecipe() forces;
      // compare their scalar storage representation instead.
      array_walk_recursive($values, function (mixed &$value): void {
        if ($value instanceof Section) {
          $value = $value->toArray();
        }
      });
      $snapshot[$uuid] = $values;
    }
    return $snapshot;
  }

  /**
   * Reads every active config object.
   *
   * @return array<string, mixed>
   *   All active configuration, keyed by object name.
   */
  private function activeConfig(): array {
    $storage = \Drupal::service('config.storage');
    $config = [];
    foreach ($storage->listAll() as $name) {
      $config[$name] = $storage->read($name);
    }
    return $config;
  }

}
