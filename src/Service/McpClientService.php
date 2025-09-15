<?php
declare(strict_types=1);

namespace Survos\McpBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class McpClientService
{
    public function __construct(
        private readonly HttpClientInterface $http,
        /** @var array<string,array{host:string,endpoint?:string,api_key?:?string,proxy?:?string,headers?:array<string,string>}> */
        private readonly array $clients,
        private readonly ?string $defaultClient = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>
     */
    public function call(string $clientName, string $method, array $params = []): array
    {
        $cfg = $this->getClientConfig($clientName);
        $url = $this->getEndpointUrl($clientName);

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
            // force {} for empty params to match JSON-RPC object
            'params'  => (object) $params,
        ];

        $options = [
            'headers' => $headers,
            'json'    => $payload,
        ];
        if (!empty($cfg['proxy'])) {
            $options['proxy'] = $cfg['proxy']; // e.g. http://127.0.0.1:7080 (Symfony CLI)
        }

        $this->debug('mcp.request', [
            'client'  => $clientName,
            'url'     => $url,
            'method'  => $method,
            'headers' => $this->sanitizeHeaders($headers),
            'payload' => $payload,
        ]);
        if (str_contains($url, '.wip')) {
            $options['proxy'] = 'http://127.0.0.1:7080';
        }

        $t0 = microtime(true);
        $response = $this->http->request('POST', $url, $options);
        $raw = $response->getContent(false);
        $info = $response->getInfo();
        $lat = round((microtime(true) - $t0) * 1000, 1);

        $this->debug('mcp.response', [
            'client'     => $clientName,
            'status'     => $response->getStatusCode(),
            'latency_ms' => $lat,
            'info'       => $info,
            'raw'        => $this->truncate($raw),
        ]);

        /** @var array<string,mixed>|null $data */
        $data = null;
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Invalid JSON from MCP server "%s": %s %s',
                $data,
                $clientName, $e->getMessage()));
        }

        if (isset($data['error'])) {
            $msg  = $data['error']['message'] ?? 'JSON-RPC error';
            $code = $data['error']['code'] ?? 0;
            throw new \RuntimeException(sprintf('MCP error [%s] (%s): %s', $method, (string) $code, $msg));
        }

        /** @var array<string,mixed> */
        return $data['result'] ?? [];
    }

    /** Minimal handshake */
    public function initialize(string $clientName): array
    {
        return $this->call($clientName, 'initialize', [
            'capabilities' => new \stdClass(),
            'clientInfo'   => ['name' => 'Survos MCP CLI', 'version' => '1.0'],
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

    /**
     * tools/call with "name" and "arguments", mirroring your fguillot example.
     *
     * @param array<string,mixed>|object $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $clientName, string $toolName, array|object $arguments = []): array
    {
        $args = \is_object($arguments) ? $this->objectToArray($arguments) : $arguments;
        return $this->call($clientName, 'tools/call', [
            'name'      => $toolName,
            'arguments' => $args,
        ]);
    }

    /** Public so the command can print it at -vv */
    public function getEndpointUrl(string $clientName): string
    {
        $cfg = $this->getClientConfig($clientName);
        return rtrim($cfg['host'], '/') . '/' . ltrim($cfg['endpoint'] ?? '/mcp', '/');
    }

    /** @return array{host:string,endpoint?:string,api_key?:?string,proxy?:?string,headers?:array<string,string>} */
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

    private function objectToArray(object $o): array
    {
        if ($o instanceof \JsonSerializable) {
            $v = $o->jsonSerialize();
            return \is_array($v) ? $v : (array) $v;
        }
        // naive public-props extraction; replace with Symfony Serializer if needed
        return get_object_vars($o);
    }

    /** @param array<string,string> $headers */
    private function sanitizeHeaders(array $headers): array
    {
        if (isset($headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer ***';
        }
        return $headers;
    }

    private function truncate(string $s, int $max = 3000): string
    {
        return strlen($s) > $max ? substr($s, 0, $max) . '…(truncated)' : $s;
    }

    /** @param array<string,mixed> $ctx */
    private function debug(string $msg, array $ctx): void
    {
        $this->logger?->debug($msg, $ctx);
    }
}
