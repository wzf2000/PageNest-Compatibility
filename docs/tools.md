# Optional tools host

The optional tools host is disabled by default. It provides bound
page rendering, the public index contract, shortcode/block aliases, safe editor previews,
access checks and provider asset loading. It ships no browser tools, games, Node verifier,
private storage, installers or implementation discovery service.

Enable it explicitly in server-owned WordPress configuration:

```php
define('PAGENEST_COMPANION_TOOLS_ENABLED', true);
define('PAGENEST_COMPANION_TOOLS_FILE', '/private/config/tools.json');
```

The JSON file must be readable outside the web root. The legacy `SITE_TOOLS_PROFILE_FILE`
constant remains a fallback only when the module is explicitly enabled. Missing or invalid
profiles disable this host. `allowed_hosts` must include the current WordPress host. Other
Companion modules do not depend on tools configuration.

WP Tool Integration retains ownership while its class or active/network plugin entry is
present, irrespective of normal plugin loading order. Companion waits until `plugins_loaded`
(priority 30) before defining compatibility aliases. Deactivate the old owner before enabling
the new composition. This performs no database migration, installation or cleanup. A theme,
XP provider, MarkBridge and tool packs remain independent.

## Independent implementation packs

Examples of independently maintained trusted plugins include:

- `pagenest-basic-tools`: text counter and random number generator, PHP 8.0; browser only.
- `pagenest-game-tools`: four games, replay verifier, personal game records, rankings and
  local maintenance CLI support, PHP 8.2; Node is required only for server replay verification.
- `pagenest-private-tools`: personal drafts/local jobs and archive/privacy lifecycle, PHP 8.2.
  Loading this plugin never creates tables or enables writes. Private services still require
  their explicit profile flag and existing schema; isolated installers keep their safeguards.

These packages are versioned and built independently. Use each implementation repository’s
README and release manifest for its build command and current version; none is part of the Companion ZIP. External catalog
entries work without any implementation pack. Basic tools need no `games`, `services`,
`maintenance` configuration or Node runtime. Existing production-used implementations remain
maintained source, including their original deterministic engines and replay fingerprints.

## Profile and catalog

The independent profile keeps schema version 1 and existing field names:

```json
{
  "schema_version": 1,
  "allowed_hosts": ["example.org"],
  "shortcodes": ["site_tool"],
  "blocks": ["site/tool"],
  "rest_namespaces": ["site-tools/v1"],
  "catalog": { "schema_version": 1, "categories": [], "tools": [] }
}
```

Shortcode/block/REST lists can be empty. `games`, `services` and `maintenance` are optional;
implementation packs validate their own configured sections. If a pack's configuration is
invalid, that provider fails closed while valid unrelated providers and external entries
remain usable. Catalog schema errors still reject the catalog as a whole.

Entries retain required schema-1 fields: `schema_version`, `slug`, `kind`, `title`,
`description`, `category`, `version`, `status`, `binding`, `listed`, `access`, `assets`,
`input_schema`, `render`, `service`, `data`, `limits`, `owner`, `maintenance`. Internal entries
bind `post_id` and `post_name` to a real WordPress page. External entries use an HTTPS
`canonical_url`, null binding/render/service and no assets. A source identity such as
`content/text-count.md` is optional for new entries and remains unchanged where configured.
A `template` field is rejected: data cannot select PHP files or executable callbacks.

`render` identifies an installed registered handler. A missing handler retains its catalog
entry for maintenance but renders an unavailable message and is excluded from the public
index. Healthy entries remain available. Public listings require an active public entry and
published password-free bound page. Rendering separately checks page visibility/passwords.
Private APIs additionally require login, WordPress object/capability checks and a REST nonce;
a nonce never grants access by itself.

The `SiteTools\Profile`, `Registry`, `Host`, `Assets` and `Providers` aliases preserve existing
content and XP/maintenance consumers when Companion owns the host. `SiteTools\VERSION`
identifies the host (1.2.0); provider manifests independently identify pack versions.
The catalog/index contract does not gain internal availability fields.

## Trusted PHP provider API

An installed plugin adds an action to `pagenest_tools_register_providers` and calls
`PageNest\Tools\Providers::register($id, $definition)`. Register before the host starts.
Definitions contain:

- `root`: trusted absolute local asset directory, outside uploads; no streams or traversal.
- `url`: public HTTP(S) URL for that same asset directory.
- `manifest`: map from logical JS/CSS names to existing built names under the root. Paths
  cannot traverse or escape the root, including through symlinks.
- `handlers`: unique identifier map. Each handler has PHP callable `validate($tool)` and
  `render($tool, $editorPreview)`; optional `assets`, `legacy_styles` and `archive` callbacks.
  Private handlers set `requires_service_access` to `true`.
- Optional `validate_profile($data)`, `start()` and `version`. `start()` loads implementation
  code only after valid profile/host approval. It must not install data or activate services.

Callbacks come only from installed trusted PHP; no profile field supplies callables, provider
roots, PHP paths or downloaded code. Duplicate providers/handlers reject atomically. Handler
asset handles include provider identity so separate packs do not collide. A failed provider
start or profile validator disables its handlers. Existing packs catch registration failures
and log only the pack ID. There is no uploads execution or runtime download loader.

Canonical host source lives in `modules/tools`. The standalone integration's generated copy
is checked byte-for-byte by `python3 tools/sync-tool-core.py --check`; edit the canonical
source and regenerate, rather than maintaining two implementations. `Diagnostics::index()`,
`diagnose()` and `preflight()` are read-only framework APIs available even with no game pack.
The shared `LocalIsolation` guard performs the existing exact synthetic-site ownership proof;
private services use it without loading game maintenance.

## Local verification

Run `npm run test:tools` in this repository (generic provider fixtures are self-contained). It covers empty/external/basic catalogs, missing handlers, public
and private page access, registration/path constraints, invalid pack isolation, default-off
and old-owner discovery. Real WordPress migration/browser tests belong to the isolated task
harness. Official Companion packaging still requires committed source HEAD; this feature
does not weaken that guard or authorize publication/deployment.
