<?php
namespace App\PhofmitBundle\Command;

use App\IQSocketControlBundle\Connector\IQSocket;
use App\PhofmitBundle\Helper\DateTime;
use App\PhofmitBundle\Service\Mirror;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class MirrorCommand extends \Symfony\Component\Console\Command\Command
{
    /** @var Mirror */
    protected $mirror;

    public function __construct(
        string $name = null,
        Mirror $mirror
    ) {
        parent::__construct($name);
        $this->mirror = $mirror;
    }

    protected function configure()
    {
        $this->setName('phofmit:mirror')
            ->setDescription('[Phofmit] Use specified snapshot file to mirror files location')
            ->addOption(
                'dry-run',
                'N',
                InputOption::VALUE_NONE,
                'Do not apply changes, only show what would be done.'
            )
            ->addOption(
                'shell',
                's',
                InputOption::VALUE_NONE,
                'Print shell commands instead of doing the actual moving.'
            )
            ->addOption(
                'target-snapshot',
                't',
                InputOption::VALUE_REQUIRED,
                'Use given target snapshot file instead of scanning target folder.'
            )
            ->addOption(
                'option',
                'o',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Set scanner options.'
            )
            ->addOption(
                'dir-mode',
                'd',
                InputOption::VALUE_REQUIRED,
                'Mode for directories created when mirroring.',
                0777
            )
            ->addArgument(
                'snapshot-filename',
                InputArgument::REQUIRED,
                'Snapshot file created with phofmit:snapshot'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path of target folder'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $referenceSnapshotFilename = $input->getArgument('snapshot-filename');
        if (!is_file($referenceSnapshotFilename) || !is_readable($referenceSnapshotFilename)) {
            throw new \InvalidArgumentException('Invalid snapshot path: ' . $referenceSnapshotFilename);
        }
        if (!is_dir($path = $input->getArgument('path'))) {
            throw new \InvalidArgumentException('Invalid path: ' . $path);
        }

        $options = $this->readOptions($input);

        // If shell output is requested, use STDOUT for shell commands and print all logs on STDERR
        if ($options['shell-mode']) {
            /** @var OutputInterface $logOutput */
            $logOutput = $output->getErrorOutput();
            $shellOutput = $output;
        } else {
            $logOutput = $shellOutput = $output;
        }

        $logOutput->writeln("<info>🛈 Using snapshot file at $referenceSnapshotFilename.</info>");
        $logOutput->writeln("<info>🛈 Target folder is at $path...</info>");

        $startTime = microtime(true);
        if ($options['dry-run']) {
            $logOutput->writeln('<fg=blue>✋ DRY-RUN ENABLED.</>');
        }

        $referenceSnapshot = $this->loadSnapshot($referenceSnapshotFilename);
        if ($targetSnapshotFilename = $input->getOption('target-snapshot')) {
            $logOutput->writeln(sprintf('<info>🛈 Loading target snapshot from %s</info>', $targetSnapshotFilename));
            $targetSnapshot = $this->loadSnapshot($targetSnapshotFilename);
            $logOutput->writeln(sprintf(
                '<info>🛈 %d file(s) found in target snapshot.</info>',
                count($targetSnapshot['files'])
            ));
        } else {
            if (!$input->getArgument('path')) {
                throw new \InvalidArgumentException('You must specify a path if no --target-snapshot is given.');
            }

            // Use scanner config from reference snapshot to get comparable results
            $options['scanner-config'] = $referenceSnapshot['scanner-config'];
            $targetSnapshot = $this->mirror->snapshot($path, $logOutput, $options);
        }

        $diffScannerConfig = $this->mirror->getScannerConfig($options);

        $logOutput->writeln(
            '<info>⏳ Searching for matching files with reference snapshot (this might take a while)...</info>'
        );
        $diff = $this->mirror->diffSnapshots($referenceSnapshot, $targetSnapshot, $diffScannerConfig);

        $logOutput->writeln(sprintf('🛈 %d target file(s) found with matching reference:', count($diff)));

        $this->applyMirroring(
            $diff,
            $targetSnapshot['base-path'],
            $logOutput,
            $shellOutput,
            $options['dir-mode'],
            $options['shell-mode'],
            $options['dry-run']
        );
        $logOutput->writeln('');

        $logOutput->writeln(sprintf(
            "<info>✔ Finished in %s</info>",
            DateTime::secondsToTime(microtime(true) - $startTime)
        ));

        return self::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    protected function readOptions(InputInterface $input): array {
        $options = [
            'dry-run'    => $input->getOption('dry-run'),
            'shell-mode' => $input->getOption('shell'),
            'dir-mode'   => $input->getOption('dir-mode'),
            'scanner-config' => [],
        ];
        foreach ($input->getOption('option') as $option) {
            list($key, $value) = explode('=', $option);
            $options['scanner-config'][$key] = $value;
        }

        return $options;
    }

    /**
     * @param $filename
     * @return array
     */
    protected function loadSnapshot($filename): array {
        return json_decode(file_get_contents($filename), true);
    }

    /**
     * @param array $diffResults
     * @param string $targetBasePath
     * @param OutputInterface $logOutput
     * @param OutputInterface $shellOutput
     * @param array $options
     */
    public function applyMirroring(
        array $diffResults,
        string $targetBasePath,
        OutputInterface $logOutput,
        OutputInterface $shellOutput,
        int $dirMode,
        bool $shellMode,
        bool $dryRun
    ) {
        foreach ($diffResults as $match) {
            $targetFileNewPath = $targetBasePath . DIRECTORY_SEPARATOR . $match['reference']['path'];

            if ($shellMode) {
                $this->moveFileShell($match, $targetFileNewPath, $shellOutput);
            } else {
                $this->moveFileNative($match, $targetFileNewPath, $logOutput, $dirMode, $dryRun);
            }
        }
    }

    protected function moveFileShell(
        array $match,
        string $targetFileNewPath,
        OutputInterface $output
    ) {
        if ($output->isVerbose()) {
            $output->writeln(
                sprintf(
                    '# FILE                  %s | Size: %s | Time: %s | Checksum: %s',
                    $match['target']['path'],
                    $match['target']['size'] ?? '(unknown)',
                    $match['target']['mtime'] ?? '(unknown)',
                    $match['target']['checksum-summary'] ?? '(unknown)'
                )
            );
            $output->writeln(
                sprintf(
                    '# MATCHES FROM SNAPSHOT %s | Size: %s | Time: %s | Checksum: %s',
                    $match['reference']['path'],
                    $match['reference']['size'] ?? '(unknown)',
                    $match['reference']['mtime'] ?? '(unknown)',
                    $match['reference']['checksum-summary'] ?? '(unknown)'
                )
            );

            $output->write(sprintf(
                '# %s => %s',
                $match['target']['path'],
                $targetFileNewPath
            ));
        }

        if ($match['target']['path'] == $match['reference']['path']) {
            if ($output->isVerbose()) {
                $output->writeln(' # INFO: Already at the expected location.');
            }
        } else {
            if ($output->isVerbose()) {
                if (file_exists($targetFileNewPath)) {
                    $output->writeln(
                        ' # NOTICE: A different file already exists at that location, skipping.'
                    );
                    return;
                }
                $output->writeln('');
            }

            if (!is_dir($parentDir = dirname($targetFileNewPath))) {
                $output->writeln(sprintf(
                    'mkdir -p %s ;',
                    escapeshellarg($parentDir)
                ));
            }

            $output->writeln(sprintf(
                'mv %s %s ;',
                escapeshellarg($match['target']['path']),
                escapeshellarg($targetFileNewPath)
            ));
        }
    }

    protected function moveFileNative(
        array $match,
        string $targetFileNewPath,
        OutputInterface $output,
        int $dirMode,
        bool $dryRun
    ) {
        if ($output->isVerbose()) {
            $output->writeln(
                sprintf(
                    '* FILE                  %s | Size: %s | Time: %s | Checksum: %s',
                    $match['target']['path'],
                    $match['target']['size'] ?? '(unknown)',
                    $match['target']['mtime'] ?? '(unknown)',
                    $match['target']['checksum-summary'] ?? '(unknown)'
                )
            );
            $output->writeln(
                sprintf(
                    '  MATCHES FROM SNAPSHOT %s | Size: %s | Time: %s | Checksum: %s',
                    $match['reference']['path'],
                    $match['reference']['size'] ?? '(unknown)',
                    $match['reference']['mtime'] ?? '(unknown)',
                    $match['reference']['checksum-summary'] ?? '(unknown)'
                )
            );
        }

        $output->write(sprintf(
            '  %s => %s',
            $match['target']['path'],
            $targetFileNewPath
        ));

        if ($match['target']['path'] == $match['reference']['path']) {
            $output->writeln(' <info>✔ Already at the expected location.</info>');
        } else {
            if (file_exists($targetFileNewPath)) {
                $output->writeln(
                    '  <fg=red>✖ A different file already exists at that location, skipping.</>'
                );
                return;
            }

            if ($dryRun) {
                $output->writeln('  <fg=blue>✋ Dry-run enabled, keeping file in place.</>');
            } else {
                if (!is_dir($parentDir = dirname($targetFileNewPath))) {
                    if (!mkdir($parentDir, $dirMode, true)) {
                        $output->writeln(sprintf('  <error>✖ Could not create directory at %s.</error>', $parentDir));
                        return;
                    }
                }
                if (@rename($match['target']['path'], $targetFileNewPath)) {
                    $output->writeln('  <info>✔ OK, file moved successfully.</info>');
                } else {
                    $output->writeln('  <error>✖ Could not move file.</error>');
                }
            }
        }
    }
}
