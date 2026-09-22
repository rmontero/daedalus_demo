<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_recipe_ai\Kernel;

use Drupal\daedalus_blueprint_ai_dev_tools\Plugin\AiProvider\PromptCaptureProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * The assistant's llm_configuration reaches the provider on the agent path.
 *
 * Upstream, that configuration is dead on the sidebar's real path:
 * AiAssistantApiRunner::getProviderAndModel() returns only provider/model
 * ids, AiAgentEntityWrapper never copies its aiConfiguration onto the
 * provider instance, and fromArray() recreates the provider bare on every
 * restored turn. The provider module's api defaults win instead (for
 * Anthropic, max_tokens 4096 - too small for a whole-page create document).
 *
 * The product's fix lives in daedalus_ai_memory's AgentMemoryRunner, which IS
 * the sidebar's runner for the daedalus agent (the recipe opts it into
 * enabled_agents): on every turn state it resolves the fronting assistant's
 * llm_configuration and sets it on the agent's provider. This test drives
 * the genuine decorated runner into the capture provider (which records its
 * own configuration at chat() time) and asserts the recipe's max_tokens
 * value is what the provider would actually send.
 *
 * It lives in the daedalus_ai recipe, not daedalus_ai_memory, because the
 * guarantee under test is a product guarantee: the RECIPE's assistant config
 * (created here from the recipe's own file, netting drift there) flowing
 * through the RECIPE's runner opt-in. It is a sibling of
 * PromptSurfaceSnapshotTest, which deliberately excludes daedalus_ai_memory;
 * this test deliberately includes it because the memory runner is exactly
 * the machinery that carries the configuration.
 *
 * Discovery note: recipes/ is not scanned for test namespaces, so PHPUnit
 * finds this class only when pointed at the file or directory; keep it
 * self-contained (no sibling base class).
 *
 * @group daedalus_recipe
 */
#[Group('daedalus_recipe')]
#[RunTestsInSeparateProcesses]
class ProviderConfigurationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The PromptSurfaceSnapshotTest module set (see its docblock) PLUS
   * daedalus_ai_memory, whose runner decoration is the subject here.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'file',
    'link',
    'image',
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
    'daedalus_ai',
    'daedalus_ai_anthropic',
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'daedalus_ai_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The page-language catalog build (system prompt assembly) builds the
    // materials roster, whose providers read daedalus_stylish's four material
    // tables (variants, skins, swatches, animations) and the stencil table.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('stencil');
    $this->setUpCurrentUser([], [], TRUE);

    // The agent and assistant are recipe config: create them from the
    // recipe's own files so this test also nets drift in those files. The
    // package root is this file's fourth-level parent (ai/tests/src/Kernel).
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

    // The recipe opts the daedalus agent into the memory runner; mirror
    // that here so the decorated (production) path runs.
    $this->config('daedalus_ai_memory.settings')
      ->set('enabled_agents', ['daedalus' => 'daedalus'])
      ->save();
  }

  /**
   * The captured provider configuration carries the recipe's llm settings.
   */
  public function testLlmConfigurationReachesProvider(): void {
    $capture = $this->container->get('daedalus_blueprint_ai_dev_tools.prompt_capture');
    $capture->reset();

    // Mimic AiAssistantApiRunner exactly: getProviderAndModel() hands the
    // runner ONLY provider and model ids, so any configuration the provider
    // ends up with must have been resolved by the runner itself.
    $this->container->get('ai_assistant_api.agent_runner')->runAsAgent(
      'daedalus',
      [['role' => 'user', 'message' => 'Add a hero section to this page.']],
      [
        'provider_id' => 'prompt_capture',
        'model_id' => PromptCaptureProvider::MODEL_ID,
      ],
      'provider_configuration_' . uniqid(),
      FALSE,
      [],
    );

    $this->assertTrue($capture->hasCapture(), 'The decorated runner reached the capture provider.');
    $configuration = $capture->getProviderConfiguration();

    // The headline: the assistant's max_tokens is on the provider at chat()
    // time, and it is the raised ceiling a whole-page create document needs
    // (the Anthropic api default of 4096 truncates one, and 16384 still
    // truncated once interleaved thinking shared the budget).
    $this->assertSame(64000, $configuration['max_tokens'] ?? NULL, 'The provider configuration carries the recipe assistant\'s max_tokens.');

    // The rest of the recipe's llm_configuration rides along verbatim.
    $assistant = $this->container->get('entity_type.manager')->getStorage('ai_assistant')->load('daedalus');
    foreach ($assistant->get('llm_configuration') as $key => $value) {
      $this->assertSame($value, $configuration[$key], "The provider configuration carries llm_configuration.$key.");
    }

    // Claude 4+ models reject requests that set both temperature and top_p;
    // now that this configuration actually reaches the wire, top_p must not
    // ride it (the recipe dropped it deliberately).
    $this->assertArrayNotHasKey('top_p', $configuration, 'top_p never reaches the provider: Anthropic rejects temperature and top_p together.');

    // Claude Sonnet 5 goes further and rejects temperature outright
    // ("`temperature` is deprecated for this model", a 400 on every sidebar
    // turn), so it must not ride the wire either.
    $this->assertArrayNotHasKey('temperature', $configuration, 'temperature never reaches the provider: Claude Sonnet 5 rejects it as deprecated.');
  }

}
