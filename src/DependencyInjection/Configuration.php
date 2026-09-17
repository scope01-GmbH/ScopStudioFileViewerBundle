<?php

declare(strict_types=1);

/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in
 * LICENSE which is distributed with this source code.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

namespace Scop\StudioFileViewerBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const DEFAULT_MAX_EDITABLE_SIZE = 2097152;

    public const DEFAULT_TAIL_BYTES = 262144;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('scop_studio_file_viewer');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('root_path')
                    ->defaultValue('%kernel.project_dir%')
                    ->info('Absolute path of the directory exposed to the file viewer. Nothing outside of it can be read or written.')
                ->end()
                ->booleanNode('writable')
                    ->defaultTrue()
                    ->info('Set to false to run the viewer in read-only mode, e.g. on production.')
                ->end()
                ->booleanNode('follow_symlinks')
                    ->defaultTrue()
                    ->info('Whether a symlink inside root_path may be followed to a target outside of it. Deployments link shared directories (var, config, storage) into every release, so this is on by default. Set to false to confine the viewer to what physically lives under root_path.')
                ->end()
                ->integerNode('max_editable_size')
                    ->defaultValue(self::DEFAULT_MAX_EDITABLE_SIZE)
                    ->min(1024)
                    ->info('Files larger than this (bytes) are not loaded into the editor; the UI offers a read-only tail instead.')
                ->end()
                ->integerNode('tail_bytes')
                    ->defaultValue(self::DEFAULT_TAIL_BYTES)
                    ->min(1024)
                    ->info('How many bytes from the end of a large file the read-only tail view returns.')
                ->end()
                ->arrayNode('excluded_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['.git'])
                    ->info('Paths relative to root_path that are hidden and cannot be read or written.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
