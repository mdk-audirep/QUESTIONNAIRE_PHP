<?php

namespace Questionnaire\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class OpenAIClient
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => 60,
        ]);
    }

    public function send(array $payload): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . Env::get('OPENAI_API_KEY'),
            'Content-Type' => 'application/json'
        ];

        try {
            $response = $this->client->post('responses', [
                'headers' => $headers,
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'stream' => true
            ]);
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('Appel OpenAI impossible : ' . $exception->getMessage(), 0, $exception);
        }

        $payload = $this->decodeStream((string) $response->getBody());
        if (!is_array($payload)) {
            throw new \RuntimeException('Réponse OpenAI illisible');
        }

        return $payload;
    }

    private function decodeStream(string $raw): array
    {
        $events = preg_split('/\r?\n\r?\n/', trim($raw));
        $output = [];
        $fallback = null;

        foreach ($events as $event) {
            $lines = preg_split('/\r?\n/', trim($event));
            foreach ($lines as $line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }

                $json = trim(substr($line, 5));
                if ($json === '' || $json === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($json, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $fallback = $decoded;

                if (isset($decoded['output']) && is_array($decoded['output'])) {
                    $output = $decoded['output'];
                }

                if (isset($decoded['delta']['output']) && is_array($decoded['delta']['output'])) {
                    foreach ($decoded['delta']['output'] as $chunk) {
                        $output[] = $chunk;
                    }
                }
            }
        }

        if (!empty($output)) {
            return ['output' => $output];
        }

        if ($fallback) {
            return $fallback;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    public function buildPayload(array $session, string $userMessage, bool $finalOverride = false): array
    {
        $input = [];

        if (empty($session['summary']) && empty($session['recentTurns'])) {
            $input[] = self::formatMessage('system', Prompt::systemPrompt());
        }

        if (!empty($session['summary'])) {
            $input[] = self::formatMessage('system', 'Résumé mémoire : ' . $session['summary']);
        }

        foreach ($session['recentTurns'] as $turn) {
            $input[] = self::formatMessage($turn['role'], (string) $turn['content']);
        }

        $input[] = self::formatMessage('user', $userMessage);

        $payload = [
            'model' => 'gpt-5-mini',
            'reasoning' => ['effort' => 'high'],
            'stream' => true,
            'parallel_tool_calls' => true,
            'tools' => [
                ['type' => 'file_search'],
                ['type' => 'web_search']
            ],
            'input' => $input,
            'metadata' => [
                'session_id' => $session['id'],
                'prompt_version' => $session['promptVersion']
            ]
        ];

        if ($finalOverride) {
            $payload['verbosity'] = 'high';
            $payload['max_output_tokens'] = 20000;
        }

        return $payload;
    }

    private static function formatMessage(string $role, string $content): array
    {
        $type = $role === 'assistant' ? 'output_text' : 'input_text';

        return [
            'role' => $role,
            'content' => [
                [
                    'type' => $type,
                    'text' => $content
                ]
            ]
        ];
    }
}
