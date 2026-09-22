<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe_ai\Kernel;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\Edit;
use Drupal\daedalus_blueprint_ai_dev_tools\Plugin\AiProvider\PromptCaptureProvider;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Drift net: the assembled AI prompt surface against the live registries.
 *
 * The prompt the model actually receives is assembled from many hands: the
 * recipe's agent config (identity + the loop), the subscribers that append
 * the Selection Scope / Context Tags protocol, the page-language catalog,
 * and the skills obligation, the per-turn <system-reminder> injections
 * (skill catalog, current page print, ACTIVE SELECTION), and every tool
 * description. Each part has its own owner and its own tests; nothing else
 * proves the ASSEMBLED surface stays a projection of the live registries.
 * This test does, the same way the daedalus-blueprint:prompt-audit Drush
 * command does: it swaps daedalus_blueprint_ai_dev_tools' capture provider
 * in for the LLM, drives the genuine agent-runner pipeline (the exact path
 * AiAssistantApiRunner delegates to for agent assistants), and reads the
 * fully-assembled ChatInput back from the shared PromptCapture sink. No
 * LLM is called.
 *
 * A surface asserted against frozen strings (or against nothing) lets
 * drift through unseen, so the drift-net rule here is: every roster is
 * DERIVED from the registry that owns it, never hardcoded.
 * - Tools: the assembled roster is exactly the recipe agent's enabled
 *   tools mapped through the live function_calls plugin registry.
 * - Ops: eb_edit presents the non-page shape (root-field + config
 *   grammar) with every registered op page-only per the live registry,
 *   and the page language's op set is derived from DomOperationHandler
 *   and the registry's page-language ops.
 * - Skills: the first-turn catalog lists exactly the SkillsResolver's
 *   skills, and the skills the design system brief dissolved (styling,
 *   stencils, a package's doctrine skill) never register again.
 * - The page language: the cached system prompt's catalog section is
 *   checked against the generated DialectCatalog (block types, tokens, op
 *   set), so a catalog-builder regression or a subscriber that stops
 *   riding fails here.
 * - The design system brief: the styling half of the cached prefix is
 *   checked against the design_system entity (name, identity, law), the
 *   catalog (axes with their descriptions and owns, birth defaults, the
 *   skin, the dressed tags) and the materials roster (looks, a stencil's
 *   root kind and band qualifier, a swatch's description), so the model
 *   is introduced to the system the site actually runs and every line
 *   stays a projection.
 * The only frozen strings left are anchors from the recipe's own config
 * (which this test reads from disk, netting drift there too) and the
 * retired-vocabulary bans, which are tracers for text that must never come
 * back.
 *
 * THE CACHE CONTRACT: facts ride the cached prefix, the page rides the
 * turn. The generated catalog (the component reference, the design system
 * brief with its token roster, the vocabulary rosters and the op set)
 * sits in the SYSTEM prompt, the stable prefix the provider caches
 * (PageLanguageSubscriber appends it under "## Page language"), and the
 * current page's dialect print sits on the USER message
 * (CurrentPageMarkupContextSubscriber), never the other way around: a
 * page in the system prompt would break the cache on every edit, and a
 * catalog re-sent per turn would never be cached. Both directions are
 * asserted, plus a first-turn size budget, because the always-on surface
 * only pays for itself while it stays small enough for the small models.
 *
 * Block-type names ride the always-on surface on purpose: the component
 * reference in the cached prefix IS the bundle vocabulary, one tag per
 * product block type. What is banned is the retired vocabulary below.
 *
 * It lives in the daedalus_ai recipe because the assembled surface only
 * exists at the product level: the agent and assistant entities are
 * RECIPE config (modules do not ship them), so the test creates them from
 * the recipe's own config files. Kernel tests cannot apply recipes, so
 * the module set the product installs is enabled directly, INCLUDING the
 * page-builder chain and two real product block types
 * (daedalus_edit_text_block, daedalus_edit_formatted_text_block), the shipped
 * container variants, and a shipped skin, so the catalog, the op
 * roll-call, and the budget carry real product content.
 *
 * Discovery note: recipes/ is not scanned for test namespaces, so PHPUnit
 * finds this class only when pointed at the file or directory; keep it
 * self-contained (no sibling base class).
 *
 * @group daedalus_recipe
 */
#[Group('daedalus_recipe')]
#[RunTestsInSeparateProcesses]
class PromptSurfaceSnapshotTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The first-turn budget, in estimated tokens.
   *
   * The pin: the whole first turn (the system prompt, the rendered tool
   * definitions and the first user message with the page print, the
   * skill catalog and the selection) must estimate under this number for
   * the fixture's one-container page. Estimator: bytes / 4. Measured on
   * this surface against the Anthropic tokenizer the ratio runs 3.9-4.1
   * bytes/token, so /4 is slightly generous. The fixture carries two
   * product block types where a live site carries more, so a live first
   * turn runs a few hundred tokens above this measurement; the live
   * number belongs to the manual fresh-site smoke with a real key (see
   * DaedalusAiBehaviorTest), not to this test.
   *
   * The pin is a guardrail, not a target: it sits about 117 tokens above
   * the surface as measured here, so only real growth can cross it. When
   * a real capability lands on the always-on surface (a new fact in the
   * brief, a new grammar line in the catalog, a new tool), re-measure
   * (the failure message prints the estimate) and raise the pin by the
   * measured remainder; never shave unrelated teaching to fit the old
   * number, and never lower the pin for a retired line. An unexplained
   * rise means something is riding the first turn that should not: a
   * page or a schema in the cached prefix, a catalog copy on the user
   * message, a skill body inlined, a tool description grown by accident.
   *
   * What the surface pays for, and why it is worth the tokens: the
   * cached prefix teaches the whole design system brief (the system by
   * name, its identity, law and skin, every axis with its description
   * and owned properties, every value's first sentence, the birth line,
   * the stencils and their owned looks, the shipped animations, the
   * tokens and swatches, the dressed tags, the mint line), the stencil
   * placement contract, the responsive grammar (the width bands beside
   * their wrappers, one narrow example, both doors for conditioned
   * rules) and the per-kind vocabulary rosters by property name. Each
   * of those is a fact the model would otherwise learn by a failed write
   * and a corrective turn, which costs far more than the cached line
   * does. The olivero guide fragment is part of that cost: its
   * constraint_defaults bind font-size, font-weight, line-height and
   * every padding side to the skin's scales, so the brief prints a
   * bindings line per bound property and a description for each of the
   * text scale's named roles. The activation lands every enabled
   * module's shipped definitions the way a live install does, so the
   * fixture's brief prints daedalus_stylish's own shipped animations too.
   */
  protected const FIRST_TURN_TOKEN_BUDGET = 8664;

  /**
   * Vocabulary retired outright: nothing live spells any of these.
   *
   * Eras of retirement, each a tracer for a refactor that must not unwind: the
   * early component library's deleted block types and config-styling vocabulary
   * (title_size scales, layout column plugins), the doctrine chapter titles
   * that live in on-demand skill bodies and must never ride the assembled
   * prompt, the guidance refactor's retired tool names and ops (the five single
   * mutation tools, the batch_operations spelling, config_ai's
   * ManagePluginCollection, the positional-alias ops, and the
   * one-tool-per-response rule the eb_edit collapse obsoleted), the
   * identity-first image rethink's retired wire keys, the spine prose trim's
   * restated doctrine, the one-box container rethink's retired width-container
   * part class, the AI-runtime rework's retired styling blocking gate, and the
   * page-language port's retired markup-print header (the current page rides
   * as its dialect print now).
   */
  protected const RETIRED_VOCABULARY = [
    // Deleted block types + config-styling vocabulary.
    'title_size',
    'text-jumbo',
    'text-large',
    'daedalus_stylish_onecol',
    'daedalus_stylish_twocol',
    'daedalus_edit_cta_block',
    'daedalus_edit_header_block',
    'field_heading',
    'basic_block',
    'single-column',
    'multi-column',
    // On-demand doctrine chapters (skill bodies, never always-on).
    'Commit to a concept',
    'Build the arc from section moves',
    // The guidance refactor: retired tool names.
    'eb_update_element',
    'eb_add_element',
    'eb_remove_element',
    'eb_restructure_layout',
    'eb_reorder_field',
    'batch_operations',
    'ManagePluginCollection',
    'manage_plugin_collection',
    // The guidance refactor: retired ops and grammar.
    'add_component',
    'update_component',
    'remove_component',
    'update_fields',
    'change_section_layout',
    'section_uuid',
    'section_index',
    // The one-tool-per-response rule died with the tool collapse.
    'entity-modification',
    // The identity-first image rethink: the spine deferred-operation
    // channel and the placeholder-swap flow died. References are final at
    // compose time; only the pixels arrive later, as generation jobs
    // keyed by media uuid.
    'deferred_operations',
    'deferred_targets',
    'placeholder_media_id',
    // The spine prose trim (schema = facts, skills = mechanics): the
    // layout schema's nesting/doctrine paragraph and the materials
    // guidance's procedure sentences died where skills already state
    // them; their most distinctive spellings must not come back on any
    // tier.
    'Precedent, not the only bricks',
    'identical in shape and capability',
    // The one-box container rethink: the width-container part died (a
    // container is ONE element at every depth; width and layout write the
    // container's own key), so the part class must never come back on any
    // tier of the surface.
    'daedalus-stylish-layout-container',
    // The AI-runtime rework (facts cached, behavior on demand): the
    // "styling before any styling" blocking gate is gone (styling has no
    // gate and no skill of its own; the design system brief carries the
    // facts, see the block below), and the gate's spellings stay banned
    // as the older tracer.
    'Before any styling work',
    'about to change how anything looks',
    // The page-language port: the current page's per-turn shape is the
    // dialect print, not the key-annotated markup print, so the markup
    // print's header must not come back. (The markup projection itself
    // lives on as eb_read_entity's markup mode and the print-gap
    // fallback, whose header spells "Current page print".)
    'Current page markup',
    // No second grammar for pages: the page language is the ONE AI
    // grammar for pages, so the eb_edit page-op spellings are off the
    // always-on surface entirely. Their gestures live on as page-language
    // forms (style/set/class/detach, <pb-stencil>); the op names below
    // are page-only spine internals and must never be taught.
    'apply_variant',
    'detach_variant',
    'remove_variant',
    'update_style',
    'instantiate_stencil',
    // The quoting canon (dialect 0.4): single-quoted url('media:...') is
    // the one url() spelling on every AI-facing surface, because a double
    // quote ends a style="" attribute early and a model that has seen the
    // double-quoted form writes it there. The retired spelling must never
    // be taught, printed, or echoed on any tier (the &quot; form is the
    // printer's old escape).
    'url("media:',
    'url(&quot;media:',
    'url("generate:',
    'url(&quot;generate:',
    // Placement curates offers and birth, never legality: a root
    // container grants the whole Spacing group and a variant validates
    // wherever it is worn, so the catalog holds one roster per kind and
    // no depth face. The old depth delta line below must never return to
    // any tier of the surface.
    'nested containers',
    // The design system brief: the model is introduced to the design
    // system by name, and every fact about a material, an axis or a
    // composition lives on its record and prints from there. There is no
    // flat MATERIALS block, no ## Styling pointer, no styling, stencils
    // or package brand skill, so the old block's heading, the pointer's
    // heading, the styling skill's generated-law heading and its trigger
    // line, and the old mint framing (the gate is MaterialsProse's one
    // sentence) must never ride any tier. 'Commit to a concept' above
    // stays banned for the same reason it always was: the design skill's
    // doctrine is on-demand.
    'MATERIALS (',
    '## Styling',
    'What this site accepts',
    'a styling gesture needs a recipe beyond',
    'MINT only when the user asks',
  ];

  /**
   * {@inheritdoc}
   *
   * The module set the product installs: the daedalus_ai ingredient's own
   * stack plus the page builder chain the umbrella recipe brings via
   * daedalus (daedalus_layout, daedalus_edit, stencil, daedalus_stylish and their
   * dependencies), plus two real product block-type modules so the
   * generated catalog and the budget are exercised with product content.
   * daedalus_ai_memory is deliberately absent: it decorates the runner with
   * cross-turn thread persistence and adds nothing to the assembled
   * first-turn surface this test pins.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    // daedalus_edit_formatted_text_block's shipped editor config is a
    // ckeditor5 editor; its plugins must exist for the config to install.
    'ckeditor5',
    'file',
    'link',
    'image',
    'options',
    'views',
    'media',
    'media_library',
    'block',
    'block_content',
    'key',
    'ai',
    'ai_assistant_api',
    'ai_agents',
    'modeler_api',
    'ai_chatbot',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_ai_anthropic',
    'daedalus_blueprint_ai_dev_tools',
    'daedalus_blueprint_stencil',
    'daedalus_ui',
    'daedalus_layout',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_edit_text_block',
    'daedalus_edit_formatted_text_block',
    'daedalus_ai',
    'daedalus_ai_anthropic',
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'daedalus_stylish_olivero_skin',
    // Test fixture, not product: the routed "current page" whose metadata
    // reminder and dialect print the context subscribers inject.
    // entity_test keeps the routed entity independent of product bundles.
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build (the shipped-definitions sync below builds one).
    $this->installEntitySchema('daedalus_animation');
    // The materials roster's stencil provider queries stencil storage even
    // when no stencil exists.
    $this->installEntitySchema('stencil');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->setUpCurrentUser([], [], TRUE);

    // The product block types are real module config (block types +
    // fields), so the generated catalog derives real contracts.
    // daedalus_stylish_olivero_skin's install config is the olivero design system
    // the activation below names.
    $this->installConfig([
      'filter',
      'block_content',
      'daedalus_blueprint',
      'daedalus_stylish',
      'daedalus_stylish_olivero_skin',
      'daedalus_edit_text_block',
      'daedalus_edit_formatted_text_block',
    ]);

    // The shipped design system activated (its default skin is the olivero
    // skin) so the token roster is real. The activation is what lands the
    // shipped styling vocabulary: it syncs every enabled module's shipped
    // definitions under olivero's open law (daedalus_stylish ShippedSync, the
    // recipe's own install shape), so the container variants and Columns
    // (daedalus_stylish_layouts), the button look (daedalus_edit_text_block) and
    // daedalus_stylish's own animations arrive here as they do on a live site. A
    // hand sync before it would run on a skinless guarded site, where the
    // suite's bindings refuse the literal-valued button and the
    // token-valued Columns (nothing minted), which is not the surface a
    // site serves.
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('olivero');

    // The brief grows by data: a swatch line prints only while the site
    // has swatches, the STENCILS lines and the <pb-stencil> placement
    // sentence only while it has stencils. One described swatch and one
    // band stencil, both members of the active system, so the described
    // swatch line, the stencil line and the placement sentence are
    // exercised with product content (the values asserted below are read
    // back from the roster, never frozen here).
    $this->container->get('daedalus_stylish.swatch_manager')->mint('Signal', 'color', '#ff5500', MaterialRecord::ORIGIN_USER, FALSE, 'The one loud accent, for a single call to action per page. Never on body text.');
    $stencil = Stencil::create([
      'label' => 'Feature band',
      'machine_name' => 'feature_band',
      'description' => 'A titled band with one paragraph. Reach for it when a page needs one idea stated plainly.',
      'design_system' => 'olivero',
      'status' => 1,
    ]);
    $stencil->setElement([
      'kind' => 'container',
      'layout_id' => 'daedalus_stylish_container',
      'label' => 'Feature band',
      'styles' => ['' => ['variants' => ['container_site']]],
      'children' => [
        [
          'kind' => 'block',
          'block_type' => 'text',
          'block_fields' => ['info' => 'Title', 'field_text_tag' => 'h2', 'field_text_text' => 'One idea'],
        ],
        [
          'kind' => 'block',
          'block_type' => 'text',
          'block_fields' => ['info' => 'Body', 'field_text_text' => 'Stated plainly.'],
        ],
      ],
    ]);
    $stencil->save();

    // Enforcement is the active design system's own property, and the
    // olivero system ships open, so the activation above put the site's
    // posture there (a site with no active system is guarded). Pinned,
    // because the brief's Law line and the roster sentence asserted
    // below are the posture's own prose (VocabularyProse), and a fixture
    // drifting to another mode would change both silently.
    $this->assertSame('open', $this->container->get('daedalus_stylish.style_guide')->enforcement(), 'The olivero design system ships open enforcement.');

    // Routes must be built so path.validator resolves /entity_test/{id} to
    // the canonical route the current-page context subscribers key on.
    $this->container->get('router.builder')->rebuild();

    // The routed page carries a real (empty) container section, so the
    // dialect print asserted below is the live projection of a layout,
    // never a fixture string.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    // The agent and assistant are recipe config: create them from the
    // recipe's own files so this test also nets drift in those files.
    $config_dir = dirname(__DIR__, 4) . '/ingredients/daedalus_ai/config';
    foreach ([
      'ai_agent' => 'ai_agents.ai_agent.daedalus.yml',
      'ai_assistant' => 'ai_assistant_api.ai_assistant.daedalus.yml',
    ] as $entity_type => $file) {
      $path = "$config_dir/$file";
      $this->assertFileExists($path, 'The recipe ships its agent/assistant config where this test reads it.');
      $this->container->get('entity_type.manager')->getStorage($entity_type)
        ->create(Yaml::parseFile($path))
        ->save();
    }
  }

  /**
   * The assembled prompt surface is a projection of the live registries.
   */
  public function testAssembledPromptSurface(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $agent = $entity_type_manager->getStorage('ai_agent')->load('daedalus');
    $assistant = $entity_type_manager->getStorage('ai_assistant')->load('daedalus');

    // The sidebar fronts the agent: the assistant delegates to it (the
    // runner's agent path, which is what assembles everything below) and
    // carries NO prompt text of its own, so the agent's system_prompt is
    // the single source and no second copy can drift.
    $this->assertSame('daedalus', $assistant->get('ai_agent'));
    $this->assertSame('', trim((string) $assistant->get('instructions')));
    $this->assertSame('', trim((string) $assistant->get('system_prompt')));

    // THE TRANSPORT IS INERT for this agent. The recipe pins
    // hostname_filter_disabled so upstream's ToolsPropertyResult never
    // rewrites tool arguments in flight (it strips any style attribute
    // containing url(), which would silently de-style the taught
    // url('media:')/url('generate:') grammar): the op spine is the sole
    // validator of tool input. The agent was created from the recipe's own
    // config file in setUp, so this also nets drift in that file. Chat
    // link protection does not depend on the transport filter: it lives
    // at the display boundary (daedalus_ai DeepChatResponseListener).
    $this->assertTrue((bool) $agent->get('hostname_filter_disabled'), 'The recipe agent disables transport hostname filtering: tool arguments reach the page tools byte-intact.');

    // Config hygiene: every enabled tool exists in the live function_calls
    // registry (a config entry naming a nonexistent plugin would silently
    // vanish from the surface otherwise), and tool_settings carries no
    // orphans for tools that are not enabled.
    $function_calls = $this->container->get('plugin.manager.ai.function_calls');
    $enabled_tool_ids = array_keys(array_filter($agent->get('tools')));
    $this->assertNotEmpty($enabled_tool_ids, 'The recipe agent enables tools.');
    $expected_tool_names = [];
    foreach ($enabled_tool_ids as $tool_id) {
      $this->assertTrue($function_calls->hasDefinition($tool_id), "The configured tool $tool_id exists in the live function_calls registry.");
      $expected_tool_names[] = $function_calls->getDefinition($tool_id)['function_name'];
    }
    $settings_ids = array_keys($agent->get('tool_settings'));
    sort($settings_ids);
    $sorted_tool_ids = $enabled_tool_ids;
    sort($sorted_tool_ids);
    $this->assertSame($sorted_tool_ids, $settings_ids, 'tool_settings covers exactly the enabled tools: no orphans, no gaps.');
    // The page tools are the page surface: all three ride the roster.
    foreach (['daedalus_blueprint:page_read', 'daedalus_blueprint:page_edit', 'daedalus_blueprint:page_create'] as $page_tool) {
      $this->assertContains($page_tool, $enabled_tool_ids, "The recipe agent enables $page_tool.");
    }

    // The routed page: a real container section so the dialect print below
    // is a live projection.
    $section = new Section('daedalus_stylish_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', '11111111-2222-4333-8444-555555555555');
    $page = $entity_type_manager->getStorage('entity_test')->create([
      'name' => 'Routed Page',
      'layout_builder__layout' => [$section],
    ]);
    $page->save();

    // Drive the genuine assembly pipeline into the capture provider, with
    // the context DeepChatApi would pass: the routed current page, an
    // active view mode and a selected element.
    $capture = $this->container->get('daedalus_blueprint_ai_dev_tools.prompt_capture');
    $capture->reset();
    $this->container->get('ai_assistant_api.agent_runner')->runAsAgent(
      'daedalus',
      [['role' => 'user', 'message' => 'Add a hero section to this page.']],
      [
        'provider_id' => 'prompt_capture',
        'model_id' => PromptCaptureProvider::MODEL_ID,
        'configuration' => [],
      ],
      'prompt_surface_snapshot_' . uniqid(),
      FALSE,
      [
        'view_mode' => 'default',
        'current_route' => '/entity_test/' . $page->id(),
        'selected_elements' => [
          ['key' => 'block:00000000-0000-0000-0000-000000000000', 'number' => 1],
        ],
      ],
    );

    $this->assertTrue($capture->hasCapture(), 'The runner reached the capture provider: the assembled prompt was recorded.');
    $input = $capture->getInput();

    // The inert transport at the wire: the config pin above only matters
    // if the flag reaches the request, so the captured ChatInput must
    // carry the scoped full-trust DTO (AiAgentEntityWrapper sets it per
    // request; ProviderProxy applies it around the one provider
    // invocation and restores in finally). This is what makes ai_log
    // byte-match tool execution.
    $hostname_filter = $input->getHostnameFilter();
    $this->assertNotNull($hostname_filter, 'The assembled ChatInput carries the per-request hostname filter DTO.');
    $this->assertTrue($hostname_filter->fullTrust, 'The DTO is full trust: the transport is inert for the page-builder agent.');
    $system_prompt = $input->getSystemPrompt();
    $message_text = implode("\n", array_map(
      static fn (ChatMessage $message) => $message->getRole() . ': ' . $message->getText(),
      $input->getMessages(),
    ));

    // System prompt: the agent identity and loop from the recipe config.
    $this->assertStringContainsString('You are the Daedalus page builder assistant', $system_prompt);
    $this->assertStringContainsString('Skills first.', $system_prompt);
    $this->assertStringContainsString('ONE atomic page_edit batch', $system_prompt);
    $this->assertStringContainsString('ONE page_create document', $system_prompt);
    // The skills obligation (daedalus_blueprint_ai_anthropic).
    $this->assertStringContainsString('## Skills', $system_prompt);
    $this->assertStringContainsString('BLOCKING REQUIREMENT', $system_prompt);
    // The selection-scope protocol (daedalus_ai_anthropic), whose Context Tags
    // section names the real read tool: eb_read_schema, never the
    // never-real read_schema alias.
    $this->assertStringContainsString('## Selection Scope', $system_prompt);
    $this->assertStringContainsString('## Context Tags', $system_prompt);
    $this->assertStringContainsString('eb_read_schema', $system_prompt);

    // THE CACHED CATALOG: the page-language section rides the system
    // prompt (the cached prefix), and everything in it is checked against
    // the generated catalog, so the prompt stays a projection of live
    // config. The catalog itself must be non-trivial here: the product
    // block types, the container axes, the shipped tokens.
    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    $this->assertStringContainsString('## Page language', $system_prompt, 'The catalog section rides the cached system prompt.');
    $this->assertStringNotContainsString('## Page language', $message_text, 'The catalog never rides the per-turn messages.');
    $block_types = array_keys($catalog->blockTypes());
    $this->assertContains('text', $block_types, 'The product text block type is declared in the generated catalog.');
    $this->assertContains('formatted_text', $block_types, 'The product formatted_text block type is declared in the generated catalog.');
    foreach ($block_types as $type) {
      $this->assertStringContainsString('<' . DialectCatalog::tagForBlockType($type), $system_prompt, "The catalog section teaches the $type component.");
    }
    $container_axes = $catalog->axesFor(TRUE, NULL, NULL);
    $this->assertNotEmpty($container_axes, 'The shipped container variants derive the axes.');
    foreach (array_keys($container_axes) as $axis_attr) {
      $this->assertMatchesRegularExpression('/<pb-container[^>]*\b' . preg_quote($axis_attr, '/') . '\(/', $system_prompt, "The container reference carries the $axis_attr axis.");
    }
    // THE DESIGN SYSTEM BRIEF: the ONE thing the model is introduced to,
    // riding the cached prefix as the styling half of the page-language
    // section. It opens with the active system by name and its identity
    // (the entity's description, verbatim: the description is
    // model-facing by contract), states its law (the mode, the polarity
    // sentence VocabularyProse owns, the skin), the membership, preference
    // and stack sentences (MaterialsProse and VocabularyProse, the one
    // source each), then the roster projected in the system's own
    // structure. Every line below is read back from the entity, the
    // catalog and the roster, so the brief stays a projection of live
    // config; nothing about the look is hand-listed in a skill.
    $roster = $catalog->roster();
    $system = $this->container->get(ActiveDesignSystem::SERVICE_ID)->load();
    $this->assertNotNull($system, 'The fixture activates a design system.');
    $brief_heading = '## Design system: ' . $system->label();
    $this->assertStringContainsString($brief_heading, $system_prompt, 'The brief names the active design system in the cached system prompt.');
    $this->assertStringNotContainsString($brief_heading, $message_text, 'The brief never rides the per-turn messages.');
    $brief = substr($system_prompt, strpos($system_prompt, $brief_heading));
    $brief = substr($brief, 0, strpos($brief, "\nOPS (") ?: NULL);
    $identity = trim($system->getDescription());
    $this->assertNotSame('', $identity, 'The shipped system carries a description: the identity the model is told.');
    $this->assertStringContainsString("\n" . $identity . "\n", $brief, 'The identity paragraph is the entity\'s description, verbatim and unclipped.');
    $prose = VocabularyProse::of($this->container->get('daedalus_stylish.style_guide'));
    $this->assertStringContainsString('Law: open. ' . $prose->polaritySentence(), $brief, 'The law line names the mode and carries the polarity sentence, attributed to the system.');
    $this->assertSame('Olivero', $catalog->skin(), 'daedalus_stylish contributes the active published skin\'s label to the catalog.');
    $this->assertStringContainsString(' Skin: ' . $catalog->skin() . ';', $brief, 'The law line names the active skin.');
    $this->assertStringContainsString(MaterialsProse::membershipSentence($system->label()), $brief, 'The membership sentence names the system.');
    $this->assertStringContainsString(MaterialsProse::preferenceSentence(), $brief, 'The preference order and the mint gate are MaterialsProse\'s one sentence.');
    $this->assertStringContainsString($prose->stackSentence(), $brief, 'The stack sentence (with the explain_element_styles pointer) rides the brief.');
    // The roster in the system's structure: per element kind its axes,
    // each with the axis description, the properties it owns and its
    // values (label plus the description's first sentence), its class
    // looks, then its birth line. The width axis is the suite's own
    // described axis (daedalus_stylish_layouts declares its description and owns).
    $width = $container_axes['width'];
    $this->assertNotSame('', (string) $width['description'], 'The suite describes the container width axis.');
    $this->assertNotEmpty($width['owns'], 'The width axis owns properties (padding-left, padding-right, max-width in daedalus_stylish_layouts).');
    $width_line = sprintf('- width (top-level only; owns %s: wear the axis, never write them): %s.', implode(', ', $width['owns']), self::firstSentence($width['description']));
    $this->assertStringContainsString($width_line, $brief, 'The axis line carries the qualifiers, the owned properties and the axis description.');
    $variants = $roster->variants();
    $this->assertNotEmpty($variants, 'The shipped variants are rostered.');
    foreach ($width['values'] as $value => $id) {
      $this->assertStringContainsString(sprintf('%s "%s": %s', $value, $variants[$id]->label, self::firstSentence($variants[$id]->description)), $brief, "The width value $value rides with its label and its description's first sentence.");
    }
    $this->assertStringContainsString(sprintf('- button "%s" (class="button"): %s', $variants['button']->label, self::firstSentence($variants['button']->description)), $brief, 'A class look rides under its element with its spelling and first sentence.');
    $birth = $catalog->birthDefaults(Address::layout($catalog->containerLayoutId()));
    $this->assertArrayHasKey('width', $birth, 'The shipped container layout declares a width birth default.');
    $born = array_search($birth['width'], $width['values'], TRUE);
    $this->assertIsString($born);
    $this->assertStringContainsString(sprintf('- Born from the palette wearing width="%s"; a document states what it wears itself.', $born), $brief, 'The birth line spells the layout\'s default by its axis value.');
    // Stencils: one line each with the root kind, the band qualifier and
    // the first sentence; there is no anatomy string, the record carries
    // the element tree the schema prints in full as a page-language
    // fragment, and the placement sentence (both shapes: <pb-stencil
    // name="id"/> as is, or the printed fragment with your own content
    // under stencil="id") joins the block line only while stencils
    // exist.
    $stencils = $roster->stencils();
    $this->assertArrayHasKey('feature_band', $stencils, 'The fixture stencil is rostered as a member of the active system.');
    $stencil = $stencils['feature_band'];
    $this->assertNotEmpty($stencil->tree, 'The stencil record carries the element tree the schema prints; there is no anatomy string.');
    $this->assertTrue($roster->isBand($stencil), 'A root wearing the top-level-only width axis is a band.');
    $this->assertStringContainsString('STENCILS (', $brief);
    $this->assertStringContainsString(sprintf('- feature_band "%s" (%s, top-level only): %s', $stencil->label, $stencil->rootKind, self::firstSentence($stencil->description)), $brief, 'The stencil line carries the root kind, the band qualifier and the first sentence, no anatomy.');
    $this->assertStringNotContainsString('container[container_site] >', $brief, 'The anatomy string never rides the brief; the schema prints the fragment.');
    $this->assertStringContainsString('<pb-stencil name="stencil_id"/> places a saved stencil', $system_prompt, 'The as-is placement spelling rides the block line while stencils exist.');
    $this->assertStringContainsString('write its printed fragment (the schema shows each in full) with your content and stencil="stencil_id" on the root', $system_prompt, 'The expanded placement (the printed fragment under the stencil label) is taught beside the reference tag.');
    $this->assertStringContainsString('never a boundary', $system_prompt, 'Stencils are taught as pre-approved blocks and examples, never a boundary on hand-rolled work.');
    $this->assertStringNotContainsString('never add it by hand', $system_prompt, 'The old ban on authoring the stencil label is retired.');
    $this->assertStringNotContainsString('<pb-stencil', $message_text, 'The placement grammar never rides the per-turn messages.');
    // Named values: the skin's tokens by scale, then the swatches with
    // their labels and descriptions.
    $tokens = array_values($roster->tokens());
    $this->assertNotEmpty($tokens, 'The active skin mints tokens.');
    foreach ([$tokens[0], $tokens[count($tokens) - 1]] as $token) {
      $property = substr($token->spelling(MaterialRecord::SLOT_STYLE), 4, -1);
      $this->assertStringContainsString($property, $brief, "The named values carry $property.");
    }
    $swatches = $roster->swatches();
    $this->assertCount(1, $swatches, 'The fixture mints one swatch.');
    $swatch = reset($swatches);
    $this->assertStringContainsString(sprintf('- swatch color: %s "%s" (%s): %s', MaterialsSchema::customProperty($swatch), $swatch->label, self::firstSentence($swatch->description), MaterialsRoster::valueProse($swatch->value, 32)), $brief, 'The swatch line carries the custom property, the label, the description\'s first sentence and the value.');
    $this->assertStringNotContainsString('Never on body text', $brief, 'Only the first sentence of a description rides the prefix; the schema keeps the rest.');
    // The dressed tags: the skin sizes the tag, so the model picks the tag.
    $this->assertNotEmpty($catalog->dressedTags(), 'The active skin dresses base tags.');
    $this->assertStringContainsString(sprintf('The skin dresses %s: pick the tag, the skin sizes it.', implode(', ', $catalog->dressedTags())), $brief, 'The dressed-tags line is derived from the skin\'s defaults.');
    // The mint line: one gesture per kind behind MaterialsProse's gate.
    $this->assertStringContainsString('MINT (save_material {kind, label, ...}; ' . rtrim(MaterialsProse::mintGateSentence(), '.') . '): ', $brief, 'The mint line frames every gesture behind the one gate sentence.');
    foreach (['swatch: ', 'variant: ', 'stencil: '] as $gesture) {
      $this->assertStringContainsString($gesture, $brief, "The mint line teaches the $gesture gesture.");
    }
    // THE RESPONSIVE GRAMMAR: every published wrapper is taught verbatim
    // BESIDE its condition name (narrow, medium, dark, ...), the width
    // bands are taught base-out with the narrow example, and the STYLES
    // line names both doors the conditioned rules ride: the page_create
    // document's trailing <style> block and the page_edit style op. The
    // edit-only phrasing "through the style op" is banned because it
    // leaves a page_create document with no taught responsive door, and
    // an unnamed band is a lane the model cannot reach.
    foreach ($catalog->conditions() as $name => $wrapper) {
      if (str_starts_with($wrapper, '@')) {
        $this->assertStringContainsString("$name $wrapper", $system_prompt, "The published wrapper $wrapper is taught verbatim beside its name.");
      }
    }
    $this->assertNotEmpty($catalog->widthBands(), 'The product publishes width bands, so the base-out teaching below is exercised.');
    $this->assertStringContainsString('width bands narrow @container (max-width: 767px), medium @container (min-width: 768px) and (max-width: 1023px) (base-out: the default, no wrapper, IS the wide look', $system_prompt, 'The width bands are named with their wrappers and taught base-out.');
    $this->assertStringContainsString('@container (max-width: 767px) { #hero-title { font-size: 2.5rem } } steps a headline down on phones', $system_prompt, 'One narrow example rides the prefix.');
    $this->assertStringContainsString('a page_create document carries them in one trailing <style> block, a page_edit batch in the style op', $system_prompt, 'Conditioned rules are taught on both doors.');
    $this->assertStringNotContainsString('through the style op', $system_prompt, 'The edit-only phrasing is retired.');
    // THE QUOTING CANON (dialect 0.4): every url() the surface teaches is
    // single-quoted, the one spelling valid inside style="" attributes,
    // <style> blocks, and JSON strings alike, and the catalog says the
    // rule out loud. The retired double-quoted spelling is banned
    // surface-wide via RETIRED_VOCABULARY.
    $this->assertStringContainsString("url('media:<uuid>')", $system_prompt, 'The catalog teaches the single-quoted media token.');
    $this->assertStringContainsString("url('generate:<prompt>')", $system_prompt, 'The catalog teaches the single-quoted generate url.');
    $this->assertStringContainsString('Always single-quote url() arguments', $system_prompt, 'The catalog names the quoting rule.');
    // THE VOCABULARY ROSTER: the product contributes one vocabulary
    // roster per element kind (the catalog's vocabularies() read, kind
    // => roster, granted() the property names), and the cached prompt
    // teaches them by property name through VocabularyProse (the widest
    // kind in full, the others as deltas against it) so a model never
    // has to miss (padding on a container) to learn what the site
    // permits. The roster is the design system's vocabulary on the open
    // site (the header is the posture's roster sentence); it must ride
    // the cached prefix, never the per-turn messages.
    $rosters = $catalog->vocabularies();
    $this->assertNotEmpty($rosters, 'The product contributes per-kind vocabulary rosters to the catalog.');
    $granted = array_map(static fn (VocabularyRosterInterface $roster): array => $roster->granted(), $rosters);
    $roster_header = $prose->rosterSentence();
    $this->assertStringContainsString($roster_header, $system_prompt, 'The roster section rides the cached system prompt.');
    $this->assertStringNotContainsString($roster_header, $message_text, 'The roster never rides the per-turn messages.');
    foreach (array_keys($granted) as $kind) {
      $this->assertMatchesRegularExpression('/^- ' . preg_quote($kind, '/') . ':/m', $system_prompt, "The roster teaches the $kind kind.");
    }
    $widest = array_reduce(array_keys($granted), fn (?string $carry, string $kind) => $carry === NULL || count($granted[$kind]) > count($granted[$carry]) ? $kind : $carry);
    $this->assertStringContainsString(implode(', ', $granted[$widest]), $system_prompt, 'The widest roster is taught in full, by property name.');
    // The padding shorthand on a container is a granted property, not a
    // miss to teach around: placement curates offers and birth, never
    // legality, so a root container grants the whole Spacing group and
    // the shorthand family rule derives padding from its four granted
    // sides. Pinned so the roster keeps teaching the shorthand on both
    // kinds, and the container line in the form VocabularyProse prints
    // for the product's rosters (a container's grant equals a block's).
    $this->assertContains('padding', $granted['container']);
    $this->assertContains('padding', $granted['block']);
    $this->assertSame($granted['block'], $granted['container'], 'A root container grants what a block grants; there is no depth roster.');
    $this->assertStringContainsString('- container: same as block', $system_prompt, 'The container line rides the cached prefix as the delta form against the block roster.');
    // The op set: every DOM op, and only the DOM vocabulary, is the taught
    // edit grammar.
    $ops_section = substr($system_prompt, strpos($system_prompt, 'OPS ('));
    $ops_section = substr($ops_section, 0, strpos($ops_section, '## ') ?: NULL);
    foreach (DomOperationHandler::OPS as $op) {
      $this->assertMatchesRegularExpression('/\b' . $op . ' \{/', $ops_section, "The op set teaches $op.");
    }

    // THE PAGE ON THE TURN: the current page's dialect print rides the
    // user message (refreshed per turn), never the cached system prompt.
    $this->assertStringContainsString(
      sprintf('Current page: entity_test (ID: %s, UUID: %s', $page->id(), $page->uuid()),
      $message_text,
      'The Current page reminder rides the user message.',
    );
    $this->assertStringContainsString('Current page print', $message_text, 'The dialect print block rides the user message.');
    $container_open = '<pb-container id="11111111-2222-4333-8444-555555555555"';
    $this->assertStringContainsString($container_open, $message_text, 'The print is the live dialect projection of the routed page.');
    $this->assertStringNotContainsString($container_open, $system_prompt, 'The page never rides the cached prefix.');
    $this->assertStringContainsString('"Current page print" tag', $system_prompt, 'The Context Tags protocol teaches the print.');

    // The ACTIVE SELECTION reminder rides the user message and matches the
    // protocol's label.
    $this->assertStringContainsString('ACTIVE SELECTION', $message_text);

    // The first-turn skill catalog is exactly the live SkillsResolver
    // registry: every discovered skill is offered, and nothing is offered
    // that does not exist. The catalog format is SkillListSubscriber's
    // "- id: description" list inside a <system-reminder>.
    $registered_skills = array_keys($this->container->get('daedalus_blueprint.skills_resolver')->getSkills());
    $this->assertContains('design', $registered_skills, 'The product module set registers the one page skill.');
    foreach (['styling', 'stencils', 'brand'] as $dissolved) {
      $this->assertNotContains($dissolved, $registered_skills, "The $dissolved skill dissolved into the design system brief and the cached lines; it must not register again.");
    }
    $marker = 'The following skills are available for use with the Skill tool:';
    $marker_position = strpos($message_text, $marker);
    $this->assertIsInt($marker_position, 'The skill catalog rides the first user message.');
    $skill_catalog = substr($message_text, $marker_position);
    $skill_catalog = substr($skill_catalog, 0, strpos($skill_catalog, '</system-reminder>') ?: NULL);
    preg_match_all('/^- ([a-z0-9_]+): /m', $skill_catalog, $matches);
    $catalog_skills = $matches[1];
    sort($catalog_skills);
    sort($registered_skills);
    $this->assertSame($registered_skills, $catalog_skills, 'The skill catalog lists exactly the skills the live resolver registers.');

    // The tool roster is exactly the recipe agent's enabled tools, by the
    // function names the live registry maps them to.
    $tools = $input->getChatTools();
    $this->assertNotNull($tools, 'The assembled prompt carries tools.');
    $tool_names = array_map(
      static fn ($function) => $function->getName(),
      $tools->getFunctions(),
    );
    sort($tool_names);
    sort($expected_tool_names);
    $this->assertSame($expected_tool_names, array_values($tool_names), 'The assembled roster is exactly the configured tools mapped through the live registry.');

    // The eb_edit operations parameter presents the NON-PAGE shape: on a
    // page-language site (the catalog declares a container layout,
    // which the product always does) the layout grammar and the page-only
    // handler roll-call leave the surface entirely, so the description is
    // byte-identical to the root-field + config-ops composition. A page
    // op or the layout grammar leaking back into eb_edit fails here.
    $rendered_tools = $tools->renderToolsArray();
    $operations_description = NULL;
    $edit_description = NULL;
    foreach ($rendered_tools as $rendered) {
      if (($rendered['function']['name'] ?? NULL) === 'eb_edit') {
        $operations_description = $rendered['function']['parameters']['properties']['operations']['description'] ?? NULL;
        $edit_description = $rendered['function']['description'] ?? NULL;
      }
    }
    $this->assertIsString($operations_description, 'The assembled eb_edit tool carries the operations parameter description.');
    // The renderer prefixes the context label ("Operations "); the rest is
    // byte-identical to the constants' composition.
    $this->assertSame(
      'Operations ' . Edit::OPERATIONS_INTRO . Edit::OPERATIONS_ROOT_GRAMMAR . Edit::OPERATIONS_CONFIG_OPS . Edit::OPERATIONS_ROOT_EXAMPLE,
      $operations_description,
      'On the product (page-language) site shape, eb_edit teaches only the root-field and config grammar; the layout grammar and any handler roll-call stay off the surface.',
    );
    // The page ops still exist at the spine and are all declared
    // page-only on the product set: that is exactly why no
    // "Module-registered ops:" roll-call rides eb_edit anymore.
    $registry = $this->container->get('daedalus_blueprint.operation_handler_registry');
    $guidance = $registry->getGuidance();
    $this->assertNotEmpty($guidance, 'The product module set registers ops (daedalus_stylish + stencil + DOM).');
    foreach (['insert', 'style', 'save_material'] as $op) {
      $this->assertArrayHasKey($op, $guidance, "The $op op is registered, so the page-only exclusion below is exercised with real product ops.");
    }
    $this->assertSame([], array_diff(array_keys($guidance), $registry->getPageOnlyOperations()), 'Every registered op on the product set is page-only (page_edit grammar), so eb_edit carries no roll-call.');
    // The routing rides the tool's own description (the recipe's agent
    // config sets no description_override keys): eb_edit presents as the
    // non-page JSON surface (page_edit owns pages) on every site.
    $this->assertIsString($edit_description);
    $this->assertStringContainsString('NON-PAGE', $edit_description, 'eb_edit scopes itself to non-page entities on the wire.');

    // THE FIRST-TURN BUDGET: system prompt + rendered tools + messages,
    // bytes/4 as the token estimate (see FIRST_TURN_TOKEN_BUDGET).
    $surface = $system_prompt . "\n" . $message_text . "\n" . json_encode($rendered_tools);
    $estimated_tokens = (int) ceil(strlen($surface) / 4);
    $this->assertLessThan(
      self::FIRST_TURN_TOKEN_BUDGET,
      $estimated_tokens,
      sprintf('The assembled first turn is ~%d estimated tokens; the budget is %d. Trim the surface, never the page.', $estimated_tokens, self::FIRST_TURN_TOKEN_BUDGET),
    );

    // The retired-vocabulary sweep covers the WHOLE captured surface:
    // system prompt, every message, and every rendered tool definition.
    foreach (self::RETIRED_VOCABULARY as $banned) {
      $this->assertStringNotContainsString($banned, $surface, "The assembled prompt surface must not carry retired vocabulary: $banned");
    }
    // The read tool has exactly one name: every mention is eb_read_schema.
    $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(?<!eb_)read_schema/', $surface, 'The surface never names the retired read_schema alias.');
    // The retired positional location key {_section, ...}: the lookbehind
    // spares live ops like add_section while catching the bare key.
    $this->assertDoesNotMatchRegularExpression('/(?<![a-z])_section\b/', $surface, 'The surface never spells the retired positional _section key.');
    // The retired selection-key grammar: the live selectable keys are
    // entity:/field:/container:/block: only; section:/region: died with
    // the container model (regions have no keys at all).
    $this->assertDoesNotMatchRegularExpression('/(?<![a-z_-])(section|region):/', $surface, 'The surface never teaches the retired section:/region: selection keys.');
  }

  /**
   * A description's first sentence, terminal punctuation off.
   *
   * The brief's clip rule (DialectCatalogPrompt::firstSentence()): the
   * prefix pays for what a material is and when to reach for it, the
   * schema keeps the whole text. Restated here so the assertions above
   * derive the expected line from the record instead of freezing prose.
   */
  protected static function firstSentence(string $description): string {
    $description = trim($description);
    if (preg_match('/^(.+?)[.!?](?:\s|$)/su', $description, $m)) {
      return $m[1];
    }
    return $description;
  }

}
