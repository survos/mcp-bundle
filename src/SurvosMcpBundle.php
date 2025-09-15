<?php
/** generated from /home/tac/g/sites/mono/bu/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\McpBundle;

use Survos\McpBundle\Command\DebugMcpCommand;
use Survos\McpBundle\Service\McpClientService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SurvosMcpBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->autowire(McpClientService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->setArgument('$clients', $config['clients'])
        ;

        foreach ([DebugMcpCommand::class] as $class) {
            $builder->autowire($class)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

    }
public function configure(DefinitionConfigurator $definition): void
{
    $definition->rootNode()
        ->children()
            ->scalarNode('default_client')
                ->defaultNull()
                ->info('Name of the default MCP client (one of the keys under "clients").')
            ->end()

            ->arrayNode('clients')
                ->info(<<<'INFO'
Define named MCP clients.

Example:

  survos_mcp:
    default_client: sais_local
    clients:
      sais_local:
        host: 'https://sais.sip'
        endpoint: '/mcp'       # default; could be '/rpc' or other
        api_key: null          # or '%env(MCP_SAIS_LOCAL_KEY)%'
      sais:
        host: 'https://sais.survos.com'
        # endpoint defaults to '/mcp'
        # api_key: '%env(MCP_SAIS_KEY)%'
INFO)
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('host')
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->info('Base URL for the server (e.g. "https://sais.sip").')
                        ->end()
                        ->scalarNode('endpoint')
                            ->defaultValue('/mcp')
                            ->info('JSON-RPC endpoint path (e.g. "/mcp", "/rpc").')
                        ->end()
                        ->scalarNode('api_key')
                            ->defaultNull()
                            ->info('Optional API key. If set, added as "Authorization: Bearer <key>".')
                        ->end()
                        ->arrayNode('headers')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('name')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Optional extra headers to send with each request.')
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();
}


}
