<?php
declare(strict_types=1);

namespace Survos\McpBundle\Command;

use Survos\McpBundle\Service\McpClientService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('debug:mcp', 'Inspect MCP server: tools/resources/prompts for a configured client')]
final class DebugMcpCommand
{
    const JSON_FLAGS = JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;
    public function __construct(private readonly McpClientService $mcp) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Client name (configured key, e.g. "sais_local")')]
        string $client,

        // boolean flags (VALUE_NONE)
        #[Option('List tools')]
        bool $tools = true,

        #[Option('List resources')]
        bool $resources = false,

        // negatable with default true: --prompts / --no-prompts
        #[Option('Include prompts')]
        bool $prompts = true,
    ): int {
        // verbosity-aware logging
        $verbose = $io->isVerbose();

        try {
            if ($verbose) {
                $io->comment('Initializing MCP session…');
            }
            $info = $this->mcp->initialize($client);

            if ($verbose) {
                $io->comment('Initialization result: '.json_encode($info, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            }

            if ($tools) {
                $io->section('Tools');
                $list = $this->mcp->listTools($client);
                if (!$list) {
                    $io->warning('No tools returned.');
                } else {
                    foreach ($list as $t) {
                        $name = $t['name'] ?? '(unnamed)';
                        $desc = (string)($t['description'] ?? '');
                        $io->writeln(sprintf('- %s%s', $name, $desc ? ': '.$desc : ''));
                        if ($verbose && isset($t['inputSchema'])) {
                            $io->writeln('    inputSchema: '.json_encode($t['inputSchema'], self::JSON_FLAGS));
                        }
                    }
                }
            }

            if ($resources) {
                $io->section('Resources');
                $list = $this->mcp->listResources($client);
                if (!$list) {
                    $io->warning('No resources returned.');
                } else {
                    foreach ($list as $r) {
                        $uri  = (string)($r['uri'] ?? '(no-uri)');
                        $name = (string)($r['name'] ?? '');
                        $line = '- '.$uri . ($name ? ' ('.$name.')' : '');
                        $io->writeln($line);
                        if ($verbose) {
                            $io->writeln('    meta: '.json_encode($r, self::JSON_FLAGS));
                        }
                    }
                }
            }

            if ($prompts) {
                $io->section('Prompts');
                $list = $this->mcp->listPrompts($client);
                if (!$list) {
                    $io->warning('No prompts returned.');
                } else {
                    foreach ($list as $p) {
                        $name = (string)($p['name'] ?? '(unnamed)');
                        $desc = (string)($p['description'] ?? '');
                        $io->writeln(sprintf('- %s%s', $name, $desc ? ': '.$desc : ''));
                        if ($verbose) {
                            $io->writeln('    meta: '.json_encode($p, self::JSON_FLAGS));
                        }
                    }
                }
            }

            if (!$tools && !$resources && !$prompts) {
                $io->note('Nothing selected. Use one or more: --tools, --resources, --prompts/--no-prompts');
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
