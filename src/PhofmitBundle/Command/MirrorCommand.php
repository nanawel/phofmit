<?php
namespace App\PhofmitBundle\Command;

use App\PhofmitBundle\Helper\DateTime;
use App\PhofmitBundle\Service\FileMover\FileMoverInterface;
use App\PhofmitBundle\Service\FileMover\Native;
use App\PhofmitBundle\Service\FileMover\Shell;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class MirrorCommand extends \Symfony\Component\Console\Command\Command
{
    const CANCELED = 2;

    public function __construct(
        protected \App\PhofmitBundle\Service\Mirror $mirrorService,
        string $name = null
    ) {
        parent::__construct($name);
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
                'Set scanner options: ' . implode(', ', array_keys($this->mirrorService->getScannerConfig()))
            )
            ->addOption(
                'dir-mode',
                'd',
                InputOption::VALUE_REQUIRED,
                'Mode for directories created when mirroring.',
                '0775'
            )
            ->addOption(
                'locale',
                'l',
                InputOption::VALUE_REQUIRED,
                'Locale to use when dealing with paths using non-ASCII characters.',
                'en_US.UTF-8'
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
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        /** @var SymfonyStyle $errIo */
        $errIo = $io->getErrorStyle();
        $errIo->setDecorated(true);

        $errIo->title('MIRRORING');

        $referenceSnapshotFilename = $input->getArgument('snapshot-filename');
        if (!is_file($referenceSnapshotFilename) || !is_readable($referenceSnapshotFilename)) {
            $errIo->error('Invalid snapshot path: ' . $referenceSnapshotFilename);

            return self::FAILURE;
        }

        $referenceSnapshotFilename = realpath($referenceSnapshotFilename);

        $options = $this->readOptions($input);

        // See https://www.php.net/manual/fr/function.escapeshellarg.php#99213
        setlocale(LC_CTYPE, $options['locale']);

        if ($options['shell-mode']) {
            $errIo->note('Using shell output.');
        }

        $errIo->writeln(sprintf('<info>🛈 Using snapshot file at: %s</info>', $referenceSnapshotFilename));

        $startTime = microtime(true);
        if ($options['dry-run']) {
            $errIo->note('DRY-RUN ENABLED.');
        }

        if ($options['dry-run'] && $options['shell-mode']) {
            $errIo->error('Shell output and dry-mode are mutually exclusive.');

            return self::FAILURE;
        }

        try {
            $referenceSnapshot = $this->loadSnapshot($referenceSnapshotFilename);
        }
        catch (\Throwable $throwable) {
            $errIo->error($throwable->getMessage());

            return self::FAILURE;
        }

        if ($targetSnapshotFilename = $input->getOption('target-snapshot')) {
            $errIo->writeln(sprintf('<info>🛈 Loading target snapshot from: %s</info>', $targetSnapshotFilename));
            $targetSnapshot = $this->loadSnapshot($targetSnapshotFilename);

            if (!is_dir($targetSnapshot['base-path'])) {
                throw new \InvalidArgumentException(
                    'The base path from snapshot does not exist: ' . $targetSnapshot['base-path']
                );
            }

            $errIo->writeln(sprintf('<info>🛈 Target folder is at: %s</info>', $targetSnapshot['base-path']));

            $errIo->writeln(sprintf(
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

            $errIo->writeln(sprintf('<info>🛈 Target folder is at: %s</info>', $path));

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
                    if (strtolower(trim((string) $io->ask($question, 'n'))) === 'y') {
                        $runSnapshot = false;
                    }
                } catch (\Throwable) {
                    // just ignore
                }
            }

            if ($runSnapshot) {
                // Use scanner config from reference snapshot to get comparable results
                $options['scanner-config'] = $referenceSnapshot['scanner-config'];

                $targetSnapshot = $this->mirrorService->snapshot($path, $errIo, $options);

                // Save result in cache file to speed up next execution if any
                @file_put_contents($targetSnapshotCacheFilename, json_encode($targetSnapshot, JSON_PRETTY_PRINT));
            }
        }

        if (!isset($targetSnapshot)) {
            throw new \RuntimeException('Could not load target snapshot!');
        }

        $diffScannerConfig = $this->mirrorService->getScannerConfig($options);

        if (!$this->validateSnapshots($referenceSnapshot, $targetSnapshot, $errIo)) {
            $errIo->writeln('Canceled.');

            return self::CANCELED;
        }

        $errIo->writeln(
            '⏳ Searching for matching files with reference snapshot (this might take a while)...'
        );
        $diff = $this->mirrorService->diffSnapshots(
            $referenceSnapshot,
            $targetSnapshot,
            $diffScannerConfig,
            $errIo
        );

        $errIo->writeln(sprintf('<info>🛈 %d target file(s) found with matching reference.</info>', count($diff)));
        $errIo->writeln('⏳ Mirroring...');

        if ($options['shell-mode']) {
            $errIo->note('Shell mode enabled: Printing shell commands to STDOUT to allow piping.');
        }

        /** @var FileMoverInterface $fileMover */
        $fileMover = $this->createFileMover($options, $io, $errIo);

        $movedFilesCnt = $this->applyMirroring(
            $diff,
            $targetSnapshot['base-path'],
            $errIo,
            $fileMover
        );
        $errIo->newLine();

        $errIo->success(sprintf(
            "✔ Finished in %s. %d file(s) have been moved%s.",
            DateTime::secondsToTime((int) (microtime(true) - $startTime)),
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

    protected function readOptions(InputInterface $input): array {
        $options = [
            'dry-run'    => $input->getOption('dry-run'),
            'shell-mode' => $input->getOption('shell'),
            'dir-mode'   => $input->getOption('dir-mode'),
            'locale'     => $input->getOption('locale'),
            'scanner-config' => [],
        ];

        // Try to handle wrong octal representation if possible
        $dirMode = $options['dir-mode'];
        if (strlen((string) $dirMode) === 3) {
            $options['dir-mode'] = sscanf('0' . $dirMode, '%o')[0];
        } elseif (strlen((string) $dirMode) === 4) {
            $options['dir-mode'] = sscanf($dirMode, '%o')[0];
        } else {
            throw new \InvalidArgumentException('Invalid directory mode: ' . $options['dir-mode']);
        }

        foreach ($input->getOption('option') as $option) {
            if ((str_starts_with((string) $option, 'use-') || str_starts_with((string) $option, 'ignore-'))
                && !str_contains((string) $option, '=')
            ) {
                $option .= '=1';
            }

            [$key, $value] = explode('=', (string) $option);
            $options['scanner-config'][$key] = $value;
        }

        return $options;
    }

    /**
     * @param $filename
     * @throws \Exception
     */
    protected function loadSnapshot(string $filename): array {
        if (!is_file($filename) || !is_readable($filename)) {
            throw new \Exception($filename . ' does not exist or is not readable.');
        }

        return json_decode(file_get_contents($filename), true);
    }

    protected function validateSnapshots(
        array $referenceSnapshot,
        array $targetSnapshot,
        SymfonyStyle $logIo
    ): bool {
        ksort($referenceSnapshot['scanner-config']);
        ksort($targetSnapshot['scanner-config']);

        if ($referenceSnapshot['scanner-config'] !== $targetSnapshot['scanner-config']) {
            $logIo->warning(sprintf(
                "Reference and target snapshots scanner configurations differ.\n"
                . "Reference: %s\nTarget: %s",
                json_encode($referenceSnapshot['scanner-config'], JSON_PRETTY_PRINT),
                json_encode($targetSnapshot['scanner-config'], JSON_PRETTY_PRINT)
            ));
            if (strtolower(trim((string) $logIo->ask('Continue anyway?', 'n'))) !== 'y') {
                return false;
            }
        }

        return true;
    }

    public function applyMirroring(
        array $diffResults,
        string $targetBasePath,
        SymfonyStyle $io,
        FileMoverInterface $fileMover
    ): int {
        $movedFilesCnt = 0;

        if ($diffResults === []) {
            $io->note('No syncable files found.');
        } else {
            $pb = new ProgressBar($io, 0, 0.5);
            $pb->setFormat("%current%/%max% [%bar%] %elapsed:6s%/%estimated:-6s% %memory:6s%\n %message%");
            $pb->setMessage('Starting...');
            $pb->setMaxSteps(count($diffResults));
            $pb->start();

            foreach ($diffResults as $match) {
                $targetFileCurrentPath = $targetBasePath . DIRECTORY_SEPARATOR . $match['target']['path'];
                $targetFileNewPath = $targetBasePath . DIRECTORY_SEPARATOR . $match['reference']['path'];

                $pb->setMessage($targetFileCurrentPath);
                $pb->advance();

                if ($fileMover->moveFile($targetFileCurrentPath, $targetFileNewPath, $match)) {
                    $movedFilesCnt++;
                }
            }

            $pb->setMessage('Finished.');
            $pb->finish();
            $io->newLine();
        }

        return $movedFilesCnt;
    }

    protected function createFileMover(
        array $options,
        OutputInterface $io,
        SymfonyStyle $errIo
    ): FileMoverInterface {
        if ($options['shell-mode']) {
            return new Shell($io, $options['dir-mode']);
        }

        return new Native($errIo, $options['dir-mode'], $options['dry-run']);
    }

    protected function getTargetSnapshotCacheFilename(string $path, array $scannerConfig): string {
        $hash = sha1(json_encode([
            'path' => $path,
            'scanner-config' => $scannerConfig
        ]));

        return sprintf('%s%s%s.phofmit.json', sys_get_temp_dir(), DIRECTORY_SEPARATOR, $hash);
    }
}
