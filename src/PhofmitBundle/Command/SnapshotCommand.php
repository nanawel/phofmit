<?php
namespace App\PhofmitBundle\Command;

use App\PhofmitBundle\Helper\DateTime;
use App\PhofmitBundle\Helper\FileChecksum;
use App\PhofmitBundle\Service\Mirror;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


class SnapshotCommand extends \Symfony\Component\Console\Command\Command
{
    /** @var Mirror */
    protected $mirrorService;

    /** @var \App\PhofmitBundle\Helper\FileChecksum */
    protected $fileChecksumHelper;

    public function __construct(
        Mirror $mirrorService,
        FileChecksum $fileChecksumHelper,
        string $name = null
    ) {
        $this->mirrorService = $mirrorService;
        $this->fileChecksumHelper = $fileChecksumHelper;
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
                'Include path patterns (strings or regexps if enclosed in slashes "/")'
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
                '{path}' => trim(preg_replace(['#/#', '#[\W]+#'], ['__', '-'], $path), '-'),
                '{now}' => date('Y-m-d_H-i-s')
            ]
        );

        $io->title('SNAPSHOT GENERATION');

        $io->writeln("<info>🛈 Snapshot will be written to $snapshotFilename.</info>");
        $io->writeln("⏳ Scanning folder $path...");
        $startTime = microtime(true);

        $snapshot = $this->mirrorService->snapshot($path, $io, $options);

        if ($snapshot['files'] && $io->isVerbose()) {
            $io->table(
                ['path', 'size', 'mtime', 'checksums'],
                array_map(function($fileData) {
                    return [
                        'path' => $fileData['path'],
                        'size' => $fileData['size'] ?? '(unknown)',
                        'mtime' => $fileData['mtime'] ?? '(unknown)',
                        'checksums' => $this->fileChecksumHelper->getPrintableSummary($fileData['checksums']),
                    ];
                }, $snapshot['files'])
            );
        }

        $io->writeln("<info>✎ Writing snapshot to $snapshotFilename...</info>");
        if ($bytes = file_put_contents($snapshotFilename, json_encode($snapshot, JSON_PRETTY_PRINT))) {
            $io->writeln(sprintf('<info>✔ OK, %d byte(s) have been written.</info>', $bytes));
        } else {
            $io->error("Could not write snapshot file at: $snapshotFilename");
        }

        $io->success(sprintf(
            "✔ Finished in %s",
            DateTime::secondsToTime(microtime(true) - $startTime)
        ));

        return self::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    protected function readOptions(InputInterface $input): array {
        $options = [];
        foreach ($input->getOption('option') as $option) {
            list($key, $value) = explode('=', $option);
            $options['scanner-config'][$key] = $value;
        }
        if ($includes = $input->getOption('include')) {
            foreach ($includes as $include) {
                $options['scanner-config']['include'][] = $include;
            }
        }

        return $options;
    }
}
