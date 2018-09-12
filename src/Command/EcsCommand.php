<?php declare(strict_types=1);

namespace TomasVotruba\ShopsysAnalysis\Command;

use Nette\Utils\Strings;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use TomasVotruba\ShopsysAnalysis\Contract\ProjectInterface;
use TomasVotruba\ShopsysAnalysis\Process\ProcessFactory;
use TomasVotruba\ShopsysAnalysis\ProjectProvider;

final class EcsCommand extends Command
{
    /**
     * @var string
     */
    private const ERROR_COUNT_PATTERN = '#Found (?<count>[0-9]+) errors#';

    /**
     * @var string
     */
    private const FIXABLE_ERROR_COUNT_PATTERN = '#(?<count>[0-9]+) files are fixable#';

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var ProjectProvider
     */
    private $projectProvider;

    /**
     * @var ProcessFactory
     */
    private $processFactory;

    public function __construct(
        SymfonyStyle $symfonyStyle,
        ProjectProvider $projectProvider,
        ProcessFactory $processFactory
    ) {
        parent::__construct();
        $this->symfonyStyle = $symfonyStyle;
        $this->projectProvider = $projectProvider;
        $this->processFactory = $processFactory;
    }

    protected function configure(): void
    {
        $this->setName('ecs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->projectProvider->provide() as $project) {
            $this->symfonyStyle->title('ECS Analysis of ' . $project->getName());

            foreach ($project->getEasyCodingStandardConfigs() as $config) {
                $this->processEcsConfig($project, $config);
            }
        }

        return 0;
    }

    protected function processEcsConfig(ProjectInterface $project, string $config): void
    {
        $name = $this->getConfigBaseName($config);
        $tempFile = $this->createTempFileName($project->getName(), $name);

        if (! file_exists($tempFile) || ! file_get_contents($tempFile) || file_get_contents($tempFile) === '') {
            $process = $this->processFactory->createECSProcess($project, $config, $tempFile);
            $this->symfonyStyle->note('Running: ' . $process->getCommandLine());

            $process->run();

        } else {
            $this->symfonyStyle->note(sprintf('Using cached result file "%s". Remove it to re-run.', $tempFile));
        }

        $this->symfonyStyle->writeln(sprintf(
            'Config %s: %d errors',
            $name,
            $this->getErrorCountFromTempFile($tempFile)
        ));
    }

    private function createTempFileName(string $name, string $configName): string
    {
        return sprintf('%s/_analyze_ecs-%s-config-%s', sys_get_temp_dir(), strtolower($name), $configName);
    }

    private function getErrorCountFromTempFile(string $tempFile): int
    {
        $tempFileContent = file_get_contents($tempFile);

        $errorMatch = Strings::match($tempFileContent, self::ERROR_COUNT_PATTERN);
        $fixlableErrorMatch = Strings::match($tempFileContent, self::FIXABLE_ERROR_COUNT_PATTERN);

        return (int) ($errorMatch['count'] ?? 0) + (int) ($fixlableErrorMatch['count'] ?? 0);
    }

    private function getConfigBaseName(string $config): string
    {
        return (new SplFileInfo($config))->getBasename('.yml');
    }
}
