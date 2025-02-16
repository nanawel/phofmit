<?php

namespace App\PhofmitBundle\Service\FileMover;


interface FileMoverInterface
{
    public function moveFile(
        string $targetFileCurrentPath,
        string $targetFileNewPath,
        array $match
    ): bool;
}
