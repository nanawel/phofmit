<?php

namespace App\PhofmitBundle\Service\FileMover;


use Symfony\Component\Console\Output\OutputInterface;

class Native implements FileMoverInterface
{
    /** @var OutputInterface */
    protected $io;

    /** @var int */
    protected $dirMode;

    /** @var bool */
    protected $dryRun;

    public function __construct(
        OutputInterface $io,
        int $dirMode = 0775,
        bool $dryRun = false
    ) {
        $this->io = $io;
        $this->dirMode = $dirMode;
        $this->dryRun = $dryRun;
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
            $this->io->writeln(sprintf('🗋 %s => %s', $match['target']['path'], $match['reference']['path']));
        }
        if ($this->io->isVeryVerbose()) {
            $this->io->writeln(
                sprintf(
                    '* FILE                  %s | Size: %s | Time: %s | Checksum: %s',
                    $match['target']['path'],
                    $match['target']['size'] ?? '(unknown)',
                    $match['target']['mtime'] ?? '(unknown)',
                    $match['target']['checksum-summary'] ?? '(unknown)'
                )
            );
            $this->io->writeln(
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
            if ($this->io->isVerbose()) {
                $this->io->writeln('  <info>✔ Already at the expected location.</info>');
            }
        } else {
            if (file_exists($targetFileNewPath)) {
                $this->io->warning(sprintf(
                    'Cannot move file %s: a file already exists at %s, skipping.',
                    $targetFileCurrentPath,
                    $targetFileNewPath
                ));

                return false;
            }

            $question = sprintf(
                "❓ Move file from %s\n" .
                "               to <fg=magenta>%s</>?",
                $targetFileCurrentPath,
                $targetFileNewPath
            );
            if (strtolower(trim($this->io->ask($question, 'y'))) != 'y') {
                $this->io->writeln('<fg=blue>🖮 Skipped.</>');

                return false;
            }

            if ($this->dryRun) {
                $this->io->writeln('<fg=blue>✋ Dry-run enabled, keeping file in place.</>');
            } else {
                if (!is_dir($parentDir = dirname($targetFileNewPath))) {
                    if (!mkdir($parentDir, $this->dirMode, true)) {
                        $this->io->error(sprintf('Could not create directory %s.', $parentDir));

                        return false;
                    }
                }
                if (@rename($targetFileCurrentPath, $targetFileNewPath)) {
                    $this->io->writeln(sprintf('<info>✔ File %s moved successfully.</info>', $targetFileCurrentPath));

                    return true;
                } else {
                    $this->io->error(sprintf(
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
}
