<?php

namespace App\PhofmitBundle\Service;


use App\PhofmitBundle\Helper\FileChecksum;
use App\PhofmitBundle\Model\File;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Mirror
{
    const VERSION                     = '1.0';
    const CHECKSUM_DEFAULT_CHUNK_SIZE = 512 * 1024;
    const CHECKSUM_DEFAULT_ALGO       = 'sha1';

    /** @var \App\PhofmitBundle\Helper\FileChecksum */
    protected $fileChecksumHelper;

    public function __construct(
        FileChecksum $fileChecksumHelper
    ) {
        $this->fileChecksumHelper = $fileChecksumHelper;
    }

    /**
     * @param string $path
     * @param array $options
     * @return array
     */
    public function snapshot(
        string $path,
        SymfonyStyle $io,
        array $options = []
    ): array {
        $scannerConfig = $this->getScannerConfig($options);
        $finder = $this->buildFinder($path, $options, $scannerConfig, $io);

        $pb = new ProgressBar($io, 0, 0.5);
        $pb->setFormat("%current% [%bar%] %memory:6s%\n %message%");
        $pb->setMessage('Starting...');
        $pb->start();

        $splFiles = [];
        foreach ($finder as $splFile) {
            $pb->setMessage("🖹 {$splFile->getRelativePathname()}");
            $splFiles[] = $splFile;
            $pb->advance();
        }

        $files = [];
        if (!$splFiles) {
            $io->warning('No file found in specified target folder with given include patterns (if any).');
        } else {
            $pb->setMaxSteps(count($splFiles));
            $pb->setFormat("%current:-4s%/%max:-4s% [%bar%] %elapsed:6s%/%estimated:-6s% %memory:6s%\n %message%");
            $pb->start();
            foreach ($splFiles as $splFile) {
                $pb->setMessage("🔬 {$splFile->getRelativePathname()}");
                try {
                    $files[] = $this->scanFile($splFile, $scannerConfig)->toArray();
                } catch (\Throwable $e) {
                    $io->warning("{$splFile->getRelativePathname()}: {$e->getMessage()}");
                }
                $pb->advance();
            }
            $pb->finish();
            $io->newLine();

            $io->writeln(sprintf('<info>🛈 %d file(s) have been analyzed.</info>', count($files)));
        }

        return [
            'version'        => self::VERSION,
            'hostname'       => gethostname(),
            'date'           => date('c'),
            'base-path'      => realpath($path),
            'scanner-config' => $scannerConfig,
            'files'          => $files
        ];
    }

    /**
     * @param array $options
     * @return Finder
     */
    protected function buildFinder(
        string $path,
        array $options,
        array $scannerConfig,
        SymfonyStyle $io
    ) {
        $finder = new Finder();
        $finder->files()
            ->in($path);

        if ($options['ignore-unreadable'] ?? true) {
            $finder->ignoreUnreadableDirs();
            if ($io->isVerbose()) {
                $io->writeln('<info>🛈 ignore-unreadable option enabled.</info>');
            }
        }
        if ($options['follow-links'] ?? true) {
            $finder->followLinks();
            if ($io->isVerbose()) {
                $io->writeln('<info>🛈 follow-links option enabled.</info>');
            }
        }
        if ($options['depth'] ?? false) {
            $finder->depth($options['depth']);
            if ($io->isVerbose()) {
                $io->writeln(sprintf('<info>🛈 depth option set to "%s".</info>', $options['depth']));
            }
        }
        if ($scannerConfig['include'] ?? false) {
            if (!is_array($scannerConfig['include'])) {
                throw new \InvalidArgumentException('"include" option must be an array of strings.');
            }
            foreach ($scannerConfig['include'] as $include) {
                $finder->path($include);
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('<info>🛈 included path pattern: "%s".</info>', $include));
                }
            }
        }

        return $finder;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $scannerConfig
     * @return File
     * @throws \Error
     */
    protected function scanFile(
        SplFileInfo $fileInfo,
        array $scannerConfig
    ): File {
        $file = new File();
        $file->setAbsolutePath($fileInfo->getRealPath())
            ->setPath($fileInfo->getRelativePathname());

        if ($scannerConfig['use-size']) {
            $file->setSize($fileInfo->getSize());
        }
        if ($scannerConfig['use-mtime']) {
            $file->setMtime($fileInfo->getMTime());
        }
        if ($scannerConfig['use-checksum']) {
            $file->setChecksums($this->extractChecksums($fileInfo, $scannerConfig));
        }

        return $file;
    }

    /**
     * @param SplFileInfo $fileInfo
     * @param array $scannerConfig
     * @return array
     */
    protected function extractChecksums(
        SplFileInfo $fileInfo,
        array $scannerConfig
    ) {
        $checksums = [];

        $chunkSize = min($fileInfo->getSize(), $scannerConfig['beginning-chunk-size']);

        if ($chunkSize > 0) {
            $h = fopen($fileInfo->getRealPath(), 'rb');
            $data = fread($h, $chunkSize);
            fclose($h);
        } else {
            $data = '';
        }

        $checksums[] = [
            'start'         => 0,
            'length'        => $scannerConfig['beginning-chunk-size'],
            'actual-length' => $chunkSize,
            'algo'          => $scannerConfig['beginning-chunk-algo'],
            'value'         => hash($scannerConfig['beginning-chunk-algo'], $data)
        ];

        // There might be another checksum section at the end of the file in the future

        return $checksums;
    }

    /**
     * @param array $reference
     * @param array $target
     * @param array $scannerConfig
     * @return array
     */
    public function diffSnapshots(
        array $reference,
        array $target,
        array $scannerConfig,
        SymfonyStyle $io
    ) {
        $matches = [];

        $referenceIndex = [];
        foreach ($reference['files'] as $fileData) {
            $file = File::fromArray($fileData);
            $fileKey = spl_object_hash($file);

            if ($scannerConfig['use-size']) {
                $referenceIndex['by-size'][$file->getSize()][$fileKey] = $file;
            }
            if ($scannerConfig['use-mtime']) {
                $referenceIndex['by-mtime'][$file->getMtime()][$fileKey] = $file;
            }
            if ($scannerConfig['use-checksum']) {
                foreach ($file->getChecksums() as $checksum) {
                    $referenceIndex['by-checksum'][$this->getChecksumKey($checksum)][$fileKey] = $file;
                }
            }
            if ($scannerConfig['use-filename']) {
                $referenceIndex['by-filename'][basename($file->getPath())][$fileKey] = $file;
            }
        }

        $pb = new ProgressBar($io, 0, 0.5);
        $pb->setFormat("%current%/%max% [%bar%] %elapsed:6s%/%estimated:-6s% %memory:6s%\n %message%");
        $pb->setMessage('Starting...');
        $pb->setMaxSteps(count($target['files']));
        $pb->start();

        foreach ($target['files'] as $fileData) {
            $file = File::fromArray($fileData);

            $pb->setMessage($file->getPath());
            $pb->advance();

            try {
                $lookupArrays = [];
                if ($scannerConfig['use-size']) {
                    $lookupArrays[] = $referenceIndex['by-size'][$file->getSize()] ?? [];
                }
                if ($scannerConfig['use-mtime']) {
                    $lookupArrays[] = $referenceIndex['by-mtime'][$file->getMtime()] ?? [];
                }
                if ($scannerConfig['use-checksum']) {
                    foreach ($file->getChecksums() as $checksum) {
                        $lookupArrays[] = $referenceIndex['by-checksum'][$this->getChecksumKey($checksum)] ?? [];
                    }
                }
                if ($scannerConfig['use-filename']) {
                    $lookupArrays[] = $referenceIndex['by-filename'][basename($file->getPath())] ?? [];
                }

                /** @var File[] $matchingFiles */
                $matchingFiles = count($lookupArrays) > 1
                    ? array_intersect_key(...$lookupArrays)
                    : current($lookupArrays);

                if (count($matchingFiles) > 1) {
                    $messages = [
                        sprintf(
                            'Multiple matching files returned for %s, ignoring.',
                            $file->getPath()
                        )
                    ];
                    if ($io->isVerbose()) {
                        foreach ($matchingFiles as $matchingFile) {
                            $messages[] = sprintf(' * %s', $matchingFile->getPath());
                        }
                    }
                    $io->warning($messages);
                } elseif ($matchingFiles) {
                    $referenceFile = current($matchingFiles);
                    $matches[] = [
                        'reference' => [
                            'path'  => $referenceFile->getPath(),
                            'size'  => $referenceFile->getSize(),
                            'mtime' => $referenceFile->getMtime(),
                            'checksum-summary' => $this->fileChecksumHelper->getPrintableSummary(
                                $referenceFile->getChecksums()
                            ),
                        ],
                        'target' => [
                            'path'     => $file->getPath(),
                            'size'     => $file->getSize(),
                            'mtime'    => $file->getMtime(),
                            'checksum-summary' => $this->fileChecksumHelper->getPrintableSummary(
                                $file->getChecksums()
                            ),
                        ]
                    ];
                }
            } catch (\Throwable $e) {
                $io->warning("{$file->getPath()}: {$e->getMessage()}");
            }
        }
        $pb->setMessage('Finished.');
        $pb->finish();
        $io->newLine();

        return $matches;
    }

    /**
     * @param array $options
     * @return array
     */
    public function getScannerConfig(array $options = []) {
        return [
            'include'              => $options['scanner-config']['include']              ?? [],
            'use-size'             => $options['scanner-config']['use-size']             ?? true,
            'use-mtime'            => $options['scanner-config']['use-mtime']            ?? true,
            'use-checksum'         => $options['scanner-config']['use-checksum']         ?? true,
            'use-filename'         => $options['scanner-config']['use-filename']         ?? false,
            'beginning-chunk-size' => $options['scanner-config']['beginning-chunk-size'] ?? self::CHECKSUM_DEFAULT_CHUNK_SIZE,
            'beginning-chunk-algo' => $options['scanner-config']['beginning-chunk-algo'] ?? self::CHECKSUM_DEFAULT_ALGO,
        ];
    }

    protected function getChecksumKey(array $checksumData) {
        return "{$checksumData['start']}-{$checksumData['actual-length']}-{$checksumData['value']}";
    }
}
