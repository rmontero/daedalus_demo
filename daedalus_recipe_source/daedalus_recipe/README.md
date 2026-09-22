# Daedalus recipe

<!-- cspell:ignore Bozeman DDEV -->

## Introduction

Daedalus recipe is the Drupal recipe project that installs and configures
the complete Daedalus page builder on a site: the page-building modules,
block types, the Page content type, the reference theme, the styling
layer, and a ready-to-edit starter site. It is the installer, not the page
builder itself; the `daedalus` module documents its own behavior.

One project carries two site recipes and every ingredient:

- `recipe.yml` at the root: the page builder.
- `ai/recipe.yml`: the page builder plus the AI editing assistant.
- `ingredients/`: one sub-recipe per concern, which both site recipes are
  composed from.

Applying the base recipe gives a site:

- The page-building engine: Daedalus Tempstore, Daedalus UI, Twig Events,
  Daedalus Edit, and Daedalus Layout (the Edit Mode toolbar, on-page layout
  tools, inline field editing, and staged changes).
- Daedalus Blueprint with its API and stencil submodules.
- Daedalus Stylish with its layouts and layers submodules and the Olivero
  skin, with the Olivero design system active.
- Daedalus Theme as the default front-end theme, with Claro as the
  administration theme.
- Field Sample Value, so newly placed blocks arrive pre-populated.
- Block types: text, formatted text, image, and a configuration-only layout
  block type for theme regions.
- The Page content type, layout enabled. On a site that already has a
  `page` bundle (from the standard profile on core 11.3, from
  `core/recipes/page_content_type` on core 11.4, or its own) the recipe
  takes it over and converges it to the builder's shape instead of shipping
  a second page type: the type is renamed Page, preview is switched off,
  and its display gets Layout Builder with the builder's promoted blocks.
  Fields the bundle already carries stay.
- An Image media type with the media library, and a Vector image (SVG)
  media type.
- Core navigation.
- The Daedalus Editor role, carrying every permission the suite grants.
- Demo content: a site header and footer placed in the theme regions, a
  welcome page set as the front page, a Home menu link, and one demo media
  item.

The top-level `recipe.yml` is an alias for the `daedalus` umbrella
ingredient, which applies everything above in dependency order. Any
ingredient can be applied on its own, and each declares exactly the
ingredients it needs, so a composing recipe can pick the parts it wants.
Ingredients are written to be safely re-applied to a site that already
carries them.

### The AI site recipe

`ai/recipe.yml` applies the base recipe first, then layers the AI editing
assistant on top:

- The AI framework: AI, AI Assistant API, AI Chatbot, and AI Agents.
- Daedalus Blueprint AI, the JSON serialization layer through which the
  agent reads and edits pages.
- Daedalus AI, the in-page assistant and chat sidebar in Edit Mode.
- Daedalus AI Memory, enabled for the shipped agent so a conversation
  carries its state across turns.
- The `daedalus` agent and assistant configuration.
- Anthropic (Claude) as the default chat provider, with prompt caching on,
  plus the Claude-specific context formatting submodules.
- Google Gemini as the default text-to-image provider for image generation
  in the page builder.

Each provider is its own ingredient and ships an empty key: nothing talks
to a provider until you add an API key. Image generation ships switched
off. The `use ai assistant` permission is granted to the Daedalus Editor
role.

## Requirements

- Drupal core 11.3 or 11.4 (`>=11.3 <11.5`, the minors the suite is tested on).
- Composer with recipe support in the project, which Drupal's recommended
  project template provides.
- Everything the recipes install is pulled in by `composer require`: the
  Daedalus module, Daedalus Theme, Twig Events, Field Sample Value, Layout
  Builder Restrictions, SVG Image Field, and the AI stack (AI, AI Agents,
  Key, the Anthropic provider, and the Gemini provider). The AI packages
  sit unused in `vendor/` on a site that applies only the base recipe.
- The Anthropic provider has no stable release in the range the recipe
  requires (`drupal/ai_provider_anthropic ^1.3@beta`), and a dependency
  cannot lower the project's minimum stability. A project at the default
  `stable` minimum must allow the beta at its root before the recipe
  resolves:

  ```
  composer require drupal/ai_provider_anthropic:^1.3@beta
  ```

  Projects already at `minimum-stability: beta` or below skip this step.
- For the AI site recipe: an Anthropic API key for chat, and a Google
  Gemini API key if image generation is wanted.

## Installation

From the root of a Drupal project:

```
composer require drupal/daedalus_recipe
cd web
php core/scripts/drupal recipe ../recipes/daedalus_recipe
```

With Drush installed, `drush recipe ../recipes/daedalus_recipe` does the same.
Rebuild caches afterwards with `drush cr`.

For the page builder with the AI editing assistant, apply the AI site
recipe instead: `drush recipe ../recipes/daedalus_recipe/ai`. There is no
need to apply the base recipe first; the AI recipe includes it.

To apply a single ingredient, point the command at it, for example
`drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_page`. To
leave an AI provider out, apply the AI ingredients you want instead of
the AI site recipe: `ingredients/daedalus_ai` is the provider-neutral
core, and `ingredients/daedalus_ai_anthropic` and
`ingredients/daedalus_ai_gemini` are the provider layers. With your own
provider, apply the core ingredient and set the default chat provider
yourself.

### Existing sites

The site recipes and the `daedalus` umbrella are for new sites: they
switch the default theme to Daedalus Theme and the administration theme to
Claro, take over the `page` bundle, and reshape the Image media type's
display. A site that already has content, a theme and roles adopts the
page builder ingredient by ingredient instead, in the order the guide
gives, applying only the ones it wants, and follows `EXISTING_SITES.md`
at the root of this project.

That guide is written as a prompt for a coding agent (Claude Code or
similar): the site owner hands it over, the agent surveys the site
(content types, Layout Builder displays, text formats, block types, media,
theme, roles), applies the ingredients in order knowing what each one
installs and takes over, enables the builder on the chosen content types,
adjusts text formats and roles, and runs a verification checklist at the
end. It is readable by a person too; nothing in it needs an agent.

`install.sh` at the root of the recipe builds a complete local site from
nothing with DDEV, from either site recipe. It builds on the newest core
the suite is tested on; `--core <version>` picks another release.
`setup-testing.sh` prepares that site to run the recipe's tests.

## Configuration

The base recipe needs no configuration after it is applied. Log in and use
the Edit Mode toolbar on any page.

Choices the recipe makes that a site may want to revisit:

- The site recipes set the front page to the welcome page shipped by the
  demo content ingredient. The umbrella `ingredients/daedalus` ships
  configuration only; `ingredients/daedalus_demo_content` adds the starter
  content and the front-page change when they are wanted. A site that
  already exists follows the Existing sites section above rather than
  either.
- The Vector image media type grants no upload permissions to any role.
  Grant them to trusted roles only; SVG uploads carry their own security
  considerations.
- Every permission the suite grants lives in the `daedalus_editor` role.
  A site building its own roles can copy from it.
- The active design system is Olivero, which ships with
  `enforcement: open`: its declarations curate what the styling offers
  first, and any recognized property with a grammatical value is still
  accepted. A site that wants a tighter posture (`guarded`, where authored
  constraints are law, or `strict`, where only declared properties may be
  styled) edits its active design system at
  Administration > Appearance > Design systems.

After the AI site recipe:

1. Go to Administration > Configuration > System > Keys
   (`/admin/config/system/keys`) and paste your Anthropic API key into the
   `anthropic` key. The chat assistant is live once the key is saved.
2. For image generation, paste your Gemini API key into the `gemini` key
   and turn on the site-wide switch:

   ```
   drush config:set daedalus_ai.settings image_generation_enabled true
   ```

   Each editor then has a "Generate images" toggle in the Edit Mode
   settings sidebar; when it is off, a placeholder image is used instead.
3. The shipped keys use the configuration key provider, which stores the
   value in config. For a production site, edit each key and switch it to
   a provider such as an environment variable.

The default chat and text-to-image providers can be changed at
Administration > Configuration > AI, and the `daedalus` assistant and
agent can be edited there as well.

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
