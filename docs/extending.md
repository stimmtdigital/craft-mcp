# Extending

Since 2.0 the plugin is not a server, so there is nothing to register tools with here. Add your own tools to Craft's MCP server directly, the same way this plugin does.

## Adding tools to Craft's MCP server

Register a capability class on `craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES`:

```php
use craft\events\RegisterComponentTypesEvent;
use craft\services\Mcp;
use yii\base\Event;

Event::on(Mcp::class, Mcp::EVENT_REGISTER_CAPABILITY_TYPES, function (RegisterComponentTypesEvent $event) {
    $event->types[] = \myvendor\myplugin\mcp\ReportTools::class;
});
```

A capability class is a plain class whose methods carry the MCP SDK's `#[McpTool]` (or `#[McpResource]`) attribute. Craft's own attributes gate them:

- `craft\mcp\attributes\RequiresAdmin`: admin users only, and over HTTP the `mcp:admin` scope
- `craft\mcp\attributes\RequiresPermission('permissionHandle')`: the acting user needs that permission (repeatable)

A tool without `readOnlyHint: true` in its annotations needs the `mcp:write` scope over HTTP; a read-only one needs `mcp:read`.

To act as the user the client connected as, take `craft\mcp\McpActor` in the constructor and call `user()`:

```php
namespace myvendor\myplugin\mcp;

use craft\mcp\McpActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

final readonly class ReportTools
{
    public function __construct(private McpActor $actor)
    {
    }

    #[McpTool(
        name: 'myvendor_reports_list',
        description: 'List the reports the current user may see.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function listReports(
        #[Schema(description: 'Only reports updated after this date.')]
        ?string $since = null,
    ): array {
        $user = $this->actor->user();
        // ...
    }
}
```

### Prefix your tool names

Give every tool a vendor prefix, as this plugin does with `stimmt_`. Craft refuses to build its MCP server when two capabilities share a name, so a collision with one of Craft's tools, or another plugin's, takes down the whole server, not just your tool.

## Hiding capabilities

`craft\services\Mcp::EVENT_DISCOVERING_CAPABILITY` fires for every tool, resource and prompt that already passed Craft's permission and scope checks. Set `$event->allowed = false` to hide it from the acting user (`$event->user`). This plugin uses the same event to apply its edition, `enableDangerousTools` and `disabledTools`.

## Field translators

The plugin's own extension point is for the [payload format](tools/content.md#payload-format) that its Pro write tools accept and its content reads return. Relations, Matrix, Link and zone-keeping Date fields are translated between natural keys and ids out of the box. A third-party field that stores element ids in a format of its own can register a translator:

```php
use stimmt\craft\Mcp\events\RegisterFieldTranslatorsEvent;
use stimmt\craft\Mcp\Mcp;
use yii\base\Event;

Event::on(Mcp::class, Mcp::EVENT_REGISTER_FIELD_TRANSLATORS, function (RegisterFieldTranslatorsEvent $event) {
    $event->translators[] = new \myvendor\myplugin\mcp\MyFieldTranslator();
});
```

A translator implements `stimmt\craft\Mcp\elements\refs\FieldTranslator`:

| Method | Purpose |
|--------|---------|
| `handles(FieldInterface $field): bool` | Whether this translator is responsible for the field |
| `toKeys(FieldInterface $field, mixed $value, Context $context): mixed` | Turn the stored value's ids into natural keys, for reads |
| `toIds(FieldInterface $field, mixed $value, Context $context): mixed` | Turn natural keys back into ids, for writes |

`$context->site` is the site handle the call reads or writes on. Report a key that cannot be resolved with `$context->warn(new Warning(...))` rather than dropping it or guessing. Registered translators take precedence over the built-in ones; values of fields no translator handles pass through unchanged.
