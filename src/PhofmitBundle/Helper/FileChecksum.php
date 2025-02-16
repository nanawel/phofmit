<?php

namespace App\PhofmitBundle\Helper;


class FileChecksum
{
    public function getPrintableSummary(array $fileChecksums): string {
        return implode(',', array_map(fn(array $checksum): string => sprintf(
            '[%s:%d:%d:%s]',
            $checksum['algo'],
            $checksum['start'],
            $checksum['actual-length'],
            $checksum['value']
        ), $fileChecksums));
    }
}
