<?php

namespace App\PhofmitBundle\Service;


use App\PhofmitBundle\Model\File;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Mirror
{
    const VERSION                     = '1.0';
    const CHECKSUM_DEFAULT_CHUNK_SIZE = 512 * 1024;
    const CHECKSUM_DEFAULT_ALGO       = 'sha1';

    /**
     * @param string $path
     * @param array $options
     * @return array
     */
    public function snapshot(
        string $path,
        OutputInterface $output,
        array $options = []
    ): array {
        $scannerConfig = $this->getScannerConfig($options);

        $finder = new Finder();
        $finder->files()
            ->in($path);

        if ($options['ignore-unreadable'] ?? true) {
            $finder->ignoreUnreadableDirs();
        }
        if ($options['follow-links'] ?? true) {
            $finder->followLinks();
        }
        if ($options['depth'] ?? false) {
            $finder->depth($options['depth']);
        }

        $pb = new ProgressBar($output, 0, 0.5);
        $pb->setFormat('%current% [%bar%] %message%');
        $pb->setMessage('Starting...');
        $pb->start();

        $splFiles = [];
        foreach ($finder as $splFile) {
            $pb->setMessage("🖹 {$splFile->getRelativePathname()}");
            $splFiles[] = $splFile;
            $pb->advance();
        }

        $files = [];
        $pb->setMaxSteps(count($splFiles));
        $pb->setFormat('%current%/%max% [%bar%] %message%');
        $pb->start();
        foreach ($splFiles as $splFile) {
            $pb->setMessage("🔬 {$splFile->getRelativePathname()}");
            $files[] = $this->scanFile($splFile, $scannerConfig)->toArray();
            $pb->advance();
        }
        $pb->setMessage(sprintf('<info>🛈 %d file(s) have been analyzed.', count($files)));
        $pb->finish();
        $output->writeln('');

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
     * @param SplFileInfo $fileInfo
     * @param array $scannerConfig
     * @return File
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
    public function diffSnapshots(array $reference, array $target, array $scannerConfig) {
        $matches = [];

        $referenceIndex = [];
        foreach ($reference['files'] as $fileData) {
            $file = File::fromArray($fileData);

            if ($scannerConfig['use-size']) {
                $referenceIndex['by-size'][$file->getSize()][] = $file;
            }
            if ($scannerConfig['use-mtime']) {
                $referenceIndex['by-mtime'][$file->getMtime()][] = $file;
            }
            if ($scannerConfig['use-checksum']) {
                foreach ($file->getChecksums() as $checksum) {
                    $referenceIndex['by-checksum'][$checksum['start']][] = $file;
                }
            }
        }

        foreach ($target['files'] as $fileData) {
            $file = File::fromArray($fileData);

            $lookupArrays = [];
            if ($scannerConfig['use-size']) {
                $lookupArrays[] = $referenceIndex['by-size'][$file->getSize()] ?? [];
            }
            if ($scannerConfig['use-mtime']) {
                $lookupArrays[] = $referenceIndex['by-mtime'][$file->getMtime()] ?? [];
            }
            if ($scannerConfig['use-checksum']) {
                foreach ($file->getChecksums() as $checksum) {
                    $lookupArrays[] = $referenceIndex['by-checksum'][$checksum['start']] ?? [];
                }
            }

            /** @var File[] $matchingFiles */
            $uintersectArgs = array_merge($lookupArrays, [function($a, $b) {
                return strcmp(spl_object_hash($a), spl_object_hash($b));
            }]);
            $matchingFiles = call_user_func_array('array_uintersect', $uintersectArgs);

            if (count($matchingFiles) > 1) {
                // TODO Report ignored file
            } elseif ($matchingFiles) {
                $referenceFile = current($matchingFiles);
                $matches[] = [
                    'reference' => [
                        'path'  => $referenceFile->getPath(),
                        'size'  => $referenceFile->getSize(),
                        'mtime' => $referenceFile->getMtime(),
                        'checksum-summary' => implode(',', array_map(function(array $checksum) {
                            return sprintf(
                                '[%s:%d:%d:%s]',
                                $checksum['algo'],
                                $checksum['start'],
                                $checksum['actual-length'],
                                $checksum['value']
                            );
                        }, $referenceFile->getChecksums())),
                    ],
                    'target' => [
                        'path'     => $file->getPath(),
                        'size'     => $file->getSize(),
                        'mtime'    => $file->getMtime(),
                        'checksum-summary' => implode(',', array_map(function(array $checksum) {
                            return sprintf(
                                '[%s:%d:%d:%s]',
                                $checksum['algo'],
                                $checksum['start'],
                                $checksum['actual-length'],
                                $checksum['value']
                            );
                        }, $file->getChecksums())),
                    ]
                ];
            }
        }

        return $matches;
    }

    /**
     * @param array $options
     * @return array
     */
    public function getScannerConfig(array $options = []) {
        return [
            'use-size'             => $options['scanner-config']['use-size']             ?? true,
            'use-mtime'            => $options['scanner-config']['use-mtime']            ?? true,
            'use-checksum'         => $options['scanner-config']['use-checksum']         ?? true,
            'beginning-chunk-size' => $options['scanner-config']['beginning-chunk-size'] ?? self::CHECKSUM_DEFAULT_CHUNK_SIZE,
            'beginning-chunk-algo' => $options['scanner-config']['beginning-chunk-algo'] ?? self::CHECKSUM_DEFAULT_ALGO,
        ];
    }
}
