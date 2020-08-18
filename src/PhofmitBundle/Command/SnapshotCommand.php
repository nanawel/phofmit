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


class SnapshotCommand extends \Symfony\Component\Console\Command\Command
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
        $this->setName('phofmit:snapshot')
            ->setDescription('[Phofmit] Scan folder and create snapshot file')
            ->addOption(
                'snapshot-filename',
                'f',
                InputOption::VALUE_REQUIRED,
                'Filename of the generated snapshot file. Use {now} to inject current date/time.',
                '{hostname}-{path}-{now}.phofmit.json'
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

        $snapshotFilename = strtr(
            $input->getOption('snapshot-filename'),
            [
                '{hostname}' => gethostname(),
                '{path}' => trim(preg_replace(['#/#', '#[\W]+#'], ['__', '-'], $path), '-'),
                '{now}' => date('Y-m-d_H-i-s')
            ]
        );

        $output->writeln("<info>🛈 Snapshot will be written to $snapshotFilename.</info>");
        $output->writeln("<info>⏳ Scanning folder $path...</info>");
        $startTime = microtime(true);

        $snapshot = $this->mirror->snapshot($path, $output);
        $output->writeln('');

        $output->writeln("<info>✎ Writing snapshot to $snapshotFilename...</info>");
        file_put_contents($snapshotFilename, json_encode($snapshot, JSON_PRETTY_PRINT));
        $output->writeln(sprintf(
            "<info>✔ Finished in %s</info>",
            DateTime::secondsToTime(microtime(true) - $startTime)
        ));

        return self::SUCCESS;
    }
}
