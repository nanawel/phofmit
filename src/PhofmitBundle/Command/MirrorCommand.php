<?php
namespace App\PhofmitBundle\Command;

use App\PhofmitBundle\Helper\DateTime;
use App\PhofmitBundle\Service\Mirror;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class MirrorCommand extends \Symfony\Component\Console\Command\Command
{
    /** @var Mirror */
    protected $mirrorService;

    public function __construct(
        Mirror $mirrorService,
        string $name = null
    ) {
        parent::__construct($name);
        $this->mirrorService = $mirrorService;
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

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        /** @var SymfonyStyle $logIo */
        $logIo = $io->getErrorStyle();
        $logIo->setDecorated(true);
        $shellIo = $io;

        $referenceSnapshotFilename = $input->getArgument('snapshot-filename');
        if (!is_file($referenceSnapshotFilename) || !is_readable($referenceSnapshotFilename)) {
            $logIo->error('Invalid snapshot path: ' . $referenceSnapshotFilename);

            return self::FAILURE;
        }
        $referenceSnapshotFilename = realpath($referenceSnapshotFilename);

        $options = $this->readOptions($input);

        if ($options['shell-mode']) {
            $logIo->note('Using shell output.');
        }

        $logIo->writeln("<info>🛈 Using snapshot file at: $referenceSnapshotFilename</info>");

        $startTime = microtime(true);
        if ($options['dry-run']) {
            $logIo->note('DRY-RUN ENABLED.');
        }

        if ($options['dry-run'] && $options['shell-mode']) {
            $logIo->error('Shell output and dry-mode are mutually exclusive.');

            return self::FAILURE;
        }

        try {
            $referenceSnapshot = $this->loadSnapshot($referenceSnapshotFilename);
        }
        catch (\Throwable $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        if ($targetSnapshotFilename = $input->getOption('target-snapshot')) {
            $logIo->writeln(sprintf('<info>🛈 Loading target snapshot from: %s</info>', $targetSnapshotFilename));
            $targetSnapshot = $this->loadSnapshot($targetSnapshotFilename);

            if (!is_dir($targetSnapshot['base-path'])) {
                throw new \InvalidArgumentException(
                    'The base path from snapshot does not exist: ' . $targetSnapshot['base-path']
                );
            }
            $logIo->writeln("<info>🛈 Target folder is at: {$targetSnapshot['base-path']}</info>");

            $logIo->writeln(sprintf(
                '<info>🛈 %d file(s) found in target snapshot.</info>',
                count($targetSnapshot['files'])
            ));
        } else {
            if (!$path = $input->getArgument('path')) {
                throw new \InvalidArgumentException('You must specify a path if no --target-snapshot is given.');
            }
            if (!is_dir($path)) {
                throw new \InvalidArgumentException('Invalid path: ' . $path);
            }
            $logIo->writeln("<info>🛈 Target folder is at: $path</info>");

            $targetSnapshotCacheFilename = $this->getTargetSnapshotCacheFilename(
                $path,
                $referenceSnapshot['scanner-config']
            );

            $runSnapshot = true;
            if (is_file($targetSnapshotCacheFilename) && is_readable($targetSnapshotCacheFilename)) {
                try {
                    $targetSnapshot = $this->loadSnapshot($targetSnapshotCacheFilename);

                    $question = sprintf(
                        '❓ A snapshot from a previous run on the same folder created on %s has been found. '
                        . 'Do you want to use it instead of scanning the folder again?',
                        $targetSnapshot['date']
                    );
                    if (strtolower(trim($io->ask($question, 'n'))) == 'y') {
                        $runSnapshot = false;
                    }
                } catch (\Throwable $e) {
                    // just ignore
                }
            }

            if ($runSnapshot) {
                // Use scanner config from reference snapshot to get comparable results
                $options['scanner-config'] = $referenceSnapshot['scanner-config'];
                $targetSnapshot = $this->mirrorService->snapshot($path, $logIo, $options);

                @file_put_contents($targetSnapshotCacheFilename, json_encode($targetSnapshot, JSON_PRETTY_PRINT));
            }
        }

        $diffScannerConfig = $this->mirrorService->getScannerConfig($options);

        $logIo->writeln(
            '⏳ Searching for matching files with reference snapshot (this might take a while)...'
        );
        $diff = $this->mirrorService->diffSnapshots(
            $referenceSnapshot,
            $targetSnapshot,
            $diffScannerConfig,
            $logIo
        );

        $logIo->writeln(sprintf('<info>🛈 %d target file(s) found with matching reference.</info>', count($diff)));

        if ($options['shell-mode']) {
            $logIo->note('Shell mode enabled: Printing shell commands to STDOUT to allow piping.');
        }
        $movedFilesCnt = $this->applyMirroring(
            $diff,
            $targetSnapshot['base-path'],
            $logIo,
            $shellIo,
            $options['dir-mode'],
            $options['shell-mode'],
            $options['dry-run']
        );
        $logIo->newLine();

        $logIo->success(sprintf(
            "✔ Finished in %s. %d file(s) have been moved%s.",
            DateTime::secondsToTime(microtime(true) - $startTime),
            $movedFilesCnt,
            $options['dry-run']
                ? ' (dry-run mode enabled)'
                : ($options['shell-mode']
                    ? ' (shell mode enabled)'
                    : ''
                )
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
     * @throws \Exception
     */
    protected function loadSnapshot($filename): array {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new \Exception("$filename does not exist or is not readable.");
        }

        return json_decode(file_get_contents($filename), true);
    }

    /**
     * @param array $diffResults
     * @param string $targetBasePath
     * @param SymfonyStyle $logIo
     * @param SymfonyStyle $shellIo
     * @param array $options
     * @return int
     */
    public function applyMirroring(
        array $diffResults,
        string $targetBasePath,
        SymfonyStyle $logIo,
        SymfonyStyle $shellIo,
        int $dirMode,
        bool $shellMode,
        bool $dryRun
    ) {
        $movedFilesCnt = 0;

        if (empty($diffResults)) {
            $logIo->note('No syncable files found.');
        } else {
            foreach ($diffResults as $match) {
                $targetFileCurrentPath = $targetBasePath . DIRECTORY_SEPARATOR . $match['target']['path'];
                $targetFileNewPath = $targetBasePath . DIRECTORY_SEPARATOR . $match['reference']['path'];

                if ($shellMode) {
                    if ($this->moveFileShell($targetFileCurrentPath, $targetFileNewPath, $match, $shellIo)) {
                        $movedFilesCnt++;
                    }
                } else {
                    if ($this->moveFileNative(
                        $targetFileCurrentPath,
                        $targetFileNewPath,
                        $match,
                        $logIo,
                        $dirMode,
                        $dryRun
                    )) {
                        $movedFilesCnt++;
                    }
                }
            }
        }

        return $movedFilesCnt;
    }

    /**
     * @param string $targetFileCurrentPath
     * @param string $targetFileNewPath
     * @param array $match
     * @param InputInterface $input
     * @param SymfonyStyle $io
     * @return bool
     */
    protected function moveFileShell(
        string $targetFileCurrentPath,
        string $targetFileNewPath,
        array $match,
        SymfonyStyle $io
    ) {
        if ($io->isVerbose()) {
            $io->writeln(sprintf(
                '# %s => %s',
                $targetFileCurrentPath,
                $targetFileNewPath
            ));
        }
        if ($io->isVeryVerbose()) {
            $io->writeln(
                sprintf(
                    '# FILE                  %s | Size: %s | Time: %s | Checksum: %s',
                    $match['target']['path'],
                    $match['target']['size'] ?? '(unknown)',
                    $match['target']['mtime'] ?? '(unknown)',
                    $match['target']['checksum-summary'] ?? '(unknown)'
                )
            );
            $io->writeln(
                sprintf(
                    '# MATCHES FROM SNAPSHOT %s | Size: %s | Time: %s | Checksum: %s',
                    $match['reference']['path'],
                    $match['reference']['size'] ?? '(unknown)',
                    $match['reference']['mtime'] ?? '(unknown)',
                    $match['reference']['checksum-summary'] ?? '(unknown)'
                )
            );
        }

        if ($match['target']['path'] == $match['reference']['path']) {
            if ($io->isVerbose()) {
                $io->writeln('# INFO: Already at the expected location.');
            }
        } else {
            if ($io->isVerbose()) {
                if (file_exists($targetFileNewPath)) {
                    $io->writeln(
                        '# WARNING: A different file already exists at that location, skipping.'
                    );

                    return false;
                }
            }

            if (!is_dir($parentDir = dirname($targetFileNewPath))) {
                $io->writeln(sprintf(
                    'mkdir -p %s ;',
                    escapeshellarg($parentDir)
                ));
            }

            $io->writeln(sprintf(
                'mv %s %s ;',
                escapeshellarg($targetFileCurrentPath),
                escapeshellarg($targetFileNewPath)
            ));
        }

        return false;   // This method does not really *move* files so always return FALSE
    }

    /**
     * @param string $targetFileCurrentPath
     * @param string $targetFileNewPath
     * @param array $match
     * @param SymfonyStyle $io
     * @param int $dirMode
     * @param bool $dryRun
     * @return bool
     */
    protected function moveFileNative(
        string $targetFileCurrentPath,
        string $targetFileNewPath,
        array $match,
        SymfonyStyle $io,
        int $dirMode,
        bool $dryRun
    ) {
        if ($io->isVerbose()) {
            $io->writeln(sprintf('🗋 %s => %s', $match['target']['path'], $match['reference']['path']));
        }
        if ($io->isVeryVerbose()) {
            $io->writeln(
                sprintf(
                    '* FILE                  %s | Size: %s | Time: %s | Checksum: %s',
                    $match['target']['path'],
                    $match['target']['size'] ?? '(unknown)',
                    $match['target']['mtime'] ?? '(unknown)',
                    $match['target']['checksum-summary'] ?? '(unknown)'
                )
            );
            $io->writeln(
                sprintf(
                    '  MATCHES FROM SNAPSHOT %s | Size: %s | Time: %s | Checksum: %s',
                    $match['reference']['path'],
                    $match['reference']['size'] ?? '(unknown)',
                    $match['reference']['mtime'] ?? '(unknown)',
                    $match['reference']['checksum-summary'] ?? '(unknown)'
                )
            );
        }

        if ($match['target']['path'] == $match['reference']['path']) {
            if ($io->isVerbose()) {
                $io->writeln('<info>✔ Already at the expected location.</info>');
            }
        } else {
            if (file_exists($targetFileNewPath)) {
                $io->warning(sprintf('A file already exists at %s, skipping.', $targetFileNewPath));

                return false;
            }

            $question = sprintf(
                "❓ Move file from %s\n" .
                "               to <fg=magenta>%s</>?",
                $targetFileCurrentPath,
                $targetFileNewPath
            );
            if (strtolower(trim($io->ask($question, 'y'))) != 'y') {
                $io->writeln('<fg=blue>🖮 Skipped.</>');

                return false;
            }

            if ($dryRun) {
                $io->writeln('<fg=blue>✋ Dry-run enabled, keeping file in place.</>');
            } else {
                if (!is_dir($parentDir = dirname($targetFileNewPath))) {
                    if (!mkdir($parentDir, $dirMode, true)) {
                        $io->error(sprintf('Could not create directory %s.', $parentDir));

                        return false;
                    }
                }
                if (@rename($targetFileCurrentPath, $targetFileNewPath)) {
                    $io->writeln(sprintf('<info>✔ File %s moved successfully.</info>', $targetFileCurrentPath));

                    return true;
                } else {
                    $io->error(sprintf(
                        'Could not move file from %s to %s.',
                        $targetFileCurrentPath,
                        $targetFileNewPath
                    ));

                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @param string $path
     * @param array $scannerConfig
     * @return string
     */
    protected function getTargetSnapshotCacheFilename(string $path, array $scannerConfig) {
        $hash = sha1(json_encode([
            'path' => $path,
            'scanner-config' => $scannerConfig
        ]));

        return sprintf('%s%s%s.phofmit.json', sys_get_temp_dir(), DIRECTORY_SEPARATOR, $hash);
    }
}
