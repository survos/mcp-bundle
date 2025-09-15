<?php
declare(strict_types=1);

namespace Survos\McpBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class McpClientService
{
    public function __construct(
        private readonly HttpClientInterface $http,
        /** @var array<string,array{host:string,endpoint?:string,api_key?:?string,headers?:array<string,string>}> */
        private readonly array $clients=[],
        private readonly ?string $defaultClient = null,
    ) {}

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>
     */
    public function call(string $clientName, string $method, array $params = []): array
    {
        $cfg = $this->getClientConfig($clientName);
        $url = rtrim($cfg['host'], '/') . '/' . ltrim($cfg['endpoint'] ?? '/mcp', '/');

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $cfg['headers'] ?? []);

        if (!empty($cfg['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $cfg['api_key'];
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => $method,
            'params'  => (object) $params, // ensure {} for empty object
        ];

        $response = $this->http->request('POST', $url, [
            'headers' => $headers,
            'json'    => $payload,
        ]);

        $data = $response->toArray(false);

        if (isset($data['error'])) {
            $msg  = $data['error']['message'] ?? 'JSON-RPC error';
            $code = $data['error']['code'] ?? 0;
            $dataStr = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException(sprintf('MCP error [%s] (%s): %s', $method, (string)$code, $msg) . ' :: ' . $dataStr);
        }

        /** @var array<string,mixed> */
        return $data['result'] ?? [];
    }

    /** Minimal handshake (extend capabilities if your server expects more) */
    public function initialize(string $clientName): array
    {
        return $this->call($clientName, 'session/initialize', [
            'capabilities' => new \stdClass(), // or a richer object if needed
            'clientInfo'   => [
                'name'    => 'Survos MCP CLI',
                'version' => '1.0',
            ],
        ]);
    }

    /** @return array<int,array{name:string,description?:string}> */
    public function listTools(string $clientName): array
    {
        $res = $this->call($clientName, 'tools/list');
        return $res['tools'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listResources(string $clientName): array
    {
        $res = $this->call($clientName, 'resources/list');
        return $res['resources'] ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public function listPrompts(string $clientName): array
    {
        $res = $this->call($clientName, 'prompts/list');
        return $res['prompts'] ?? [];
    }

    /** @return array{host:string,endpoint?:string,api_key?:?string,headers?:array<string,string>} */
    private function getClientConfig(string $clientName): array
    {
        if ($clientName === '' && $this->defaultClient) {
            $clientName = $this->defaultClient;
        }
        if (!isset($this->clients[$clientName])) {
            $known = $this->clients ? implode(', ', array_keys($this->clients)) : '(none configured)';
            throw new \InvalidArgumentException(sprintf('Unknown MCP client "%s". Known: %s', $clientName, $known));
        }
        return $this->clients[$clientName];
    }
}
