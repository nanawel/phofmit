<?php

namespace App\PhofmitBundle\Service\FileMover;


interface FileMoverInterface
{
    /**
     * @param string $targetFileCurrentPath
     * @param string $targetFileNewPath
     * @param array $match
     * @return bool
     */
    public function moveFile(
        string $targetFileCurrentPath,
        string $targetFileNewPath,
        array $match
    ): bool;
}
