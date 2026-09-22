<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Smoke: the recipe-installed site serves its editor through a real browser.
 *
 * Installs Drupal FROM the daedalus recipe (no install profile), the exact
 * contract `drush si recipes/daedalus_recipe` gives a new site, and proves the
 * result answers an authenticated browser request. That is all the recipe's
 * own browser test asserts, on purpose: the recipe ships config and content,
 * no wiring of its own, and the page builder's wiring is tested where it is
 * owned. Every page-builder module's FunctionalJavascript test installs this
 * same recipe through daedalus's DaedalusFixtureTrait (the ONE copy
 * of the fixture, so a recipe change is absorbed there once) and runs as
 * daedalus_editor, so the union of the module tests is the integration
 * net for the composition this recipe ships: Edit Mode entry in
 * daedalus_ui, placement in daedalus_layout, inline editing in daedalus_edit, and
 * so on. A change here that breaks one of them fails that module's test on
 * this install.
 *
 * This is intentionally a single self-contained class. Recipe projects live
 * in recipes/, which Drupal's PHPUnit bootstrap does not scan for test
 * namespaces, so PHPUnit discovers this class only by including the *Test.php
 * file directly (via --directory). A separate, autoload-dependent base class
 * would not load. (Traits from modules are fine: module test namespaces are
 * autoloaded.)
 *
 * Applicability to an EXISTING standard site, and the convergence of every
 * ingredient on re-apply, are server contracts, not browser facts;
 * RecipeRetrySafetyTest covers them at the Functional tier.
 *
 * @group daedalus_recipe
 */
#[Group('daedalus_recipe')]
#[RunTestsInSeparateProcesses]
class DaedalusBehaviorTest extends WebDriverTestBase {

  use DaedalusFixtureTrait;

  /**
   * The installed site serves the editor's own page.
   */
  public function testInstalledSiteServesEditor(): void {
    // The editor persona: ONLY the recipe-shipped role, no admin bypass.
    $account = $this->loginAsEditor();
    $this->drupalGet('user');
    $this->assertSession()->pageTextContains($account->getAccountName());
  }

}
