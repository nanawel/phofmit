<?php

namespace App\PhofmitBundle\Model;


class File
{
    /** @var string */
    protected $absolutePath;

    /** @var string */
    protected $path;

    /** @var int */
    protected $size;

    /** @var int */
    protected $mtime;

    /** @var array */
    protected $checksums = [];

    /**
     * @param array $data
     * @return File
     */
    public static function fromArray(array $data): File {
        $file = new self();
        foreach ($data as $k => $v) {
            $file->{$k} = $v;
        }

        return $file;
    }

    /**
     * @return string
     */
    public function getAbsolutePath(): ?string {
        return $this->absolutePath;
    }

    /**
     * @param string $absolutePath
     * @return File
     */
    public function setAbsolutePath(string $absolutePath): File {
        $this->absolutePath = $absolutePath;

        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string {
        return $this->path;
    }

    /**
     * @param string $path
     * @return File
     */
    public function setPath(string $path): File {
        $this->path = $path;

        return $this;
    }

    /**
     * @return int
     */
    public function getSize(): ?int {
        return $this->size;
    }

    /**
     * @param int $size
     * @return File
     */
    public function setSize(int $size): File {
        $this->size = $size;

        return $this;
    }

    /**
     * @return int
     */
    public function getMtime(): ?int {
        return $this->mtime;
    }

    /**
     * @param int $mtime
     * @return File
     */
    public function setMtime(int $mtime): File {
        $this->mtime = $mtime;

        return $this;
    }

    /**
     * @return array
     */
    public function getChecksums(): array {
        return $this->checksums;
    }

    /**
     * @param array $checksums
     * @return File
     */
    public function setChecksums(array $checksums): File {
        $this->checksums = $checksums;

        return $this;
    }

    /**
     * @return array
     */
    public function toArray(): array {
        return get_object_vars($this);
    }
}
