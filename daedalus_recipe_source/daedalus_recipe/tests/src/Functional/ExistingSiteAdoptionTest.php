<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe\Functional;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the ingredients adopt Daedalus onto a site that already exists.
 *
 * The fresh-install tests (DaedalusBehaviorTest, RecipeRetrySafetyTest)
 * start from nothing or from a bare profile, so they can never see what
 * an established site collides with: a page type the site already owns,
 * a Layout Builder storage core created itself (on a non-English site,
 * with the site's langcode and a translated label), content in a text
 * format the site chose, an admin sidebar the site rearranged, and a
 * theme the site keeps. This class builds that site first and then
 * applies the ingredients the way EXISTING_SITES.md prescribes: never
 * the umbrella, never daedalus_themes, the engine first and the roles
 * last.
 *
 * The proxy for "any site" is core's standard profile plus core's own
 * page_content_type recipe (on core 11.3 standard still ships the page
 * type itself, so the recipe converges as a no-op there; on 11.4 it is
 * where the page type comes from), with Layout Builder enabled on the
 * page display through the entity API exactly as the Manage display
 * screen does it.
 *
 * This is intentionally a single self-contained class: recipe projects
 * live in recipes/, which Drupal's PHPUnit bootstrap does not scan for
 * test namespaces, so PHPUnit discovers this class only by including
 * the *Test.php file directly. See RecipeRetrySafetyTest for the same
 * constraint.
 */
#[Group('daedalus_recipe')]
#[RunTestsInSeparateProcesses]
class ExistingSiteAdoptionTest extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * The body an existing site's page carries, in the format it chose.
   *
   * Every tag here is one the identity minter addresses (its vocabulary)
   * and one basic_html allows WITHOUT an unrestricted class attribute:
   * the exact combination that used to make the node unsavable the
   * moment daedalus_edit was installed.
   */
  private const BODY = '<p>An <strong>existing</strong> page.</p><h2>Heading</h2><ul><li>One</li><li>Two</li></ul><blockquote><p>Quoted</p></blockquote>';

  /**
   * The Layout Builder field label a French site's core created.
   */
  private const FRENCH_LAYOUT_LABEL = 'Mise en page';

  /**
   * {@inheritdoc}
   *
   * Layout Builder, because an existing site that adopts Daedalus already
   * runs it on a bundle (that is what creates the storage the strict
   * compare used to refuse); navigation, because the guide requires it
   * and core 11.3's standard profile does not install it (11.4's does,
   * and listing it again is a no-op there).
   */
  protected static $modules = ['layout_builder', 'navigation'];

  /**
   * {@inheritdoc}
   *
   * The standard profile's own default. The adoption path must leave the
   * site's theme alone, and only a site that starts on olivero can prove
   * it still runs olivero afterwards.
   */
  protected $defaultTheme = 'olivero';

  /**
   * {@inheritdoc}
   *
   * The existing site: an established install with content types, text
   * formats and media, not a bare no-profile install.
   */
  protected $profile = 'standard';

  /**
   * The existing page node's ID.
   */
  private int $nodeId;

  /**
   * The UUID of the navigation component whose weight the site changed.
   */
  private string $touchedComponent;

  /**
   * The weight the site gave that component.
   */
  private int $touchedWeight;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The page type, from core's own recipe: on 11.4 this is where the
    // Basic page comes from; on 11.3 standard already ships it and the
    // recipe's strict compare passes over the identical objects.
    $this->applyRecipe(static::getDrupalRoot() . '/core/recipes/page_content_type');

    // Layout Builder with overrides on the page display, through the
    // entity API, so core creates field.storage.node.layout_builder__layout
    // and its field itself, as it does on any site that flipped the
    // switch on Manage display.
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $display->enableLayoutBuilder()->setOverridable()->save();

    // A French site: core stamps the storage and field with the site's
    // default language and the field's label is translated. The storage
    // shape is core's and cannot differ; langcode and label can, and a
    // strict compare counts both.
    FieldStorageConfig::loadByName('node', 'layout_builder__layout')
      ->set('langcode', 'fr')
      ->save();
    FieldConfig::loadByName('node', 'page', 'layout_builder__layout')
      ->set('langcode', 'fr')
      ->set('label', self::FRENCH_LAYOUT_LABEL)
      ->save();

    // Content in the format the site chose.
    $node = Node::create([
      'type' => 'page',
      'title' => 'An existing page',
      'body' => ['value' => self::BODY, 'format' => 'basic_html'],
    ]);
    $node->save();
    $this->nodeId = (int) $node->id();

    // An admin sidebar the site rearranged: one component's weight moved.
    $layout = $this->config('navigation.block_layout');
    $components = $layout->get('sections.0.components');
    $this->assertNotEmpty($components, 'navigation ships a block layout with components.');
    $this->touchedComponent = (string) array_key_first($components);
    $this->touchedWeight = (int) $components[$this->touchedComponent]['weight'] + 10;
    $layout->set("sections.0.components.{$this->touchedComponent}.weight", $this->touchedWeight)->save();
  }

  /**
   * Applies the guide's ingredient ladder and checks what it converged.
   */
  public function testAdoption(): void {
    $ingredients = dirname(__DIR__, 3) . '/ingredients';

    // Guide order: the engine, the page (which pulls styling, the block
    // types and image media), the roles last, once every bundle they
    // grant on exists.
    $this->applyRecipe($ingredients . '/daedalus_engine');
    $this->applyRecipe($ingredients . '/daedalus_page');
    $this->applyRecipe($ingredients . '/daedalus_editor_roles');

    // The page takeover: the bundle is renamed and the display converges
    // to the builder shape, while the site's own body field stays.
    $this->assertSame('Page', NodeType::load('page')->label(), "daedalus_page took the page type over (renamed 'Basic page' to 'Page').");
    $this->assertNotNull(FieldConfig::loadByName('node', 'page', 'body'), 'The takeover kept the body field the site owns.');
    $display = EntityViewDisplay::load('node.page.default');
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $this->assertTrue($display->getThirdPartySetting('layout_builder', 'enabled'), 'The page display runs Layout Builder.');
    $this->assertTrue($display->isOverridable(), 'The page display allows per-node overrides.');
    $this->assertSame([
      'inline_block:image' => 'inline_block:image',
      'inline_block:formatted_text' => 'inline_block:formatted_text',
      'inline_block:text' => 'inline_block:text',
    ], $display->getThirdPartySetting('daedalus_layout', 'promoted_blocks'), "daedalus_page's display action promoted the three block types on the existing display.");

    // The Layout Builder storage core created on the French site was
    // neither refused nor rewritten: langcode and label are the site's.
    $storage = FieldStorageConfig::loadByName('node', 'layout_builder__layout');
    $this->assertSame('fr', $storage->get('langcode'), 'The site-created layout storage kept its langcode.');
    $field = FieldConfig::loadByName('node', 'page', 'layout_builder__layout');
    $this->assertSame('fr', $field->get('langcode'), 'The site-created layout field kept its langcode.');
    $this->assertSame(self::FRENCH_LAYOUT_LABEL, $field->label(), 'The site-created layout field kept its translated label.');

    // basic_html content still saves with daedalus_edit installed: the
    // identity minter skips the vocabulary tags the format allows
    // without an unrestricted class instead of throwing on them.
    $node = Node::load($this->nodeId);
    $this->assertNotNull($node);
    $node->setTitle('An existing page, re-saved');
    $node->save();
    $node = Node::load($this->nodeId);
    $this->assertSame('An existing page, re-saved', $node->label(), 'The basic_html page re-saved after adoption.');
    $this->assertSame(self::BODY, $node->get('body')->value, 'The re-save left the basic_html body as the site wrote it.');

    // No theme takeover on the existing-site path (daedalus_themes is
    // never applied), and the sidebar the site rearranged is intact.
    $this->assertSame('olivero', $this->config('system.theme')->get('default'), 'The site keeps its own default theme.');
    $this->assertSame($this->touchedWeight, $this->config('navigation.block_layout')->get("sections.0.components.{$this->touchedComponent}.weight"), 'The navigation layout the site touched is intact.');

    // The editor role exists with the grants its bundles provide.
    $role = Role::load('daedalus_editor');
    $this->assertNotNull($role, 'daedalus_editor_roles created the Daedalus Editor role.');
    $this->assertTrue($role->hasPermission('use daedalus ui edit mode'));
    $this->assertTrue($role->hasPermission('create page content'));
    $this->assertTrue($role->hasPermission('create text block content'));
  }

  /**
   * Applies the styling ingredient and checks the shipped definitions landed.
   *
   * The styling modules import their shipped skins, animations and
   * variants at module install; inside core's RecipeRunner that install
   * runs with config syncing on and a different account context than a
   * plain `drush en`, and every import used to fail there with an access
   * denial logged and no skin on the site.
   */
  public function testStylingShipsUnderTheRunner(): void {
    $this->applyRecipe(dirname(__DIR__, 3) . '/ingredients/daedalus_styling');

    $this->assertSame([], $this->accessDeniedLogEntries(), 'The shipped definitions import under the recipe runner logged no access denial.');

    $entity_type_manager = $this->container->get('entity_type.manager');
    // Skins are content entities; the shipped file's key is the machine
    // name, not the serial ID.
    $this->assertCount(1, $entity_type_manager->getStorage('daedalus_skin')->loadByProperties(['machine_name' => 'olivero']), 'The Olivero skin the ingredient wears exists.');
    // Every variant the ingredient's modules ship: daedalus_stylish's four
    // block motion variants and daedalus_stylish_layouts' twelve (row,
    // seven containers, four container motions). The text block's
    // `button` variant arrives with daedalus_text_block, not here.
    $this->assertCount(16, $entity_type_manager->getStorage('daedalus_variant')->loadMultiple(), 'Every shipped variant of the styling ingredient landed.');
  }

  /**
   * Lists every logged error that carries an access-denied code.
   *
   * Read from the site's log (dblog, which the standard profile installs)
   * rather than the recipe process output: the import reports its
   * failures through the logger, and the runner exits 0 regardless.
   *
   * @return string[]
   *   One rendered line per matching log entry.
   */
  private function accessDeniedLogEntries(): array {
    $rows = $this->container->get('database')
      ->select('watchdog', 'w')
      ->fields('w', ['type', 'message', 'variables'])
      ->condition('severity', RfcLogLevel::ERROR, '<=')
      ->orderBy('wid')
      ->execute()
      ->fetchAll();
    $entries = [];
    foreach ($rows as $row) {
      $variables = unserialize($row->variables, ['allowed_classes' => FALSE]);
      $text = strtr($row->message, is_array($variables) ? array_map('strval', $variables) : []);
      if (str_contains($text, 'ACCESS_DENIED')) {
        $entries[] = "[{$row->type}] $text";
      }
    }
    return $entries;
  }

}
