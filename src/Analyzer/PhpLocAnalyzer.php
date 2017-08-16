<?php declare(strict_types=1);

namespace TomasVotruba\ShopsysAnalysis\Analyzer;

use SebastianBergmann\PHPLOC\Analyser;
use Symfony\Component\Console\Style\SymfonyStyle;
use TomasVotruba\ShopsysAnalysis\Finder\PhpFilesFinder;

final class PhpLocAnalyzer
{
    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @var PhpFilesFinder
     */
    private $phpFilesFinder;

    public function __construct(SymfonyStyle $symfonyStyle, PhpFilesFinder $phpFilesFinder)
    {
        $this->symfonyStyle = $symfonyStyle;
        $this->phpFilesFinder = $phpFilesFinder;
    }

    public function process(string $name, string $source): void
    {
        $count = $this->analyzeLocInDirectory($source);

        $this->symfonyStyle->title($name);

        $this->symfonyStyle->writeln(sprintf(
            'Lines of code (LOC): %d',
            $count['loc']
        ));

        $this->symfonyStyle->writeln(sprintf(
            'Cyclomatic Complexity per Class: %0.2f',
            $count['classCcnAvg']
        ));

        $this->symfonyStyle->writeln(sprintf(
            'Cyclomatic Complexity per Method: %0.2f',
            $count['methodCcnAvg']
        ));

        $this->symfonyStyle->writeln(sprintf(
            'Cyclomatic Complexity per Method: %0.2f',
            $count['methodCcnAvg']
        ));

        $this->symfonyStyle->writeln(sprintf(
            'Number of Methods/Number of Classes: %0.2f',
            $count['methods'] / $count['classes'] ?: 1
        ));
    }

    /**
     * @return mixed[]
     */
    private function analyzeLocInDirectory(string $directory): array
    {
        $files = $this->phpFilesFinder->findInDirectory($directory);

        // have to be created fresh for every project, uses cache
        return (new Analyser)->countFiles($files, false);
    }
}