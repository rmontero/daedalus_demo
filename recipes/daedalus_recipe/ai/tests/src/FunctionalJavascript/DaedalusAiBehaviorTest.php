<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe_ai\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the Daedalus AI recipe through a real browser.
 *
 * Applies the Daedalus AI umbrella recipe as the test fixture so the AI
 * editing layer is installed and configured exactly as a real site receives
 * it, then proves that layer is wired onto the page builder. It is the AI
 * counterpart to daedalus's DaedalusBehaviorTest: that test proves the page
 * builder works end to end, this one proves the AI superset assembles cleanly
 * on top of it.
 *
 * This is a wiring test, not an "the LLM answers" test. The recipe is publicly
 * installable and ships no API keys (the provider ingredients ship empty Keys),
 * so no real chat turn can run here and none is attempted: CI has no key and
 * hitting a paid provider from CI would be slow, flaky, and costly. Sending a
 * real message with a real key is a deliberate manual step (the recipe's
 * fresh-site smoke test), not part of this automated suite. What is automated
 * is everything that proves the recipe did its job: the modules install, the
 * agent/assistant/provider/key config lands correctly, and the AI editing UI
 * renders and opens in a real browser.
 *
 * This is intentionally a single self-contained class. Recipe projects live in
 * recipes/, which Drupal's PHPUnit bootstrap does not scan for test namespaces,
 * so PHPUnit discovers this class only by including the *Test.php file directly
 * (via --directory). A separate, autoload-dependent base class would not load.
 *
 * The single test method triggers a full, fresh site install plus the umbrella
 * recipe apply in setUp() (the framework installs per method and tears down per
 * method), which is by far the most expensive part of a run: the umbrella
 * installs the whole page builder plus the AI module stack and three providers.
 * Every behavior therefore shares that one fixture and runs as its own scenario
 * (distinct assertions with explicit messages) so the suite pays the install
 * cost once.
 *
 * @group daedalus_recipe
 */
#[RunTestsInSeparateProcesses]
class DaedalusAiBehaviorTest extends WebDriverTestBase {

  use RecipeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The AI layer is applied on top of the full Daedalus, which is designed
   * for a standard Drupal site (e.g. daedalus_edit relies on the standard node
   * Body field storage already existing). The 'standard' profile is the
   * faithful fixture, the same starting point a real site gives the recipe.
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   *
   * Inherited from the Daedalus base recipe: its config was exported from a
   * real site where Drupal coerces scalars at runtime, so a few values are
   * stored with a type that does not match their config schema. These are
   * harmless in production (the schema checker is off there) but abort recipe
   * application under the checker that tests enable by default. This is a
   * behavior test, not a config schema audit, so the checker is disabled here,
   * matching DaedalusBehaviorTest.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The AI site recipe's root is this file's third-level parent:
    // recipes/daedalus_recipe/ai/tests/src/FunctionalJavascript ->
    // recipes/daedalus_recipe/ai.
    // Apply the recipe.yml at that root, the exact composition a real
    // `drush recipe recipes/daedalus_recipe/ai` runs: the full Daedalus page
    // builder, the provider-neutral AI core, and the three provider layers.
    //
    // Unlike the base recipe's test, which applies a self-contained bundled
    // umbrella by bare-name sibling includes, this recipe includes the base
    // site recipe at the package root and the AI ingredients by paths that
    // resolve from the Drupal root (web/) to the project-root recipes/
    // directory where Composer installs the package, so applying the site
    // recipe is the only faithful fixture. (A successful apply here is itself
    // the first assertion: it proves the whole multi-ingredient composition
    // installs cleanly on a fresh standard site.)
    $this->applyRecipe(dirname(__DIR__, 3));
  }

  /**
   * The AI editing layer is installed, configured, and wired onto the builder.
   *
   * One fixture (the applied umbrella) drives every behavior the recipe is
   * responsible for:
   * - Composition: the umbrella applied in setUp() without error, so the full
   *   module stack is enabled (page builder + AI framework + providers).
   * - Config wiring: the recipe ships the agent + assistant and patches the
   *   surrounding settings, so the assistant follows the Anthropic chat
   *   default, image generation is wired to Gemini but shipped off, and each
   *   provider points at its (empty) Key.
   * - Chat UI: the always-on chat sidebar renders for a permitted user on any
   *   page (its floating toggle plus the <deep-chat> panel), independent of
   *   Edit Mode, and opens when driven through the sidebar manager.
   * - Cross-module integration: the daedalus_ai chat sidebar's toggle collects into
   *   daedalus_ui's Edit Mode top bar alongside the page builder's own
   *   panel toggles, the headline proof that the AI layer plugged into the
   *   builder rather than sitting beside it.
   */
  public function testDaedalusAiLayer(): void {
    $this->assertAiStackConfigured();
    $this->assertChatSidebarRendersAndOpens();
    $this->assertAiToggleInEditModeTopBar();
  }

  /**
   * Asserts the recipe installed the modules and wired the AI config.
   *
   * These are deterministic, API-level checks on the result of the recipe
   * apply: the part of the recipe's job that does not need a browser. They are
   * the core proof that the umbrella did what it claims, independent of any UI.
   */
  protected function assertAiStackConfigured(): void {
    // The full AI stack is enabled: the drupal/ai framework, the page-builder
    // AI layer, and the three provider modules (plus their Claude-specific
    // sub-modules). If the umbrella mis-ordered or omitted an install step this
    // is where it shows.
    $module_handler = \Drupal::moduleHandler();
    $expected_modules = [
      // AI framework.
      'ai', 'ai_assistant_api', 'ai_chatbot', 'ai_agents', 'key',
      // Page-builder AI layer.
      'daedalus_blueprint', 'daedalus_blueprint_ai', 'daedalus_ai_memory', 'daedalus_ai',
      // Providers + Claude-specific formatting.
      'ai_provider_anthropic', 'daedalus_ai_anthropic', 'daedalus_blueprint_ai_anthropic',
      'gemini_provider', 'daedalus_ai_gemini',
    ];
    foreach ($expected_modules as $module) {
      $this->assertTrue(
        $module_handler->moduleExists($module),
        "The recipe enabled the $module module.",
      );
    }

    $entity_type_manager = \Drupal::entityTypeManager();

    // The recipe ships the agent + assistant chain (the shipped modules do
    // not), both branded 'daedalus'.
    $this->assertNotNull(
      $entity_type_manager->getStorage('ai_assistant')->load('daedalus'),
      'The recipe created the daedalus AI assistant.',
    );
    $this->assertNotNull(
      $entity_type_manager->getStorage('ai_agent')->load('daedalus'),
      'The recipe created the daedalus AI agent.',
    );

    // daedalus_ai points its chat sidebar at our assistant, and image generation
    // is wired (see the Gemini default below) but shipped off until a key is
    // added.
    $daedalus_ai_settings = \Drupal::config('daedalus_ai.settings');
    $this->assertSame(
      'daedalus',
      $daedalus_ai_settings->get('assistant_id'),
      'daedalus_ai uses the daedalus assistant.',
    );
    $this->assertFalse(
      (bool) $daedalus_ai_settings->get('image_generation_enabled'),
      'Image generation ships off (no image key is assumed).',
    );

    // Agent memory persists state across turns for our agent.
    $this->assertArrayHasKey(
      'daedalus',
      \Drupal::config('daedalus_ai_memory.settings')->get('enabled_agents') ?? [],
      'Agent memory is enabled for the daedalus agent.',
    );

    // The assistant leaves llm_provider empty, so it follows the ai.settings
    // defaults: chat on Anthropic, image generation on Gemini.
    $ai_settings = \Drupal::config('ai.settings');
    $this->assertSame(
      'anthropic',
      $ai_settings->get('default_providers.chat.provider_id'),
      'The default chat provider is Anthropic.',
    );
    $this->assertSame(
      'anthropic',
      $ai_settings->get('default_providers.chat_with_tools.provider_id'),
      'The default chat-with-tools provider is Anthropic.',
    );
    $this->assertSame(
      'gemini',
      $ai_settings->get('default_providers.text_to_image.provider_id'),
      'The default text-to-image provider is Gemini.',
    );

    // Each provider points at its (initially empty) Key, and the Keys exist for
    // the site owner to paste a value into.
    $this->assertSame('anthropic', \Drupal::config('ai_provider_anthropic.settings')->get('api_key'));
    $this->assertSame('gemini', \Drupal::config('gemini_provider.settings')->get('api_key'));
    $key_storage = $entity_type_manager->getStorage('key');
    foreach (['anthropic', 'gemini'] as $key_id) {
      $this->assertNotNull(
        $key_storage->load($key_id),
        "The recipe shipped the empty $key_id Key for the site owner to fill in.",
      );
    }
  }

  /**
   * Asserts the always-on chat sidebar renders for a permitted user and opens.
   *
   * The chat sidebar is a no-mode daedalus_ui Sidebar gated only on the
   * 'use ai assistant' permission, so it exists on every page independent of
   * Edit Mode: a floating toggle button plus a panel holding the single
   * <deep-chat> element. An admin (granted that permission) loading their own
   * page must therefore receive both, server-rendered. The panel starts closed
   * (no open cookie), so it carries 'daedalus-ui-hidden'; driving the
   * sidebar manager's open() removes it. The manager is driven directly rather
   * than clicking the toggle, the deterministic equivalent of a user click and
   * the same pattern DaedalusBehaviorTest uses for the mode manager.
   */
  protected function assertChatSidebarRendersAndOpens(): void {
    $account = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($account);

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // Any front-end page proves the panel is always-on, not Edit-Mode-bound.
    $this->drupalGet('user');

    // The standalone floating toggle for the always-on chat sidebar.
    $assert_session->elementExists('css', '.daedalus-ui-sidebar-fabs #daedalus-ai-chat-toggle');

    // The chat panel and its single <deep-chat> element are server-rendered
    // into the right-sidebar wrapper (present in the DOM even while closed).
    $panel_selector = '#daedalus-ui-right-sidebar #daedalus_ai';
    $panel = $assert_session->elementExists('css', $panel_selector);
    $assert_session->elementExists('css', $panel_selector . ' deep-chat.deepchat-element');

    // Closed on a cold load: no open cookie, so the panel is hidden.
    $this->assertTrue(
      $panel->hasClass('daedalus-ui-hidden'),
      'The chat sidebar is closed on a cold load.',
    );

    // Drive the sidebar manager to open the panel (the deterministic equivalent
    // of clicking the floating toggle, which delegates to the same call). Wait
    // for the deferred ES module to register the manager first; its presence is
    // itself proof that daedalus_ui's front-end booted cleanly on a page
    // carrying the full AI JS stack.
    $session->wait(20000, "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.SidebarManager && typeof Drupal.Daedalus.SidebarManager.openSidebar === 'function'");
    $session->executeScript("Drupal.Daedalus.SidebarManager.openSidebar('daedalus_ai');");

    // Once open the panel sheds the hidden class.
    $this->assertJsCondition("!document.getElementById('daedalus_ai').classList.contains('daedalus-ui-hidden')", 20000);
  }

  /**
   * Asserts the AI chat toggle collects into the Edit Mode top bar.
   *
   * This is the cross-module integration the recipe exists to deliver: the
   * daedalus_ai chat panel is a no-mode daedalus_ui Sidebar plugin (the DaedalusAi
   * TOOL died with the selection-spine convergence), so in Edit Mode its
   * standalone floating launcher hides and the mode's top bar hosts its toggle
   * instead. A page arriving with the edit-mode cookie renders that top
   * bar server-side, the same cookie-arrival path DaedalusBehaviorTest uses to
   * prove daedalus_layout's Place Block sidebar registers; here we assert the daedalus_ai
   * toggle is collected into it too.
   */
  protected function assertAiToggleInEditModeTopBar(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();

    $node = Node::create([
      'type' => 'page',
      'title' => 'AI toggle top bar test page',
    ]);
    $node->save();

    // Arrive with the edit-mode cookie so daedalus_ui renders the Edit Mode
    // top bar (and its collected sidebar toggles) server-side.
    $session->setCookie('navigationMode', 'edit');
    $this->drupalGet($node->toUrl());

    // The daedalus_ai chat toggle is collected into the Edit Mode top bar's right
    // toggle group.
    $assert_session->elementExists('css', '#edit-mode-top-bar [data-sidebar-button-for="#daedalus_ai"]');
  }

}
