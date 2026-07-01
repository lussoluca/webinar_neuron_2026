<?php

declare(strict_types=1);

namespace App\Neuron\Tool;

use App\Neuron\A2UI\A2UISchemaManager;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Lets the LLM render a generative UI by composing A2UI components from the
 * frontend catalog. The model decides the widget shape; this tool wraps the
 * chosen component tree into the A2UI v0.9.1 wire envelope (createSurface +
 * updateComponents) that the frontend renderer consumes.
 *
 * The tool name (`render_a2ui`) is significant: the frontend's A2UIDetector
 * treats results from this tool as A2UI surfaces.
 *
 * @see https://a2ui.org/specification/v0.9.1-a2ui/
 */
class A2UIRenderTool extends Tool
{
    /**
     * Catalog id advertised to the client. MUST match the id the frontend
     * registers (see `A2UI_CATALOG_ID` in the sf-chat client); the client's
     * MessageProcessor rejects a surface whose catalogId is unknown.
     */
    public const string CATALOG_ID = 'https://sparkfabrik.com/catalogs/sf-chat/v1/catalog.json';

    /** A2UI protocol version discriminant carried by every message. */
    public const string VERSION = 'v0.9';

    /**
     * Representational rules that are NOT derivable from the catalog schema:
     * the flat wire shape of a component, how container slots reference
     * children, the Action shape, and how dynamic property values may be
     * literals, data bindings or function calls. The component names,
     * properties and enum values are appended from the catalog schema by the
     * A2UISchemaManager, so they never drift from the frontend export.
     */
    private const string ROLE_DESCRIPTION = <<<'ROLE'
        Render a rich UI widget for the user by composing A2UI components.
        Call this to present structured information (e.g. a weather result)
        as a visual card instead of plain text.

        Each component is a FLAT object: the type is a `component` string
        and its properties sit directly on the object:
          { "id": "<unique-id>", "component": "<Type>", ...props }

        Container components reference their children by id: Card/Button use
        `child` (one id string); Column/Row/List use `children` (array of id
        strings).

        An Action (e.g. a Button `action`) is one of:
        - Server event:   { "event": { "name": "<action>", "context"?: { <key>: <value> } } }
        - Local function: { "functionCall": { "call": "<fn>", "args": { ... } } }

        Property value rules:
        - Dynamic props (text, url, value, label, description) take a literal
          (e.g. "Hello"), a data binding { "path": "/some/pointer" }, or a
          function call { "call": "...", "args": {...} }. For static content
          just use the literal directly — do NOT wrap it.
        - variant / fit / direction / align / justify enums are plain strings.

        The available components, their properties and allowed enum values are
        defined by the catalog schema below.
        ROLE;

    public function __construct()
    {
        $manager = new A2UISchemaManager();

        // The wire envelope (buildSurface) is static and stamps self::CATALOG_ID,
        // so the const must stay in sync with the vendored catalog it documents.
        if ($manager->catalogId() !== self::CATALOG_ID) {
            throw new \RuntimeException(sprintf(
                'A2UI catalog id mismatch: tool advertises "%s" but catalog declares "%s".',
                self::CATALOG_ID,
                $manager->catalogId(),
            ));
        }

        parent::__construct(
            'render_a2ui',
            $manager->generateSystemPrompt(self::ROLE_DESCRIPTION),
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'components',
                type: PropertyType::STRING,
                description: 'JSON array of flat component definitions. Each item is '
                    . '{"id": "<unique-id>", "component": "<Type>", ...props}. '
                    . 'Example: [{"id":"card","component":"Card","child":"t"},'
                    . '{"id":"t","component":"Text","text":"Hi","variant":"h3"}].',
                required: true,
            ),
            new ToolProperty(
                name: 'root',
                type: PropertyType::STRING,
                description: 'The id of the top-level (root) component to render. '
                    . 'It is re-keyed to the conventional id "root" in the output.',
                required: true,
            ),
            new ToolProperty(
                name: 'surfaceId',
                type: PropertyType::STRING,
                description: 'Optional unique id for this surface. Defaults to a value derived from root.',
                required: false,
            ),
        ];
    }

    public function __invoke(string $components, string $root, ?string $surfaceId = null): string
    {
        $decoded = json_decode($components, true);

        if (!\is_array($decoded)) {
            return json_encode([
                'error' => 'The "components" argument must be a JSON array of A2UI component definitions.',
            ], \JSON_THROW_ON_ERROR);
        }

        $components = array_values($decoded);

        // A2UI v0.9.1 requires the tree root to be the component with id "root".
        // Re-key the designated root (and any references to it) accordingly.
        if ('root' !== $root) {
            $components = $this->rekeyRoot($components, $root);
        }

        return json_encode(
            self::buildSurface($components, $surfaceId ?? 'surface-' . $root),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Wrap an already-resolved (root re-keyed) flat component list into the
     * A2UI v0.9.1 wire envelope: createSurface + updateComponents.
     *
     * Shared with tools that build a fixed surface programmatically instead of
     * letting the LLM compose one (e.g. RestaurantTool).
     *
     * @param array<int, array<string, mixed>> $components
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buildSurface(array $components, string $surfaceId): array
    {
        return [
            [
                'version' => self::VERSION,
                'createSurface' => [
                    'surfaceId' => $surfaceId,
                    'catalogId' => self::CATALOG_ID,
                ],
            ],
            [
                'version' => self::VERSION,
                'updateComponents' => [
                    'surfaceId' => $surfaceId,
                    'components' => $components,
                ],
            ],
        ];
    }

    /**
     * Rename the component whose id is $oldRoot to "root", and rewrite every
     * `child` / `children` reference that pointed at it.
     *
     * A2UI v0.9.1 requires the tree root component to have the id "root". The
     * LLM is free to pick any root id via the `root` argument, so this
     * normalizes it server-side rather than trusting the model to obey the
     * naming rule on every call.
     *
     * Only `child` / `children` references are rewritten. If the catalog ever
     * gains another prop that references a component by id (e.g. an action
     * target or an id-based data binding), those references must be handled
     * here too, otherwise they will still point at the old root id.
     *
     * @param array<int, array<string, mixed>> $components
     *
     * @return array<int, array<string, mixed>>
     */
    private function rekeyRoot(array $components, string $oldRoot): array
    {
        foreach ($components as &$component) {
            if (!\is_array($component)) {
                continue;
            }

            if (($component['id'] ?? null) === $oldRoot) {
                $component['id'] = 'root';
            }

            if (($component['child'] ?? null) === $oldRoot) {
                $component['child'] = 'root';
            }

            if (isset($component['children']) && \is_array($component['children'])) {
                $component['children'] = array_map(
                    static fn ($childId) => $childId === $oldRoot ? 'root' : $childId,
                    $component['children'],
                );
            }
        }
        unset($component);

        return $components;
    }
}
