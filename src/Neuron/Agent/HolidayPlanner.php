<?php

declare(strict_types=1);

namespace App\Neuron\Agent;

use App\Neuron\Tool\A2UIRenderTool;
use App\Neuron\Tool\DayTool;
use App\Neuron\Tool\RestaurantTool;
use App\Neuron\Tool\WeatherTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;

class HolidayPlanner extends Agent {

    public function __construct(
        private readonly string $anthropicApiKey,
        private readonly RestaurantTool $restaurantTool,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface {
        return new Anthropic(
            key: $this->anthropicApiKey,
            model: 'claude-sonnet-5',
        );
    }

    protected function instructions(): string {
        return (string) new SystemPrompt(
            background: ['You are an expert in creating amazing holidays'],
            steps: [
                'Get information about the destination.',
                'Find interesting things to do.',
                'Look for restaurants with the find_restaurants tool. It already '
                . 'returns a ready-to-render A2UI restaurant surface, so do NOT '
                . 'pass its result to render_a2ui and do NOT rebuild it as text.',
                'Use get_day tool to retrieve today\'s date and get_weather tool '
                . 'to retrieve weather information.',
                'Whenever a piece of information is better shown as a visual '
                . 'widget than as plain text (e.g. weather, a list of places, a '
                . 'restaurant card, a day-by-day itinerary), call the render_a2ui '
                . 'tool to render it. You decide if and when a widget helps, and '
                . 'you design its layout by composing components from the A2UI '
                . 'catalog described in the render_a2ui tool.',
            ],
            output: ['Write a detailed holiday plan for the user.'],
        );
    }

    protected function tools(): array {
        return [
            DayTool::make(),
            WeatherTool::make(),
            $this->restaurantTool,
            A2UIRenderTool::make(),
        ];
    }

}
