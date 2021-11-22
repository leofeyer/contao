<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Command;

use Contao\CoreBundle\Config\ResourceFinder;
use Contao\CoreBundle\Csrf\MemoryTokenStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Webmozart\PathUtil\Path;

class LintServiceIdsCommand extends Command
{
    protected static $defaultName = 'contao:lint-service-ids';

    /**
     * @var array<string,string> strip from name if the alias is part of the namespace
     */
    private static array $aliasNames = [
        'subscriber' => 'listener',
    ];

    private static array $renameNamespaces = [
        'contao_core' => 'contao',
        'event_listener' => 'listener',
        'http_kernel' => '',
    ];

    /**
     * @var array<string> strip these prefixes from the last chunk of the service ID
     */
    private static array $stripPrefixes = [
        'contao_table_',
        'core_',
    ];

    /**
     * @var array<class-string> classes that are not meant to be a single
     *                          service and can therefore not derive the
     *                          service ID from the class name
     */
    private static array $generalServiceClasses = [
        MemoryTokenStorage::class,
        ResourceFinder::class,
    ];

    private static array $exceptions = [
        // The "version_400_" prefix should not be stripped from the name as it
        // means something different as in the namespace
        'contao.migration.version_400.version_400_update',
    ];

    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Checks the Contao service IDs.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = Finder::create()
            ->files()
            ->name('*.yml')
            ->path('-bundle/src/Resources/config')
            ->in(Path::join($this->projectDir, 'vendor/contao/contao'))
        ;

        $tc = 0;
        $io = new SymfonyStyle($input, $output);

        $allClasses = [];
        $ignoreClasses = self::$generalServiceClasses;
        $classesByServiceId = [];

        foreach ($files as $file) {
            $yaml = Yaml::parseFile($file->getPathname(), Yaml::PARSE_CUSTOM_TAGS);

            foreach ($yaml['services'] ?? [] as $serviceId => $config) {
                if (!isset($config['class'])) {
                    continue;
                }

                $classesByServiceId[$serviceId] ??= $config['class'];

                // If the same service id gets used for two different classes
                // Example: contao.routing.candidates
                if ($classesByServiceId[$serviceId] !== $config['class']) {
                    $ignoreClasses[] = $config['class'];
                    $ignoreClasses[] = $classesByServiceId[$serviceId];
                }

                // If the same class gets used for two different services
                // Example: ArrayAttributeBag
                if (\in_array($config['class'], $allClasses, true)) {
                    $ignoreClasses[] = $config['class'];
                }

                $allClasses[] = $config['class'];
            }
        }

        foreach ($files as $file) {
            $fc = 0;
            $yaml = Yaml::parseFile($file->getPathname(), Yaml::PARSE_CUSTOM_TAGS);

            if (!isset($yaml['services'])) {
                continue;
            }

            foreach ($yaml['services'] as $serviceId => $config) {
                if ('_' === $serviceId[0] || !isset($config['class'])) {
                    continue;
                }

                if (\in_array($config['class'], $ignoreClasses, true)) {
                    continue;
                }

                if (\in_array($serviceId, self::$exceptions, true)) {
                    continue;
                }

                if (($id = $this->getServiceIdFromClass($config['class'])) && $id !== $serviceId) {
                    ++$fc;
                    ++$tc;
                    $io->warning(sprintf('The %s service should have the ID "%s" but has the ID "%s".', $config['class'], $id, $serviceId));
                }
            }

            if ($fc > 0) {
                $io->error(sprintf('%d wrong service IDs in the %s file.', $fc, $file->getRelativePathname()));
            } else {
                $io->success(sprintf('All service IDs are correct in the %s file.', $file->getRelativePathname()));
            }
        }

        if ($tc > 0) {
            $io->error(sprintf('%d wrong service IDs in all files.', $tc));
        }

        return 0;
    }

    private function getServiceIdFromClass(string $class): ?string
    {
        $chunks = explode('\\', strtolower(Container::underscore($class)));

        foreach ($chunks as &$chunk) {
            $chunk = preg_replace('(^([a-z]+)(\d+)(.*)$)', '$1_$2$3', $chunk);
        }

        unset($chunk);

        // The first chunk is the vendor name (e.g. Contao).
        if ('contao' !== array_shift($chunks)) {
            return null;
        }

        // The second chunk is the bundle name (e.g. CoreBundle).
        if ('_bundle' !== substr($chunks[0], -7)) {
            return null;
        }

        // Rename "xxx_bundle" to "contao_xxx"
        $chunks[0] = 'contao_'.substr($chunks[0], 0, -7);

        // The last chunk is the class name
        $name = array_pop($chunks);

        // The remaining chunks make up the sub-namespaces between the bundle
        // and the class name. We rename the ones from self::$renameNamespaces.
        foreach ($chunks as $i => &$chunk) {
            $chunk = self::$renameNamespaces[$chunk] ?? $chunk;

            if (!$chunk || ($i > 1 && strpos($name, $chunk) !== false)) {
                unset($chunks[$i]);
            }
        }

        unset($chunk);

        // Strip prefixes from the name
        foreach (self::$stripPrefixes as $prefix) {
            if (0 === strncmp($name, $prefix, \strlen($prefix))) {
                $name = substr($name, \strlen($prefix));
            }
        }

        // Now we split up the class name to unset certain chunks of the path,
        // e.g. we remove "Listener" from "BackendMenuListener".
        $nameChunks = explode('_', $name);

        foreach ($nameChunks as $i => $nameChunk) {
            if (
                'contao' === $nameChunk
                || \in_array($nameChunk, $chunks, true)
                || \in_array(self::$aliasNames[$nameChunk] ?? '', $chunks, true)
            ) {
                unset($nameChunks[$i]);
            }

            if (\in_array($nameChunk.'_'.($nameChunks[$i + 1] ?? ''), $chunks, true)) {
                unset($nameChunks[$i], $nameChunks[$i + 1]);
            }
        }

        $name = implode('_', $nameChunks);
        $path = implode('.', $chunks);

        return implode('.', array_filter([$path, $name]));
    }
}
