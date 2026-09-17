<?php
/**
 * config/openai.php
 *
 * Thin Guzzle-based Chat Completions client (Guzzle 7.14.2 is already
 * installed transitively via google/apiclient — no new Composer
 * dependency, same reasoning as config/paymongo.php).
 *
 * Routed through OpenRouter (https://openrouter.ai), not OpenAI directly
 * -- the key provisioned in .env (OPENAI_API_KEY) is an OpenRouter key
 * (format sk-or-v1-...), which OpenAI's own API correctly rejects with a
 * 401. OpenRouter's Chat Completions endpoint is wire-compatible with
 * OpenAI's (same request/response JSON shape), so no other code in this
 * file or any caller needed to change -- just base_uri and using an
 * OpenRouter-namespaced model slug. If a real OpenAI key is ever swapped
 * in instead, point base_uri back at https://api.openai.com/v1/ and drop
 * the 'openai/' model prefix.
 *
 * Credentials live in .env (OPENAI_API_KEY) — unlike PayMongo's
 * DB-table-backed credentials, this doesn't need a runtime owner-facing
 * test/live toggle, so it follows this project's other .env-based
 * third-party credentials (mail, Google OAuth) instead.
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Single source of truth for what gets stored in feedback.ai_provider /
 * feedback.ai_model, and for the model getOpenAiClient() actually requests
 * below -- one place to update if the backing service or model ever
 * changes, instead of the same literal repeated in every caller.
 */
const AI_PROVIDER_NAME = 'openrouter';
const AI_MODEL_NAME = 'openai/gpt-4o-mini';

class OpenAiClient
{
    public function __construct(private \GuzzleHttp\Client $http, private string $model)
    {
    }

    /**
     * One Chat Completions call. $messages follows OpenAI's role/content
     * message array; $tools is the function-calling tool schema (empty =
     * plain chat, no tools offered). $responseFormat, when given (e.g.
     * ['type' => 'json_object']), turns on OpenAI's actual enforced JSON
     * mode -- stricter than just asking for JSON in the prompt, used by
     * config/feedback_moderation.php's structured classification calls
     * (the chatbot's own tool-calling call site omits this, unaffected).
     * Returns the raw assistant message array as-is (may contain
     * 'tool_calls') so the caller's own loop can decide whether another
     * round is needed.
     */
    public function chat(array $messages, array $tools = [], ?array $responseFormat = null): array
    {
        $payload = [
            'model'    => $this->model,
            'messages' => $messages,
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        if ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
        }

        $response = $this->http->post('chat/completions', ['json' => $payload]);
        $body = json_decode((string)$response->getBody(), true);

        return $body['choices'][0]['message']
            ?? ['role' => 'assistant', 'content' => "Sorry, I couldn't process that just now."];
    }
}

/**
 * Returns null if OPENAI_API_KEY isn't set in .env yet — every caller MUST
 * handle that gracefully (a friendly "assistant isn't set up yet" message)
 * rather than assume it's always available.
 */
function getOpenAiClient(): ?OpenAiClient
{
    $apiKey = getenv('OPENAI_API_KEY') ?: '';
    if ($apiKey === '') {
        return null;
    }

    $http = new \GuzzleHttp\Client([
        'base_uri' => 'https://openrouter.ai/api/v1/',
        'headers'  => [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'HTTP-Referer'  => 'https://opo.local/',
            'X-Title'       => 'OPO! Our Pinoy Original',
        ],
        'timeout'  => 30,
        // XAMPP's bundled curl on Windows has no default CA store, so
        // Guzzle's default TLS verification fails EVERY request with
        // "cURL error 60: unable to get local issuer certificate" --
        // confirmed directly here: every Analytics AI insight was failing
        // and the box silently rendered "AI insight unavailable" while
        // still costing ~2s per page load on a call that could never
        // succeed. Same app-level fix already proven on config/paymongo.php:
        // point at XAMPP's own bundled CA bundle explicitly rather than
        // relying on php.ini's curl.cainfo (which Apache's and CLI's
        // php.ini may set differently, or not at all).
        'verify'   => httpCaBundle(),
    ]);

    return new OpenAiClient($http, AI_MODEL_NAME);
}
