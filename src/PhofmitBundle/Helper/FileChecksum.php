<?php

namespace App\PhofmitBundle\Helper;


class FileChecksum
{
    /**
     * @param array $fileChecksums
     * @return string
     */
    public function getPrintableSummary(array $fileChecksums) {
        return implode(',', array_map(function(array $checksum) {
            return sprintf(
                '[%s:%d:%d:%s]',
                $checksum['algo'],
                $checksum['start'],
                $checksum['actual-length'],
                $checksum['value']
            );
        }, $fileChecksums));
    }
}
