<?php declare(strict_types=1);

namespace TomasVotruba\ShopsysAnalysis\Command;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use TomasVotruba\ShopsysAnalysis\Contract\ProjectInterface;
use TomasVotruba\ShopsysAnalysis\Process\ProcessFactory;
use TomasVotruba\ShopsysAnalysis\ProjectProvider;
use TomasVotruba\ShopsysAnalysis\Report\PhpStanReportSummary;

final class PhpStanCommand extends Command
{
    /**
     * @var string
     */
    private const OPTION_LEVEL = 'level';

    /**
     * @var string
     */
    private const OPTION_REPORT = 'report';

    /**
     * @var string[]|int[]
     * @see https://github.com/phpstan/phpstan/tree/master/conf
     */
    private $phpStanLevels = [0, 1, 2, 3, 4, 5, 6, 7, 'max'];

    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var ProjectProvider
     */
    private $projectProvider;

    /**
     * @var PhpStanReportSummary
     */
    private $phpStanReportSummary;

    /**
     * @var ProcessFactory
     */
    private $processFactory;

    /**
     * @var Filesystem
     */
    private $filesystem;

    public function __construct(
        SymfonyStyle $symfonyStyle,
        ProjectProvider $projectProvider,
        PhpStanReportSummary $phpStanReportSummary,
        ProcessFactory $processFactory,
        Filesystem $filesystem
    ) {
        parent::__construct();
        $this->symfonyStyle = $symfonyStyle;
        $this->projectProvider = $projectProvider;
        $this->phpStanReportSummary = $phpStanReportSummary;
        $this->processFactory = $processFactory;
        $this->filesystem = $filesystem;
    }

    protected function configure(): void
    {
        $this->setName('phpstan');
        $this->addOption(self::OPTION_LEVEL, null, InputOption::VALUE_REQUIRED, 'Run at specific of level');
        $this->addOption(self::OPTION_REPORT, null, InputOption::VALUE_NONE, 'Show top 20 most common errors.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // use custom files
        if ($input->getOption(self::OPTION_LEVEL) !== null) {
            $this->phpStanLevels = [$input->getOption(self::OPTION_LEVEL)];
        }

        foreach ($this->projectProvider->provide() as $project) {
            $this->symfonyStyle->title($project->getName());

            foreach ($this->phpStanLevels as $phpStanLevel) {
                $this->processLevel($project, (string) $phpStanLevel, (bool) $input->getOption(self::OPTION_REPORT));
            }
        }

        return 0;
    }

    private function getErrorCountFromTempFile(string $tempFile): int
    {
        try {
            $resultJson = Json::decode(file_get_contents($tempFile), Json::FORCE_ARRAY);
        } catch (JsonException $jsonException) {
            // remove corrupted file and continue
            $this->filesystem->remove($tempFile);

            throw $jsonException;
        }

        return (int) $resultJson['totals']['file_errors'];
    }

    private function processLevel(ProjectInterface $project, string $level, bool $shouldReport): void
    {
        $tempFile = $this->createTempFileName($project->getName(), $level);

        // the file doesn't exist or is empty → run analysis
        // @note invalidate cache form time to time
        if (! file_exists($tempFile) || ! file_get_contents($tempFile) || file_get_contents($tempFile) === '') {
            $process = $this->processFactory->createPHPStanProcess($project, $level, $tempFile);
            $this->symfonyStyle->note('Running: ' . $process->getCommandLine());
            $process->run();
        } else {
            $this->symfonyStyle->note(sprintf('Using cached result file "%s". Remove it to re-run.', $tempFile));
        }

        $this->symfonyStyle->writeln(
            sprintf('PHPStan Level %s: %d errors', $level, $this->getErrorCountFromTempFile($tempFile))
        );

        if ($shouldReport) {
            $errorsList = $this->phpStanReportSummary->processPHPStanJsonFileToErrorList($tempFile);

            $errorTable = [];
            foreach ($errorsList as $errorMessage => $errorCount) {
                $errorTable[] = [$errorCount, $errorMessage];
            }

            $this->symfonyStyle->newLine();
            $this->symfonyStyle->table(['Count', 'Message'], $errorTable);
        }
    }

    private function createTempFileName(string $name, string $level): string
    {
        return sprintf('%s/_analyze_phpstan-%s-level-%s', sys_get_temp_dir(), strtolower($name), $level);
    }
}
