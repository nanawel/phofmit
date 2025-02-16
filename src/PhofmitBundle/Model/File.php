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

    public function setAbsolutePath(string $absolutePath): File {
        $this->absolutePath = $absolutePath;

        return $this;
    }

    public function getPath(): string {
        return $this->path;
    }

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

    public function setMtime(int $mtime): File {
        $this->mtime = $mtime;

        return $this;
    }

    public function getChecksums(): array {
        return $this->checksums;
    }

    public function setChecksums(array $checksums): File {
        $this->checksums = $checksums;

        return $this;
    }

    public function toArray(): array {
        return get_object_vars($this);
    }
}
