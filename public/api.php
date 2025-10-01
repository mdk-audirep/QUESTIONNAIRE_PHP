<?php

declare(strict_types=1);

use InvalidArgumentException;
use Questionnaire\Support\Env;
use Questionnaire\Support\MissingConfigurationException;
use Questionnaire\Support\OpenAIClient;
use Questionnaire\Support\Prompt;
use Questionnaire\Support\ResponseFormatter;
use Questionnaire\Support\SessionStore;
use RuntimeException;

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
$action = $_GET['action'] ?? ($input['action'] ?? null);

try {
    switch ($action) {
        case 'health':
            echo json_encode([
                'status' => 'ok',
                'openaiEnabled' => Env::openAiEnabled(),
                'missingKeys' => Env::openAiEnabled() ? [] : ['OPENAI_API_KEY', 'VECTOR_STORE_ID'],
                'promptVersion' => Prompt::VERSION,
            ]);
            break;

        case 'start':
            responseStart($input);
            break;

        case 'continue':
            responseContinue($input, false);
            break;

        case 'final':
            responseContinue($input, true);
            break;

        default:
            http_response_code(404);
            echo json_encode(['message' => 'Route inconnue']);
    }
} catch (MissingConfigurationException $exception) {
    http_response_code(503);
    echo json_encode(['message' => 'Configuration manquante : définissez OPENAI_API_KEY et VECTOR_STORE_ID.']);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['message' => $exception->getMessage()]);
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['message' => $exception->getMessage()]);
}

function validateCommon(array $data, bool $expectSession = false): void
{
    if (($data['promptVersion'] ?? null) !== Prompt::VERSION) {
        throw new InvalidArgumentException('Version du prompt incompatible. Lancez /start à nouveau.');
    }

    if (empty($data['userMessage']) || !is_string($data['userMessage'])) {
        throw new InvalidArgumentException('userMessage est requis.');
    }

    if ($expectSession) {
        if (empty($data['sessionId']) || !is_string($data['sessionId'])) {
            throw new InvalidArgumentException('sessionId est requis.');
        }
    }
}

function responseStart(array $data): void
{
    Env::requireKeys();
    validateCommon($data);

    $session = SessionStore::create(Prompt::VERSION);
    if (isset($data['phaseHint'])) {
        SessionStore::updatePhase($session, $data['phaseHint']);
    }
    SessionStore::mergeMemory($session, $data['memoryDelta'] ?? null);

    $client = new OpenAIClient();
    $payload = $client->buildPayload($session, (string) $data['userMessage']);
    $result = $client->send($payload);

    $assistantMarkdown = ResponseFormatter::extractContent($result);
    $phase = ResponseFormatter::detectPhase($assistantMarkdown, $session['phase']);
    SessionStore::updatePhase($session, $phase);

    SessionStore::appendTurn($session, 'user', (string) $data['userMessage']);
    SessionStore::appendTurn($session, 'assistant', $assistantMarkdown);
    SessionStore::updatePhase($session, $phase);
    SessionStore::mergeMemory($session, $data['memoryDelta'] ?? null);
    SessionStore::update($session);

    echo json_encode([
        'sessionId' => $session['id'],
        'promptVersion' => Prompt::VERSION,
        'phase' => $phase,
        'assistantMarkdown' => $assistantMarkdown,
        'memorySnapshot' => $session['memory'],
        'finalMarkdownPresent' => ResponseFormatter::hasFinalMarkdown($assistantMarkdown),
        'nextAction' => 'ask_user'
    ]);
}

function responseContinue(array $data, bool $finalOverride): void
{
    Env::requireKeys();
    validateCommon($data, true);

    $session = SessionStore::get($data['sessionId']);
    if (!$session) {
        throw new RuntimeException('Session inconnue. Relancez /start.');
    }

    if (($data['promptVersion'] ?? null) !== $session['promptVersion']) {
        throw new InvalidArgumentException('Version du prompt obsolète. Démarrez une nouvelle session.');
    }

    if (isset($data['phaseHint'])) {
        SessionStore::updatePhase($session, $data['phaseHint']);
    }

    SessionStore::mergeMemory($session, $data['memoryDelta'] ?? null);

    $client = new OpenAIClient();
    $payload = $client->buildPayload($session, (string) $data['userMessage'], $finalOverride);
    $result = $client->send($payload);

    $assistantMarkdown = ResponseFormatter::extractContent($result);
    $phase = $finalOverride ? 'final' : ResponseFormatter::detectPhase($assistantMarkdown, $session['phase']);

    SessionStore::appendTurn($session, 'user', (string) $data['userMessage']);
    SessionStore::appendTurn($session, 'assistant', $assistantMarkdown);
    SessionStore::updatePhase($session, $phase);
    SessionStore::mergeMemory($session, $data['memoryDelta'] ?? null);
    SessionStore::update($session);

    echo json_encode([
        'sessionId' => $session['id'],
        'promptVersion' => $session['promptVersion'],
        'phase' => $phase,
        'assistantMarkdown' => $assistantMarkdown,
        'memorySnapshot' => $session['memory'],
        'finalMarkdownPresent' => ResponseFormatter::hasFinalMarkdown($assistantMarkdown),
        'nextAction' => $finalOverride ? 'persist_and_render' : 'ask_user'
    ]);
}
