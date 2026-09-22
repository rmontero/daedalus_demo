# Adopting Daedalus on an existing site

<!-- cspell:ignore uli langcode onecol twocol pickable unlayered textarea -->

This is a guide for a coding agent (Claude Code or similar) that has been
handed an existing Drupal site and asked to install the Daedalus page
builder on it. Read it top to bottom before touching the site. Every
command below runs from the `web` directory of the project unless it says
otherwise, with Drush available; where the site has no Drush,
`php core/scripts/drupal recipe <path>` applies a recipe the same way
`drush recipe <path>` does.

The site recipes at the root of this project (`recipe.yml`, `ai/recipe.yml`)
and the `ingredients/daedalus` umbrella are for new sites. They switch the
default theme to Daedalus Theme and the administration theme to Claro, take
over the `page` content type, reshape the Image media type's display and
ship demo content. An existing site keeps its theme, its content types and
its roles, so it adopts the page builder ingredient by ingredient, in the
order given in section 4, applying only what it wants. Do not apply the
umbrella, `ingredients/daedalus_themes` or
`ingredients/daedalus_demo_content` to an existing site.

Everything this guide states about the ingredients was checked against a
core standard-profile site with the page content type, Layout Builder on a
bundle and `basic_html` content. Where the guide has not verified something
it says so.

## 1. What Daedalus needs from a site

- Drupal core 11.3 or 11.4 (`>=11.3 <11.5`). Check with `drush status`.
- Layout Builder. The page builder is Layout Builder with a different
  editing surface: `daedalus_layout` and `daedalus_tempstore` depend on the
  `layout_builder` module, and a content type gets the builder by enabling
  Layout Builder with per-item overrides on its display. Inline field
  editing (`daedalus_edit`) alone works on a plain display without Layout
  Builder.
- The core `navigation` module. `daedalus_ui` depends on it; the Edit Mode
  top bar is built from its class vocabulary. Core 11.4's standard profile
  installs it; on a site without it the engine ingredient installs it as a
  module dependency.
- A Twig theme engine. `daedalus_ui` re-types its theme hooks to the active
  theme's engine; a theme without an engine cannot run the builder.
- A front-end theme whose `html.html.twig` prints `{{ html_attributes }}`,
  `{{ page_top }}` and `{{ page_bottom }}` and keeps `head-placeholder`
  before `css-placeholder` (core's own template and Olivero do; the Edit
  Mode bars render into `page_top`, and the styling layer's cascade-layer
  statement rides `html_head`).
- Core's off-canvas wrapper, `.dialog-off-canvas-main-canvas`, around the
  page. The stage the builder wraps the page in mounts inside it.
- Entity, block and field templates that print `{{ attributes }}` on their
  outermost element. When a template does not, `daedalus_ui` wraps the
  output in a fallback `div` carrying the attributes and logs a warning
  naming the template, so a missing print degrades rather than breaks.

Section 8 has the full theme contract for a site keeping its own theme.

## 2. Composer

`drupal/daedalus_recipe` requires `drupal/ai_provider_anthropic ^1.3@beta`,
which has no stable release, and a dependency cannot lower a project's
minimum stability. A project at the default `stable` minimum has to allow
that beta at its root before the recipe resolves. Both commands run at the
project root (the directory holding `composer.json`), not in `web`:

```
composer require drupal/ai_provider_anthropic:^1.3@beta
composer require drupal/daedalus_recipe
```

A project already at `minimum-stability: beta` or lower skips the first
command. The AI packages then sit unused in `vendor/`; nothing below
installs them.

The recipe lands at `recipes/daedalus_recipe`, so from `web` every
ingredient is `../recipes/daedalus_recipe/ingredients/<name>`. On a
project with core's recipe unpack plugin (`drupal/core-recipe-unpack`,
which the recommended project template ships) the second command reports
`drupal/daedalus_recipe unpacked`: the recipe's dependencies move into the
root `composer.json` and the recipe itself leaves `require`; the directory
under `recipes/` stays and is applied the same way.

## 3. Survey the site first

Record the answers before applying anything; sections 4 to 8 depend on
them.

Content types, and which of them should get the builder. Typically a page
or landing-page type; an article type with a body field is usually left
alone.

```
drush php:eval 'echo implode("\n", \Drupal::configFactory()->listAll("node.type.")), "\n";'
```

Displays that already run Layout Builder, and the layouts their sections
use. Sections built with core layouts (`layout_onecol`, `layout_twocol_section`
and so on) keep rendering after adoption but stop being pickable; see
section 9.

```
drush php:eval '
foreach (\Drupal::entityTypeManager()->getStorage("entity_view_display")->loadMultiple() as $display) {
  if (!$display instanceof \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay || !$display->isLayoutBuilderEnabled()) {
    continue;
  }
  $layouts = [];
  foreach ($display->getSections() as $section) {
    $layouts[] = $section->getLayoutId();
  }
  printf("%s overrides=%s layouts=%s\n", $display->id(), $display->isOverridable() ? "yes" : "no", implode(",", array_unique($layouts)) ?: "(none)");
}'
```

Text formats and what they allow. The builder mints an identity class onto
`p`, `h2`, `h3`, `h4`, `blockquote`, `ul`, `ol` and `li` elements it stores;
a format that allows one of those tags without an unrestricted `class`
attribute has those elements skipped (section 6). Core's `basic_html`
allows `<p>` and `<h2 id>` without `class`; `full_html` restricts nothing.

```
drush php:eval 'echo implode("\n", \Drupal::configFactory()->listAll("filter.format.")), "\n";'
drush config:get filter.format.basic_html filters.filter_html.settings.allowed_html
```

Custom block types, and whether the site already has a `field_image` or
`field_image_url` field storage on `block_content`. The image block
ingredient ships both storages and compares them strictly, so a site with
its own storage of either name is refused by that ingredient (section 4).

```
drush php:eval 'echo implode("\n", \Drupal::configFactory()->listAll("block_content.type.")), "\n";'
drush php:eval 'echo implode("\n", \Drupal::configFactory()->listAll("field.storage.block_content.")), "\n";'
```

Media: whether the `media` module is on (core 11.4's standard profile does
not install it), whether an `image` media type exists and how its default
display renders the source field. The media image ingredient applies
core's `image_media_type` recipe, which creates the type only when it is
missing, and then re-sets that display.

```
drush php:eval '
$types = \Drupal::moduleHandler()->moduleExists("media") ? \Drupal::entityTypeManager()->getStorage("media_type")->loadMultiple() : [];
echo "media types: ", $types ? implode(", ", array_keys($types)) : "(media not installed)", "\n";
$component = \Drupal::config("core.entity_view_display.media.image.default")->get("content.field_media_image");
echo "image display source field: ", $component ? json_encode($component) : "(none)", "\n";'
```

The default and administration themes.

```
drush config:get system.theme
```

Roles, with their permissions, so section 7 can decide which existing role
should edit pages.

```
drush role:list
```

Whether content moderation or workspaces is enabled. Adoption with either
is untested (section 9).

```
drush pm:list --status=enabled --type=module --format=list | grep -E '^(block_content|content_moderation|layout_builder|media|navigation|workspaces)$'
```

The default language. A site whose default language is not English has
Layout Builder field storages and labels core created in that language;
the ingredients accept them (they compare no such storage strictly).

```
drush config:get system.site default_langcode
```

Take a database backup before section 4. Recipes are not reversible.

## 4. The ingredient ladder

Apply the ingredients in this order, one command each, and read each
result before the next. Every ingredient declares the ingredients it
needs and applies them first, so the order below is mostly for reading:
each step installs a small, inspectable piece. Re-applying an ingredient
that already applied is a no-op.

What each ingredient installs and what it takes over on an existing site:

`daedalus_engine`: the page-building modules `daedalus_tempstore`,
`daedalus_ui`, `twig_events`, `daedalus_edit` and `daedalus_layout`, plus
core `path`. Module dependencies pull in `daedalus`, `daedalus_blueprint`,
`daedalus_blueprint_api`, `field_sample_value`, core `layout_builder`,
`layout_discovery`, `block`, `editor` and `navigation` if any are
missing. Takes nothing over. From here on `daedalus_edit` runs its identity
minter on every entity save (section 6).

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_engine
```

`daedalus_blueprint`: `daedalus_blueprint`, its API and the stencil
submodule, the serialization spine the styling layer and the AI layer
operate through. Takes nothing over.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_blueprint
```

`daedalus_styling`: `daedalus_stylish`, `daedalus_stylish_layouts`,
`daedalus_stylish_layers` and `daedalus_stylish_olivero_skin`, and it
activates the shipped Olivero design system site-wide
(`daedalus_blueprint.settings` `active_design_system: olivero`). Three
site-wide effects follow. `daedalus_stylish_layers` wraps every pipeline
stylesheet, the theme's included, in a CSS cascade layer named `site` so
the styling layer's own output wins (section 8). `daedalus_stylish_layouts`
makes both layout pickers offer only its `daedalus_stylish_container`
layout; core layouts stay valid for sections that already use them
(section 9). `daedalus_stylish` creates a `daedalus_stylish_styles` field
on every bundle that has both Layout Builder and Edit Mode enabled, at
install and at every later bundle or display save (section 5). The shipped
skin, variants and animations import at install; confirm afterwards with
the checklist in section 10.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_styling
```

`daedalus_media_image`: core's `image_media_type` recipe (creates the
`image` media type and its `field_media_image` storage when missing;
refuses a site whose existing `field_media_image` storage differs from
core's) and the `media_library` module. Takes over two things on the
`image` type: its default display's `field_media_image` component is
re-set to the original image with no image style (the image block derives
its `src` from this display, and an image style there would cap every
image block), and the source field gets `daedalus_edit` `disable: true` so
the block's media reference, not the inner image, is what an editor
selects.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_media_image
```

`daedalus_media_vector_type`: `svg_image_field` and its media bundle
submodule, the `vector_image` (SVG) media type with its `field_media_svg`
storage (compared strictly). Grants no permissions. The image block's
media reference allows this bundle, so the image block needs it.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_media_vector_type
```

`daedalus_text_block`, `daedalus_formatted_text_block`,
`daedalus_image_block`: the three block types the builder promotes
(`text`, `formatted_text`, `image`), each from its own module
(`daedalus_edit_text_block`, `daedalus_edit_formatted_text_block`,
`daedalus_edit_image_block`), and `daedalus_sample_values`
(`field_sample_value`, so a newly placed block arrives populated). The
formatted text block ships the `daedalus_edit_formatted_text` text format
(section 6). The image block compares its six shipped objects strictly:
the `image` bundle's form and view display, its two fields and the
`field_image` and `field_image_url` storages on `block_content`; a site
whose survey found either storage under another shape must rename its
own before this step or leave the image block out.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_text_block
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_formatted_text_block
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_image_block
```

`daedalus_layout_block`: the `layout_block` block type, a standalone block
whose display is built with the page builder, for theme regions such as a
header or footer. Configuration only, plus the `layout_builder_restrictions`
module, which it uses to keep a layout block from nesting another. It
converges an existing `layout_block` bundle (revisions on, Edit Mode on)
and its display (promoted blocks, the nesting restriction). Optional: skip
it when the site's header and footer stay as they are.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_layout_block
```

`daedalus_page`: the `daedalus_edit_page` module and a takeover of the
`page` content type. When the site has no `page` bundle it creates one,
layout-enabled, with the shipped default section. When the site has one
(from an older standard profile, from `core/recipes/page_content_type`, or
its own) it converges the bundle and its default display and leaves the
bundle's fields alone: the type is renamed `Page`, its description
replaced, preview switched off, new revisions on, author information
hidden, Edit Mode enabled with the selector tool, `main` and `footer` as
its available menus; the display gets Layout Builder with overrides, the
three promoted block types and the layout-block placement restriction. An
existing display keeps its own fields (the body stays) and starts with an
empty layout; the shipped default section only reaches a display the
recipe created. Apply this when the site's `page` type is meant to become
a builder page. When it is not, or when another type should get the
builder, skip this step and follow section 5.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_page
```

`daedalus_editor_roles`, LAST: core's `administrator_role` recipe (the
`administrator` role with `is_admin: true`, created if missing) and the
`daedalus_editor` role carrying every permission the suite grants. It also
grants `access content` and `view media` to the anonymous and
authenticated roles. It goes last because its grants name the shipped
bundles (`page`, `text`, `formatted_text`, `image`, `layout_block`,
`vector_image`); a grant whose permission does not exist yet is stripped
with a logged error rather than failing the recipe, and re-applying after
the bundle exists grants it. Apply it even when the site will use its own
roles: section 7 copies from it.

```
drush recipe ../recipes/daedalus_recipe/ingredients/daedalus_editor_roles
```

Dependency facts for applying an ingredient on its own: `daedalus_page`
pulls `daedalus_styling` and the three block types; `daedalus_layout_block`
pulls the three block types; `daedalus_image_block` pulls
`daedalus_media_image` and `daedalus_media_vector_type`; every block type
pulls `daedalus_engine` and `daedalus_sample_values`; `daedalus_styling`
pulls `daedalus_blueprint` and `daedalus_engine`. `daedalus_edit_page`
depends on `daedalus_stylish_layouts`, so a page type from the recipe
always carries the styling layer. `daedalus_navigation` is only needed on
a site where the `navigation` module is absent and the shipped sidebar
layout is wanted; the engine's module dependency installs the module
either way.

Then rebuild caches and read the log:

```
drush cr
drush watchdog:show --severity=Error --count=50
```

One error entry is expected after `daedalus_styling` and is not a defect:
the `row` variant fails `STYLE_VALUE_NOT_ON_SCALE` at module install and
lands when the design system activates a moment later. Any
`ACCESS_DENIED` entry from `daedalus_stylish` means the shipped
definitions did not import; run `drush daedalus_stylish:sync` and check
the counts in section 10.

## 5. Enabling the builder on a content type by hand

For any content type other than a `page` type taken over by
`daedalus_page`. The example uses a bundle named `landing_page`; substitute
the survey's answer.

Edit Mode is a per-bundle third-party setting on the bundle config entity,
`daedalus_ui` `status.edit: true`. Only node types and block types carry
that setting, so only those get the builder. The content type's edit form
has a "Page builder" vertical tab with the mode's Enable and Configure
operations; by command:

```
drush php:eval '
$type = \Drupal\node\Entity\NodeType::load("landing_page");
$type->setThirdPartySetting("daedalus_ui", "status", ["edit" => TRUE]);
$type->setThirdPartySetting("daedalus_ui", "initial_mode", "edit");
$type->setThirdPartySetting("daedalus_ui", "modes", ["edit" => ["default_tool" => "selector"]]);
$type->save();'
```

`initial_mode: edit` opens a new node of the type in Edit Mode;
`default_tool: selector` is the resting tool.

Layout Builder with per-item overrides on the bundle's default display.
The builder's buttons are gated on both `layout_builder` `enabled` and
`allow_custom` on the display. On Manage display this is "Use Layout
Builder" plus "Allow each content item to have its layout customized"; by
command (run `drush cr` first when the bundle or its fields were created
moments ago, for example by a recipe: enabling Layout Builder builds the
display's first section from its field blocks, and a stale plugin cache
reports each of them as "block plugin was not found"):

```
drush php:eval '
$display = \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay::load("node.landing_page.default");
$display->enableLayoutBuilder()->setOverridable()->save();'
```

Promoted blocks: the block types the Add panel offers first. Empty means
the panel shows only a settings link. The form is the "Promoted blocks"
tab at `/admin/structure/types/manage/node.landing_page.default/promoted-blocks`
(permission `promote daedalus layout blocks`); by command:

```
drush php:eval '
$display = \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay::load("node.landing_page.default");
$display->setThirdPartySetting("daedalus_layout", "promoted_blocks", [
  "inline_block:image" => "inline_block:image",
  "inline_block:formatted_text" => "inline_block:formatted_text",
  "inline_block:text" => "inline_block:text",
]);
$display->save();'
```

Default section: optional, the container configuration a new item of the
bundle starts from, set on the "Configure default container" tab at
`/admin/structure/types/manage/node.landing_page.default/default-layout-section`
(permission `administer daedalus layout configuration`). Leave it unset
unless the bundle needs a specific starting container.

Preserved fields: fields that stay outside the sections, printed through
the theme's `content` variable and editable in place. On Manage display,
with Layout Builder on, the "Preserved Fields" details lists the bundle's
fields; the setting is `daedalus_layout` `preserved_fields` on the
display. Use it for a title or a summary the theme positions itself.

What the styling layer will create: once the bundle passes both gates (Edit
Mode on the bundle, Layout Builder on the display) the next save of either
creates the `daedalus_stylish_styles` field storage on the entity type and
the field on the bundle, locked and untranslatable. The two commands above
are two saves, so on a fresh bundle the field exists after the second. A
bundle that must not be styled opts out with the "Enable styling"
checkbox in the same "Page builder" tab; the opt-out is recorded in
`daedalus_stylish.settings` `opt_outs` and auto-enablement never
re-defaults it. Confirm:

```
drush php:eval 'echo implode("\n", \Drupal::configFactory()->listAll("field.field.node.landing_page.")), "\n";'
```

Permissions the editing role needs for this bundle: `use daedalus ui edit
mode`, `access inline editing`, `create landing_page content`,
`edit any landing_page content` (or `edit own`), and
`configure editable landing_page node layout overrides` (core's
bundle-scoped Layout Builder permission, which the sidebar block form
route demands) together with `create and edit custom blocks`. Section 7
has the whole list.

The front page. A request to `/` never enters Edit Mode: the edit cookie is
scoped to the page's path, and a cookie on `/` would apply to every route.
At `/` the toolbar's Edit Mode button becomes a link to the node's own URL
(its alias when it has one, otherwise `/node/N`), and editing happens
there. Tell the site owner: edit the homepage at its alias.

## 6. Text formats

`daedalus_edit` mints an identity class (`s-` and three characters) onto
every `p`, `h2`, `h3`, `h4`, `blockquote`, `ul`, `ol` and `li` it stores,
at entity save, on every formatted text field of every fieldable entity.
The class is what lets an element be selected and styled on its own. A
tag the format allows without an unrestricted `class` attribute is skipped:
the save succeeds, the element renders, and it cannot be addressed or
styled individually under that format. Tags a format strips entirely are
ignored.

The status report says which formats and tags are affected, as a warning
titled "Daedalus Edit identity vocabulary" whose description names the
formats:

```
drush core:requirements --severity=1 --fields=title,description
```

Two ways to resolve it. Widen the format: on the format's edit form
(`/admin/config/content/formats/manage/basic_html`) allow `class` on the
vocabulary tags, so `allowed_html` carries `<p class> <h2 id class>
<h3 id class> <h4 id class> <blockquote cite class> <ul type class>
<ol start type class> <li class>`; on a CKEditor 5 format the editor keeps
`allowed_html` in step with its plugins, so add the tags through the
Source Editing plugin's manually editable tags rather than by editing the
config directly, then re-run the status report. Or accept it: content in
that format is editable in place but its paragraphs and headings are not
individually stylable, which is a fair choice for an article body that
will never see the styling sidebar.

The shipped format, `daedalus_edit_formatted_text`, arrives with the
formatted text block and allows `class` on every vocabulary tag. It is
bound to the formatted text block's field and is what builder content is
written in; the site's own formats are not changed by any ingredient. The
`daedalus_editor` role holds `use text format daedalus_edit_formatted_text`;
a site role that edits formatted text blocks needs it too, or CKEditor
degrades to a plain textarea for that role.

## 7. Roles

`daedalus_editor_roles` creates `daedalus_editor`, the tested permission
contract for editing pages without configuring anything. A site that
already has an editing role copies the grants across rather than adopting
the role. Print them:

```
drush php:eval 'echo implode("\n", \Drupal\user\Entity\Role::load("daedalus_editor")->getPermissions()), "\n";'
```

Then grant the same list to the site's role, substituting the site's
bundles. The grants, in groups: entering Edit Mode and editing in place
(`use daedalus ui edit mode`, `access inline editing`, `access navigation`,
`promote daedalus layout blocks`); the sidebar block form path
(`configure editable page node layout overrides`, `configure editable
layout_block block_content layout overrides`, `create and edit custom
blocks`); saving styles as materials (`create variants`, `update variants`,
`create swatches`, `update swatches`, `create animations`,
`update animations`); the shipped format
(`use text format daedalus_edit_formatted_text`); the shipped bundles
(`create page content`, `edit any page content`, `delete any page content`,
and `create <bundle> block content`, `edit any <bundle> block content`,
`delete any <bundle> block content` for `text`, `formatted_text`, `image`
and `layout_block`); vector media (`create vector_image media`,
`edit own vector_image media`, `delete own vector_image media`).

The per-bundle strings for a content type the site enabled by hand are
`create <bundle> content`, `edit any <bundle> content`,
`delete any <bundle> content` and
`configure editable <bundle> node layout overrides`. For example, for an
`editor` role and the `landing_page` bundle:

```
drush role:perm:add editor 'use daedalus ui edit mode,access inline editing,promote daedalus layout blocks,create and edit custom blocks,use text format daedalus_edit_formatted_text,create text block content,edit any text block content,delete any text block content,create formatted_text block content,edit any formatted_text block content,delete any formatted_text block content,create image block content,edit any image block content,delete any image block content'
drush role:perm:add editor 'create landing_page content,edit any landing_page content,delete any landing_page content,configure editable landing_page node layout overrides'
```

SVG upload is deliberately restricted: an SVG is an active document, and
`daedalus_media_vector_type` grants nothing. Grant the `vector_image`
permissions to trusted roles only.

## 8. Keeping the site's theme

Nothing in the modules binds to a theme name; the anchors are core's. What
a theme must provide, then what it should port for parity with Daedalus
Theme, then what is optional.

Hard requirements:

- `html.html.twig` prints `{{ page_top }}`, `{{ page_bottom }}` and
  `{{ html_attributes }}`, with `head-placeholder` before `css-placeholder`.
- Core's `.dialog-off-canvas-main-canvas` wrapper stays around the page.
- A Twig theme engine.
- The `navigation` module enabled (the engine ingredient ensures it).
- Entity, block and field templates print `{{ attributes }}` on the outer
  element. Check the log after the first Edit Mode visit for the
  `daedalus_ui` warning "did not deliver the entity UI attributes"; each
  entry names a template to fix.

Parity with the reference theme:

- Port `daedalus_theme/css/layout.css` (in the `drupal/daedalus_theme`
  package): full-bleed section wrappers keyed on
  `[data-selectable^="container:"]`, theme-agnostic.
- `daedalus_stylish_layers` wraps all pipeline CSS, the theme included, in
  the `site` cascade layer, and unlayered skin rules beat layered rules
  regardless of specificity. A theme whose typography or colour rules must
  survive the skin exempts the asset per entry in its `*.libraries.yml`,
  or moves those values into a skin, or leaves `daedalus_stylish_layers`
  uninstalled (apply the styling modules individually instead of
  `daedalus_styling` in that case):

  ```yaml
  css:
    theme:
      css/typography.css: { daedalus_stylish_layers_exempt: true }
  ```

- `daedalus_stylish.settings` `container_roots` emits the page-level
  width-band query container rule wherever container breakpoint conditions
  are styled. Turn it off only when the theme manages its own query
  container:

  ```
  drush config:set -y --input-format=yaml daedalus_stylish.settings container_roots false
  ```

Optional:

- `MYTHEME.page_builder.block_plugin.system_branding_block.yml` and
  `MYTHEME.page_builder.block_plugin.navigation_menu.yml` in the theme
  directory, copied from `daedalus_theme` and re-keyed to the classes the
  theme's own templates emit; without them branding and menu blocks style
  as whole blocks only. `MYTHEME.page_builder.yml` in the theme directory
  re-declares the `.field__label`, `.field__items` and `.field__item` shell
  when the theme's `field.html.twig` uses other classes.
- Block placements are per theme; nothing the ingredients above ship
  places a block in the site's theme.

## 9. After applying

```
drush cr
drush core:requirements --severity=1
```

Read the status report for the two Daedalus entries: "Daedalus Edit
identity vocabulary" (section 6) and the formatted text block's text
format check, which errors only when `daedalus_edit_formatted_text` has
been disabled.

Existing Layout Builder sections built with core layouts keep rendering
exactly as before. Once `daedalus_stylish_layouts` is on, both layout
pickers offer only `daedalus_stylish_container`, so a new section is
always a container and an existing core-layout section cannot be re-picked
or restyled as one. No transform from core layouts to containers exists;
a page that should be fully stylable is rebuilt section by section in
Edit Mode.

Content moderation and workspaces are untested with the builder. Nothing
in the modules handles moderation states; treat a moderated bundle as
unsupported until tried on a copy of the site.

`daedalus_stylish` defaulted styling on for every bundle that passed the
gate at install time. Check which ones, and opt out any that should not
be styled (section 5):

```
drush php:eval '
$storages = \Drupal::entityTypeManager()->getStorage("field_config")->loadByProperties(["field_name" => "daedalus_stylish_styles"]);
foreach ($storages as $field) { echo $field->getTargetEntityTypeId(), ".", $field->getTargetBundle(), "\n"; }'
```

## 10. Verification checklist

Run every step; each has a pass condition.

Shipped definitions landed (pass: one skin, `olivero` present, at least
sixteen variants, five animations):

```
drush php:eval '
foreach (["daedalus_skin", "daedalus_variant", "daedalus_animation"] as $type) {
  echo $type, ": ", count(\Drupal::entityTypeManager()->getStorage($type)->loadMultiple()), "\n";
}
echo "olivero skin present: ", \Drupal::entityTypeManager()->getStorage("daedalus_skin")->loadByProperties(["machine_name" => "olivero"]) ? "yes" : "no", "\n";'
```

A node in the site's own text format still saves (pass: `saved OK`).
Substitute a real node ID from the survey's content type:

```
drush php:eval '
$node = \Drupal\node\Entity\Node::load(1);
$node->save();
echo "saved OK: ", $node->label(), "\n";'
```

The site still serves (pass: `200`):

```
curl -s -o /dev/null -w '%{http_code}\n' "$SITE/"
```

Edit Mode is reachable at the page's alias as an administrator (pass: the
page carries the Edit Mode button, `daedalus-ui-mode-button`, and with the
mode cookie set carries the top bar, `edit-mode-global-top-bar`). Log in
through a one-time link and request the alias twice, once plainly and once
with the path-scoped mode cookie a click on the button would set:

```
SITE=https://example.com
ALIAS=/about
LOGIN=$(drush uli --uri="$SITE" --no-browser)
curl -s -c cookies.txt -b cookies.txt -L -o /dev/null "$LOGIN"
curl -s -b cookies.txt "$SITE$ALIAS" | grep -c 'daedalus-ui-mode-button'
curl -s -b cookies.txt -H "Cookie: navigationMode=edit" "$SITE$ALIAS" | grep -c 'edit-mode-global-top-bar'
```

Both counts are at least 1. A count of 0 on the first request means the
route is vetoed: the bundle has no Edit Mode (section 5), the user cannot
edit the node, or the path is `/` (edit the homepage at its alias).

Place a block and style it, in a browser as the editor. Open the alias,
click Edit Mode in the top bar, open the Add panel, place a Text block
(it arrives with sample text), click it, open the styling sidebar and
change one property such as its background, then save. Confirm from the
command line that the block exists and the page now carries styles (pass:
both counts at least 1; substitute the node ID):

```
drush php:eval '
echo "text blocks: ", count(\Drupal::entityTypeManager()->getStorage("block_content")->loadByProperties(["type" => "text"])), "\n";
$node = \Drupal\node\Entity\Node::load(1);
echo "styled: ", ($node->hasField("daedalus_stylish_styles") && !$node->get("daedalus_stylish_styles")->isEmpty()) ? 1 : 0, "\n";'
```

When any step fails, the log names the reason:

```
drush watchdog:show --severity=Error --count=50
```
