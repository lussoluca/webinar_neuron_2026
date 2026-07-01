<?php

declare(strict_types=1);

namespace App\Neuron\Rag;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

/**
 * Structured output for the LLM reranker: the candidate document indexes
 * ordered from most to least relevant to the query.
 */
final class Ranking
{
    /**
     * @var list<int>
     */
    #[SchemaProperty(description: 'The indexes of the candidate documents ordered from most to least relevant to the query.')]
    #[ArrayOf('integer')]
    public array $list;
}
