<?php

declare(strict_types=1);

namespace App\Neuron\Tool;

use NeuronAI\Tools\Tool;

class DayTool extends Tool {

    public function __construct() {
        parent::__construct('get_day', 'Return the current day of the week in a specific ISO date (YYYY-MM-DD).');
    }

    public function __invoke(): string {
        return json_encode(['today' => date('Y-m-d')]);
    }
}
