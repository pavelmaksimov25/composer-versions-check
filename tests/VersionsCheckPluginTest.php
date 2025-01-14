<?php

namespace SLLH\ComposerVersionsCheck\Tests;

use Composer\Command\UpdateCommand;
use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\BufferIO;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginManager;
use Composer\Repository\ArrayRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositoryManager;
use Composer\Repository\WritableArrayRepository;
use Composer\Script\ScriptEvents;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SLLH\ComposerVersionsCheck\VersionsCheckPlugin;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
class VersionsCheckPluginTest extends TestCase
{
    /**
     * @var BufferIO
     */
    private $io;

    /**
     * @var Composer|MockObject
     */
    private $composer;

    /**
     * @var Config
     */
    private $config;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->io = new BufferIO();
        $this->composer = $this->createMock(Composer::class);
        $this->config = new Config(false);

        $this->composer->expects($this->any())->method('getConfig')
            ->willReturn($this->config);
        $this->composer->expects($this->any())->method('getPackage')
            ->willReturn(new RootPackage('my/project', '1.0.0', '1.0.0'));
        $this->composer->expects($this->any())->method('getPluginManager')
            ->willReturn(new PluginManager($this->io, $this->composer));
        $this->composer->expects($this->any())->method('getEventDispatcher')
            ->willReturn(new EventDispatcher($this->composer, $this->io));
        $repositoryManager = version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0.0') >= 0
            ? new RepositoryManager($this->io, $this->config, new HttpDownloader($this->io, $this->config))
            : new RepositoryManager($this->io, $this->config)
        ;
        $this->composer->expects($this->any())->method('getRepositoryManager')
            ->willReturn($repositoryManager);
    }

    /**
     * @dataProvider getTestOptionsData
     *
     * @param array|null $configData
     */
    public function testOptions($configData, array $expectedOptions)
    {
        // Arrange
        if (null === $configData) {
            $this->composer->expects($this->any())->method('getConfig')
                ->willReturn(new Config());
        } else {
            $this->config->merge($configData);
        }

        $plugin = new VersionsCheckPlugin();

        // Act
        $plugin->activate($this->composer, $this->io);

        // Assert
        $this->assertSame($expectedOptions, $plugin->getOptions());
    }

    public function getTestOptionsData()
    {
        return array(
            'No option' => array(
                null,
                array(
                    'show-links' => false,
                ),
            ),
            'Empty array options' => array(
                array(),
                array(
                    'show-links' => false,
                ),
            ),
            'Empty array plugin options' => array(
                array(
                    'config' => array(
                        'sllh-composer-versions-check' => array(),
                    ),
                ),
                array(
                    'show-links' => false,
                ),
            ),
            'Empty plugin options' => array(
                array(
                    'config' => array(
                        'sllh-composer-versions-check' => null,
                    ),
                ),
                array(
                    'show-links' => false,
                ),
            ),
            'False plugin options' => array(
                array(
                    'config' => array(
                        'sllh-composer-versions-check' => false,
                    ),
                ),
                array(
                    'show-links' => false,
                ),
            ),
            'Activate show-links' => array(
                array(
                    'config' => array(
                        'sllh-composer-versions-check' => array(
                            'show-links' => true,
                        ),
                    ),
                ),
                array(
                    'show-links' => true,
                ),
            ),
            'Disable show-links' => array(
                array(
                    'config' => array(
                        'sllh-composer-versions-check' => array(
                            'show-links' => false,
                        ),
                    ),
                ),
                array(
                    'show-links' => false,
                ),
            ),
        );
    }

    public function testPluginRegister()
    {
        $plugin = new VersionsCheckPlugin();
        $this->addComposerPlugin($plugin);

        $this->assertSame(array($plugin), $this->composer->getPluginManager()->getPlugins());
    }

    public function testUpdateCommand()
    {
        $this->addComposerPlugin(new VersionsCheckPlugin());

        $localRepository = $this->makeWritableRepository();
        $localRepository->addPackage(new Package('foo/bar', '1.0.0', '1.0.0'));
        $this->composer->getRepositoryManager()->setLocalRepository($localRepository);

        $distRepository = new ArrayRepository();
        $distRepository->addPackage(new Package('foo/bar', '1.0.0', '1.0.0'));
        $distRepository->addPackage(new Package('foo/bar', '1.0.1', '1.0.1'));
        $distRepository->addPackage(new Package('foo/bar', '2.0.0', '2.0.0'));
        $this->composer->getRepositoryManager()->addRepository($distRepository);

        $this->composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_UPDATE_CMD);

        $this->assertSameOutput(<<<'EOF'
<warning>1 package is not up to date:</warning>

  - foo/bar (1.0.0) latest is 2.0.0


EOF
);
    }

    public function testPreferLowest()
    {
        $this->addComposerPlugin(new VersionsCheckPlugin());

        $localRepository = $this->makeWritableRepository();
        $localRepository->addPackage(new Package('foo/bar', '1.0.0', '1.0.0'));
        $this->composer->getRepositoryManager()->setLocalRepository($localRepository);

        $distRepository = new ArrayRepository();
        $distRepository->addPackage(new Package('foo/bar', '1.0.0', '1.0.0'));
        $distRepository->addPackage(new Package('foo/bar', '2.0.0', '2.0.0'));
        $this->composer->getRepositoryManager()->addRepository($distRepository);

        $updateCommand = new UpdateCommand();
        $input = new ArrayInput(array('update'), $updateCommand->getDefinition());
        $input->setOption('prefer-lowest', true);
        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'update', $input, new NullOutput());
        $this->composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);
        $this->composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_UPDATE_CMD);

        $this->assertSameOutput('', 'Plugin should not be runned.');
    }

    public function testPreferLowestNotExists()
    {
        $this->addComposerPlugin(new VersionsCheckPlugin());

        $localRepository = $this->makeWritableRepository();
        $localRepository->addPackage(new Package('foo/bar', '1.0.0', '1.0.0'));
        $this->composer->getRepositoryManager()->setLocalRepository($localRepository);

        $distRepository = new ArrayRepository();
        $distRepository->addPackage(new Package('foo/bar', '1.0.0', '1.0.0'));
        $distRepository->addPackage(new Package('foo/bar', '2.0.0', '2.0.0'));
        $this->composer->getRepositoryManager()->addRepository($distRepository);

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'update', new ArrayInput(array()), new NullOutput());
        $this->composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);
        $this->composer->getEventDispatcher()->dispatchScript(ScriptEvents::POST_UPDATE_CMD);

        $this->assertSameOutput(<<<'EOF'
<warning>1 package is not up to date:</warning>

  - foo/bar (1.0.0) latest is 2.0.0


EOF
);
    }

    private function addComposerPlugin(PluginInterface $plugin)
    {
        $pluginManagerReflection = new \ReflectionClass($this->composer->getPluginManager());
        $addPluginReflection = $pluginManagerReflection->getMethod('addPlugin');
        $addPluginReflection->setAccessible(true);
        $addPluginReflection->invoke($this->composer->getPluginManager(), $plugin);
    }

    private function assertSameOutput($expectedOutput, $message = '')
    {
        $this->assertSame($expectedOutput, $this->io->getOutput(), $message);
    }

    private function makeWritableRepository()
    {
        return version_compare(PluginInterface::PLUGIN_API_VERSION, '2.0.0') >= 0
            ? new InstalledArrayRepository()
            : new WritableArrayRepository()
        ;
    }
}
