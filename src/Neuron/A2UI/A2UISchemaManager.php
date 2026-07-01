<?php

declare(strict_types=1);

namespace App\Neuron\A2UI;

/**
 * Builds the LLM-facing description of the A2UI component catalog from the
 * catalog JSON the frontend exports, instead of hand-maintaining a parallel
 * component list in prose.
 *
 * This is a focused PHP port of the upstream Python A2uiSchemaManager
 * (google/A2UI, agent_sdks/python/a2ui_agent/src/a2ui/schema). It mirrors:
 *   - A2uiCatalog::render_as_llm_instructions() -> renderAsLlmInstructions()
 *   - A2uiSchemaManager::generate_system_prompt() -> generateSystemPrompt()
 *
 * Divergence from upstream: the upstream renderer also dumps the
 * server_to_client and common_types wire schemas, because there the model
 * emits raw A2UI messages wrapped in <a2ui-json> tags. Here the model only
 * supplies a flat component list to the render_a2ui tool and PHP wraps it in
 * the createSurface/updateComponents envelope, so only the catalog schema is
 * rendered. The catalog JSON is the single source of truth for component
 * names, properties and enum values.
 *
 * @see https://github.com/google/A2UI/blob/main/agent_sdks/python/a2ui_agent/src/a2ui/schema/catalog.py
 * @see https://github.com/google/A2UI/blob/main/agent_sdks/python/a2ui_agent/src/a2ui/schema/manager.py
 */
final class A2UISchemaManager
{
    /** Markers wrapping the schema block, matching the upstream constants. */
    public const string SCHEMA_BLOCK_START = '---BEGIN A2UI JSON SCHEMA---';
    public const string SCHEMA_BLOCK_END = '---END A2UI JSON SCHEMA---';

    /**
     * Workflow rules the model must honour, adapted from the upstream
     * DEFAULT_WORKFLOW_RULES for this tool-calling flow (the model calls
     * render_a2ui rather than emitting raw <a2ui-json> blocks). The component
     * ordering rule is preserved verbatim in intent: the frontend streaming
     * parser renders the tree incrementally and requires it.
     */
    public const string DEFAULT_WORKFLOW_RULES = <<<'RULES'
        The composed UI MUST follow these rules:
        - Build the widget only from the components defined in the catalog schema below.
        - Each component is a FLAT object whose type is a `component` string and whose
          properties sit directly on the object: { "id": "<unique-id>", "component": "<Type>", ...props }.
        - Top-Down Component Ordering: within the component list the `root` component MUST
          be the FIRST element, and every parent MUST appear before its child components.
          This ordering lets the frontend streaming parser render the UI incrementally.
        RULES;

    /** @var array<string, mixed> The decoded catalog: { catalogId, components: {...} }. */
    private readonly array $catalog;

    /**
     * @param string|null $catalogPath Path to the exported catalog JSON.
     *                                 Defaults to the vendored copy under resources/a2ui.
     */
    public function __construct(?string $catalogPath = null)
    {
        $path = $catalogPath ?? \dirname(__DIR__, 3) . '/resources/a2ui/catalog.json';

        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new \RuntimeException("A2UI catalog not found at {$path}.");
        }

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['catalogId'], $decoded['components'])) {
            throw new \RuntimeException("A2UI catalog at {$path} is missing catalogId or components.");
        }

        $this->catalog = $decoded;
    }

    /** The catalog id advertised to the client, read from the catalog itself. */
    public function catalogId(): string
    {
        return (string) $this->catalog['catalogId'];
    }

    /** @return list<string> The component names defined in the catalog. */
    public function componentNames(): array
    {
        return array_keys($this->catalog['components']);
    }

    /**
     * Renders the catalog schema as an LLM instruction block, mirroring
     * A2uiCatalog::render_as_llm_instructions(). The catalog JSON is emitted
     * minified (matching Python's separators=(",", ":")) between the schema
     * block markers.
     */
    public function renderAsLlmInstructions(): string
    {
        $catalogJson = json_encode(
            $this->catalog,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        return implode("\n\n", [
            self::SCHEMA_BLOCK_START,
            "### Catalog Schema:\n{$catalogJson}",
            self::SCHEMA_BLOCK_END,
        ]);
    }

    /**
     * Assembles the final instruction text, mirroring
     * A2uiSchemaManager::generate_system_prompt(): the role description, then
     * the workflow rules, then (optionally) the rendered catalog schema.
     */
    public function generateSystemPrompt(
        string $roleDescription,
        string $workflowDescription = '',
        bool $includeSchema = true,
    ): string {
        $workflow = self::DEFAULT_WORKFLOW_RULES;
        if ('' !== $workflowDescription) {
            $workflow .= "\n{$workflowDescription}";
        }

        $parts = [
            $roleDescription,
            "## Workflow Description:\n{$workflow}",
        ];

        if ($includeSchema) {
            $parts[] = $this->renderAsLlmInstructions();
        }

        return implode("\n\n", $parts);
    }
}
