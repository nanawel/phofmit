<?php
namespace App\PhofmitBundle\Command;

use App\PhofmitBundle\Helper\DateTime;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class SnapshotCommand extends \Symfony\Component\Console\Command\Command
{
    public const TERMINAL_DEFAULT_WIDTH = 120;

    public function __construct(
        protected \App\PhofmitBundle\Service\Mirror $mirrorService,
        protected \App\PhofmitBundle\Helper\FileChecksum $fileChecksumHelper,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('phofmit:snapshot')
            ->setDescription('[Phofmit] Scan folder and create snapshot file')
            ->addOption(
                'snapshot-filename',
                'f',
                InputOption::VALUE_REQUIRED,
                'Filename of the generated snapshot file. Use {now} to inject current date/time.',
                '{hostname}-{path}-{now}.phofmit.json'
            )
            ->addOption(
                'option',
                'o',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Set scanner options: ' . implode(', ', array_keys($this->mirrorService->getScannerConfig()))
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Include path patterns (strings, or regexps if enclosed in slashes "/")'
            )
            ->addOption(
                'exclude',
                'E',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Exclude path patterns (strings, or regexps if enclosed in slashes "/")'
            )
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path of target folder'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!is_dir($path = $input->getArgument('path'))) {
            throw new \InvalidArgumentException('Invalid path: ' . $path);
        }

        $io = new SymfonyStyle($input, $output);

        $options = $this->readOptions($input);

        $snapshotFilename = strtr(
            $input->getOption('snapshot-filename'),
            [
                '{hostname}' => gethostname(),
                '{path}' => trim(
                    (string) preg_replace(
                        ['#/#', '#[\W]+#'],
                        ['__', '-'],
                        trim((string) $path, '/')
                    ),
                    '-'
                ),
                '{now}' => date('Y-m-d_H-i-s')
            ]
        );

        $io->title('SNAPSHOT GENERATION');

        $io->writeln(sprintf('<info>🛈 Snapshot will be written to %s.</info>', $snapshotFilename));
        $io->writeln(sprintf('⏳ Scanning folder %s...', $path));

        $startTime = microtime(true);

        $snapshot = $this->mirrorService->snapshot($path, $io, $options);

        if ($snapshot['files'] && $io->isVerbose()) {
            $table = new Table($io);
            $table->setHeaders(['path', 'size', 'mtime', 'checksums']);
            $colsMaxWidth = [];
            $table->setRows(array_map(function($fileData) use (&$colsMaxWidth): array {
                $return = [
                    'path' => $fileData['path'],
                    'size' => $fileData['size'] ?? '(unknown)',
                    'mtime' => $fileData['mtime'] ?? '(unknown)',
                    'checksums' => $this->fileChecksumHelper->getPrintableSummary($fileData['checksums']),
                ];
                foreach ($return as $col => $v) {
                    $colsMaxWidth[$col] = max($colsMaxWidth[$col] ?? 0, mb_strlen($v) + 2);
                }

                return $return;
            }, $snapshot['files']));
            $widthLeft = (getenv('COLUMNS') ?: self::TERMINAL_DEFAULT_WIDTH) - 7;
            unset($colsMaxWidth['path']);
            $table->setColumnMaxWidth(0, max(12, $widthLeft - array_sum($colsMaxWidth)));

            $table->render();
            $io->newLine();
        }

        $io->writeln(sprintf('<info>✎ Writing snapshot to %s...</info>', $snapshotFilename));
        if ($bytes = file_put_contents($snapshotFilename, json_encode($snapshot, JSON_PRETTY_PRINT))) {
            $io->writeln(sprintf('<info>✔ OK, %d byte(s) have been written.</info>', $bytes));
        } else {
            $io->error('Could not write snapshot file at: ' . $snapshotFilename);
        }

        $io->success(sprintf(
            "✔ Finished in %s",
            DateTime::secondsToTime((int) (microtime(true) - $startTime))
        ));

        return self::SUCCESS;
    }

    protected function readOptions(InputInterface $input): array {
        $options = [];
        foreach ($input->getOption('option') as $option) {
            [$key, $value] = explode('=', (string) $option);
            $options['scanner-config'][$key] = $value;
        }

        if ($includes = $input->getOption('include')) {
            foreach ($includes as $include) {
                $options['scanner-config']['include'][] = $include;
            }
        }

        if ($excludes = $input->getOption('exclude')) {
            foreach ($excludes as $exclude) {
                $options['scanner-config']['exclude'][] = $exclude;
            }
        }

        return $options;
    }
}
