<?php

namespace App\Services\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class BaseApi
{
    /**
     * Each child defines its base URL.
     */
    abstract protected function baseUrl(): string;

    /**
     * Children can override to add/modify headers.
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            // Leave Content-Type flexible; Laravel sets JSON automatically for arrays.
        ];
    }

    /**
     * Children can override if they want to supply a token (e.g. from config/env).
     */
    protected function token(): ?string
    {
        return null; // e.g. return config('services.someapi.token');
    }

    /**
     * Build the PendingRequest with base URL, headers, token, timeouts, retries, etc.
     */
    protected function client(array $extraHeaders = []): PendingRequest
    {
        $headers = array_filter($this->defaultHeaders() + $extraHeaders);

        $client = Http::baseUrl($this->baseUrl())
            ->withHeaders($headers)
            ->timeout(30)
            ->retry(2, 200); // light touch retries

        if ($token = $this->token()) {
            $client = $client->withToken($token);
        }

        return $client;
    }

    /**
     * One request method that supports:
     * - query params (['query' => [...]])
     * - JSON body (['json' => [...]])
     * - form body (['form' => [...]])
     * - raw body (['body' => '...'])
     * Plus per-call headers via ['headers' => [...]]
     */
    protected function request(string $method, string $endpoint, array $options = []): Response
    {
        $headers = $options['headers'] ?? [];
        $query   = $options['query']   ?? null;
        $json    = $options['json']    ?? null;
        $form    = $options['form']    ?? null;
        $body    = $options['body']    ?? null;

        $client = $this->client($headers);

        if ($form !== null) {
            $client = $client->asForm();
        }

        // Build the request
        $response = match (strtoupper($method)) {
            'GET', 'DELETE' => $client->{$method}($endpoint, $query ?? []),
            default         => $this->sendWithBody($client, $method, $endpoint, $json, $form, $body, $query),
        };

        return $this->normaliseResponse($response);
    }

    /**
     * Convenience wrappers.
     */
    protected function get(string $endpoint, array $query = [], array $options = []): Response
    {
        $options['query'] = $query;
        return $this->request('GET', $endpoint, $options);
    }

    protected function post(string $endpoint, array $payload = [], array $options = []): Response
    {
        // Default to JSON for arrays
        $options['json'] = $options['json'] ?? $payload;
        return $this->request('POST', $endpoint, $options);
    }

    protected function put(string $endpoint, array $payload = [], array $options = []): Response
    {
        $options['json'] = $options['json'] ?? $payload;
        return $this->request('PUT', $endpoint, $options);
    }

    protected function patch(string $endpoint, array $payload = [], array $options = []): Response
    {
        $options['json'] = $options['json'] ?? $payload;
        return $this->request('PATCH', $endpoint, $options);
    }

    protected function delete(string $endpoint, array $query = [], array $options = []): Response
    {
        $options['query'] = $query;
        return $this->request('DELETE', $endpoint, $options);
    }

    /**
     * Central place to shape/validate responses.
     * Keep it simple: throw for server errors; leave 4xx to the caller if needed.
     */
    protected function normaliseResponse(Response $response): Response
    {
        // You can tweak this policy however you like:
        // - ->throw() throws on 4xx/5xx
        // - ->throwIf(...) for custom logic
        return $response->throw();
    }

    /**
     * Helper to send methods with bodies while supporting json/form/raw + query.
     */
    private function sendWithBody(
        PendingRequest $client,
        string $method,
        string $endpoint,
        ?array $json,
        ?array $form,
        $body,
        ?array $query
    ): Response {
        // Apply query string if present
        if ($query) {
            $endpoint = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query);
        }

        if ($body !== null) {
            return $client->send($method, $endpoint, ['body' => $body]);
        }

        if ($form !== null) {
            return $client->send($method, $endpoint, ['form_params' => $form]);
        }

        if ($json !== null) {
            return $client->send($method, $endpoint, ['json' => $json]);
        }

        // No body at all
        return $client->send($method, $endpoint);
    }
}


// <?php

// namespace App\Services\Api;

// use Illuminate\Http\Client\PendingRequest;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Http\Client\Response;

// class BaseApi
// {
//     protected PendingRequest $http;

//     // NEW: optional default query params (e.g. ['api_key' => 'xyz'])
//     protected array $defaultQuery = [];

//     // NEW: optional callable to fetch a fresh bearer token per request
//     /** @var null|callable():(?string) */
//     protected $tokenResolver = null;

//     // keep base URL for helpers that need to build absolute URLs if you ever need it
//     protected string $baseUrl;

//     /**
//      * @param string      $baseUrl    The API's base URL (e.g., https://api.example.com)
//      * @param array       $headers    Optional custom headers
//      * @param string|null $token      Optional Bearer token (static). For dynamic tokens use withTokenResolver().
//      * @param int         $timeout    Timeout in seconds
//      * @param int         $retries    Retry attempts on failure
//      * @param int         $retryDelay Delay between retries in ms
//      */
//     public function __construct(
//         string $baseUrl,
//         array $headers = [],
//         ?string $token = null,
//         int $timeout = 10,
//         int $retries = 2,
//         int $retryDelay = 200
//     ) {
//         $this->baseUrl = rtrim($baseUrl, '/');

//         $this->http = Http::baseUrl($this->baseUrl)
//             ->timeout($timeout)
//             ->retry($retries, $retryDelay, throw: false)
//             ->acceptJson();

//         if ($token) {
//             $this->http = $this->http->withToken($token);
//         }

//         if (!empty($headers)) {
//             $this->http = $this->http->withHeaders($headers);
//         }
//     }

//     /** --------- small, backwards-compatible add-ons --------- */

//     /** Provide default query params for all requests (e.g. API keys in query). */
//     public function withDefaultQuery(array $params): static
//     {
//         $this->defaultQuery = $params;
//         return $this;
//     }

//     /** Provide a callable that returns a fresh bearer token just-in-time. */
//     public function withTokenResolver(callable $resolver): static
//     {
//         $this->tokenResolver = $resolver;
//         return $this;
//     }

//     /** Apply dynamic bearer token (if any) before each request. */
//     protected function applyAuth(): void
//     {
//         if ($this->tokenResolver) {
//             $token = ($this->tokenResolver)();
//             if ($token) {
//                 $this->http = $this->http->withToken($token);
//             }
//         }
//     }

//     /** Merge default query into a URL string. */
//     protected function appendQuery(string $endpoint, array $query = []): string
//     {
//         $merged = array_merge($this->defaultQuery, $query);
//         if (empty($merged)) {
//             return $endpoint;
//         }
//         $joiner = str_contains($endpoint, '?') ? '&' : '?';
//         return $endpoint . $joiner . http_build_query($merged);
//     }

//     /** -------------------------------------------------------- */

//     /**
//      * Handle GET requests
//      */
//     public function get(string $endpoint, array $params = []): Response
//     {
//         $this->applyAuth();
//         // Laravel's Http client will add $params as query; include defaults too:
//         $params = array_merge($this->defaultQuery, $params);

//         $response = $this->http->get($endpoint, $params);
//         $response->throw();
//         return $response;
//     }

//     /**
//      * Handle POST requests
//      */
//     public function post(string $endpoint, array $data = []): Response
//     {
//         $this->applyAuth();

//         // keep signature the same; if you need query params, put them in the endpoint itself:
//         // e.g. $this->post('/drive/v3/files?fields=id', [...])
//         $endpoint = $this->appendQuery($endpoint); // include any default query
//         $response = $this->http->post($endpoint, $data);

//         $response->throw();
//         return $response;
//     }

//     /**
//      * Handle PUT requests
//      */
//     public function put(string $endpoint, array $data = []): Response
//     {
//         $this->applyAuth();
//         $endpoint = $this->appendQuery($endpoint);
//         $response = $this->http->put($endpoint, $data);

//         $response->throw();
//         return $response;
//     }

//     /**
//      * Handle DELETE requests
//      */
//     public function delete(string $endpoint, array $params = []): Response
//     {
//         $this->applyAuth();
//         $params = array_merge($this->defaultQuery, $params);

//         $response = $this->http->delete($endpoint, $params);
//         $response->throw();
//         return $response;
//     }

//     /**
//      * Google Drive style multipart/related upload (metadata + binary in one request).
//      * Example endpoint: '/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink'
//      */
//     public function postMultipartRelated(
//         string $endpoint,
//         array $metadata,
//         string $binary,
//         string $mime,
//         array $extraQuery = []
//     ): Response {
//         $this->applyAuth();

//         // include default query + any extra uploadType/fields you pass in
//         $endpoint = $this->appendQuery($endpoint, $extraQuery);

//         $boundary = '===='.bin2hex(random_bytes(12)).'====';

//         $body =
//             "--{$boundary}\r\n" .
//             "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
//             json_encode($metadata) . "\r\n" .
//             "--{$boundary}\r\n" .
//             "Content-Type: {$mime}\r\n\r\n" .
//             $binary . "\r\n" .
//             "--{$boundary}--";

//         $response = $this->http
//             ->withHeaders(['Content-Type' => "multipart/related; boundary={$boundary}"])
//             ->withBody($body, "multipart/related; boundary={$boundary}")
//             ->post($endpoint);

//         $response->throw();
//         return $response;
//     }
// }
