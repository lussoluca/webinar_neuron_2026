<?php

declare(strict_types=1);

namespace App\Neuron\Tool;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherTool extends Tool {
    private const string GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const string FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';

    private HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null) {
        parent::__construct('get_weather', 'Get the current weather for a city.');
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    protected function properties(): array{
        return [
            new ToolProperty(
                name: 'city',
                type: PropertyType::STRING,
                description: 'The name of the city.',
                required: true,
            ),
            new ToolProperty(
                name: 'date',
                type: PropertyType::STRING,
                description: 'The date for which to get the weather, in YYYY-MM-DD format.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $city, string $date): string {
        try {
            $location = $this->geocode($city);
            if ($location === null) {
                return json_encode([
                    'error' => sprintf('City "%s" not found.', $city),
                ]);
            }

            $weather = $this->forecast($location['latitude'], $location['longitude'], $date);
            if ($weather === null) {
                return json_encode([
                    'error' => sprintf('Weather not available for "%s" on %s.', $city, $date),
                ]);
            }

            return json_encode([
                'city' => $location['name'],
                'date' => $date,
                'temp_min' => $weather['temp_min'],
                'temp_max' => $weather['temp_max'],
                'unit' => '°C',
                'weather_code' => $weather['weather_code'],
                'weather_code_standard' => 'WMO',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode([
                'error' => 'Failed to retrieve weather: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve an Italian city name to coordinates via Open-Meteo geocoding.
     *
     * @return array{name: string, latitude: float, longitude: float}|null
     */
    private function geocode(string $city): ?array {
        $response = $this->httpClient->request('GET', self::GEOCODING_URL, [
            'query' => [
                'name' => $city,
                'count' => 1,
                'language' => 'it',
                'country' => 'IT',
                'format' => 'json',
            ],
        ]);

        $data = $response->toArray(false);
        $result = $data['results'][0] ?? null;
        if ($result === null) {
            return null;
        }

        return [
            'name' => $result['name'],
            'latitude' => (float) $result['latitude'],
            'longitude' => (float) $result['longitude'],
        ];
    }

    /**
     * Fetch daily forecast for a single date.
     *
     * @return array{temp_min: float, temp_max: float, weather_code: int}|null
     */
    private function forecast(float $latitude, float $longitude, string $date): ?array {
        $response = $this->httpClient->request('GET', self::FORECAST_URL, [
            'query' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'daily' => 'temperature_2m_max,temperature_2m_min,weather_code',
                'timezone' => 'Europe/Rome',
                'start_date' => $date,
                'end_date' => $date,
            ],
        ]);

        $data = $response->toArray(false);
        $daily = $data['daily'] ?? null;
        if ($daily === null || empty($daily['time'])) {
            return null;
        }

        return [
            'temp_min' => $daily['temperature_2m_min'][0],
            'temp_max' => $daily['temperature_2m_max'][0],
            'weather_code' => (int) $daily['weather_code'][0],
        ];
    }
}
