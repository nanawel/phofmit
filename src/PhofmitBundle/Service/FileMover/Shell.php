<?php

namespace App\PhofmitBundle\Service\FileMover;


class Shell implements FileMoverInterface
{
    /** @var string[] */
    protected $createdDirectories = [];

    public function __construct(
        protected \Symfony\Component\Console\Output\OutputInterface $io,
        protected int $dirMode = 0775
    ) {
    }

    /**
     * @inheritDoc
     */
    public function moveFile(
        string $targetFileCurrentPath,
        string $targetFileNewPath,
        array $match
    ): bool {
        if ($this->io->isVerbose()) {
            $this->io->writeln(sprintf(
                '# %s => %s',
                $targetFileCurrentPath,
                $targetFileNewPath
            ));
        }

        if ($this->io->isVeryVerbose()) {
            $this->io->writeln(
                sprintf(
                    '# FILE                  %s | Size: %s | Time: %s | Checksum: %s',
                    $match['target']['path'],
                    $match['target']['size'] ?? '(unknown)',
                    $match['target']['mtime'] ?? '(unknown)',
                    $match['target']['checksum-summary'] ?? '(unknown)'
                )
            );
            $this->io->writeln(
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
            if ($this->io->isVerbose()) {
                $this->io->writeln('# INFO: Already at the expected location.');
            }
        } else {
            if ($this->io->isVerbose() && file_exists($targetFileNewPath)) {
                $this->io->writeln(
                    '# WARNING: A different file already exists at that location, skipping.'
                );
                return false;
            }

            $this->createDirectoryIfMissing(dirname($targetFileNewPath));

            $this->io->writeln(sprintf(
                'mv %s %s ;',
                escapeshellarg($targetFileCurrentPath),
                escapeshellarg($targetFileNewPath)
            ));
        }

        return false;   // This method does not really *move* files so always return FALSE
    }

    /**
     * @param string $dir
     */
    protected function createDirectoryIfMissing($dir): void {
        if (!is_dir($dir) && !isset($this->createdDirectories[$dir])) {
            $this->io->writeln(sprintf(
                'mkdir -m %o -p %s ;',
                $this->dirMode,
                escapeshellarg($dir)
            ));
            $this->createdDirectories[$dir] = true;
        }
    }
}
