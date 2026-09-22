<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the layout_block placement exclusion the recipes ship.
 *
 * The layout_block type is a theme-region block (headers/footers) and is never
 * placeable on pages; the daedalus_page ingredient and composing
 * recipes' page ingredients exclude it with a layout_builder_restrictions
 * denylist entry on the LB-enabled node view display. This test drives
 * the exact config action those recipes ship (the module's own
 * entityViewModeRestrictionAppendRestrictedBlocks) against a real
 * layout_builder_restrictions install on the real daedalus_layout storage stack,
 * and pins the payoff: inline_block:layout_block no longer reaches
 * available_block_types in the node blueprint schema (which also removes
 * its ~20KB nested style-guide duplicate from the AI prompt), while other
 * block types still do.
 *
 * This is intentionally a single self-contained class. Recipe projects live
 * in recipes/, which Drupal's PHPUnit bootstrap does not scan for test
 * namespaces, so PHPUnit discovers this class only by including the
 * *Test.php file directly (via --directory). See DaedalusBehaviorTest for
 * the same constraint.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_recipe')]
class LayoutBlockPlacementExclusionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * Kernel tests do not resolve info.yml dependencies, so the
   * daedalus_ui/daedalus_layout chain is listed explicitly (same set as
   * daedalus_layout's own kernel base): the point of this pin is that the real
   * layout_builder_restrictions module filters through daedalus_layout's
   * nested-aware section storages, not a stand-in provider.
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'field',
    'text',
    'filter',
    'node',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    'layout_builder_restrictions',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installConfig(['filter', 'text', 'block_content']);
    $this->installSchema('layout_builder', ['inline_block_usage']);

    // The schema is access-filtered per user; run as an admin so the test
    // verifies shape, not access.
    $this->setUpCurrentUser([], [], TRUE);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $block_type_storage = $this->container->get('entity_type.manager')->getStorage('block_content_type');
    $block_type_storage->create(['id' => 'layout_block', 'label' => 'Layout block', 'revision' => TRUE])->save();
    $block_type_storage->create(['id' => 'text', 'label' => 'Text', 'revision' => TRUE])->save();

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()
      ->setOverridable()
      ->save();
  }

  /**
   * The shipped denylist removes layout_block from available_block_types.
   */
  public function testLayoutBlockExcludedFromNodeSchema(): void {
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');

    // Unrestricted, layout_block reaches the node schema like any other
    // block type — the exclusion below is what removes it, not an accident
    // of the fixture.
    $block_types = $schema_builder->getSchema('node', 'page')['layout_builder__layout']['available_block_types'];
    $this->assertArrayHasKey('layout_block', $block_types);
    $this->assertArrayHasKey('text', $block_types);

    // The exact action daedalus_page and composing recipes' page
    // ingredients ship against their LB-enabled node view displays.
    $this->container->get('plugin.manager.config_action')->applyAction(
      'entityViewModeRestrictionAppendRestrictedBlocks',
      'core.entity_view_display.node.page.default',
      ['blocks' => ['Inline blocks' => ['inline_block:layout_block']]],
    );

    $block_types = $schema_builder->getSchema('node', 'page')['layout_builder__layout']['available_block_types'];
    $this->assertArrayNotHasKey('layout_block', $block_types, 'layout_block no longer reaches available_block_types in the node schema.');
    $this->assertArrayHasKey('text', $block_types, 'Other block types are untouched by the denylist.');
  }

}
