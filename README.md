Phofmit: PHP Offline Folder Mirroring Tool
===

This tool aims to ease folders synchronization on different hosts when
most files are already present on both filesystems but their respective
locations differ.

**Example:**

Host 1 (_reference_) | Host 2 (_target_) | Expected action
-|-|-
`file1.txt` | `file1.txt` | (none)
`subfolder/file2.txt` | `file2.txt` | Move to `subfolder/`
`file3.txt` | `subfolder2/file3.txt` | Move to root folder

Comparison to find matching pair of files is performed depending on given
options on:
- size
- modification time
- checksum

## Requirements

- PHP 7.2+ or Docker

## Usage (local)

```
composer install

# See command details below
bin/console phofmit:snapshot ...
bin/console phofmit:mirror ...
```

## Usage (from Docker)

> Using [`thecodingmachine/php:7.3-v3-cli`](https://github.com/thecodingmachine/docker-images-php)
> as base image, if PHP is not available on local host.

Here to mount `/my/reference/folder` and `/my/target/folder` and be able to
analyze them (you might want to mount more folders as needed):

```
docker run -it --rm \
    -v $(pwd):/usr/src/app \
    -v /tmp:/mnt/tmp \
    -v /my/reference/folder:/mnt/reference \
    -v /my/target/folder:/mnt/target \
    thecodingmachine/php:7.3-v3-cli \
    /bin/bash

composer install

# See command details below
bin/console phofmit:snapshot phofmit:snapshot [options] [--] /my/reference/folder
bin/console phofmit:mirror [options] [--] <snapshot-filename> /my/target/folder
```

### Command `phofmit:snapshot`

```
$ bin/console phofmit:snapshot -h
Description:
  [Phofmit] Scan folder and create snapshot file

Usage:
  phofmit:snapshot [options] [--] <path>

Arguments:
  path                                       Path of target folder

Options:
  -f, --snapshot-filename=SNAPSHOT-FILENAME  Filename of the generated snapshot file. Use {now} to inject current date/time. [default: "{hostname}-{path}-{now}.phofmit.json"]
  -h, --help                                 Display this help message
  -q, --quiet                                Do not output any message
  -V, --version                              Display this application version
      --ansi                                 Force ANSI output
      --no-ansi                              Disable ANSI output
  -n, --no-interaction                       Do not ask any interactive question
  -e, --env=ENV                              The Environment name. [default: "prod"]
      --no-debug                             Switches off debug mode.
  -v|vv|vvv, --verbose                       Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

### Command `phofmit:mirror`

Execute mirroring between the _reference snapshot_ and a _target folder_.
It basically consists in comparing files location and moving them (when they exist)
to match the reference structure described in the snapshot, creating intermediate
folders as needed.

```
$ bin/console phofmit:mirror -h
Description:
  [Phofmit] Use specified snapshot file to mirror files location

Usage:
  phofmit:mirror [options] [--] <snapshot-filename> [<path>]

Arguments:
  snapshot-filename                      Snapshot file created with phofmit:snapshot
  path                                   Path of target folder

Options:
  -N, --dry-run                          Do not apply changes, only show what would be done.
  -s, --shell                            Print shell commands instead of doing the actual moving.
  -t, --target-snapshot=TARGET-SNAPSHOT  Use given target snapshot file instead of scanning target folder.
  -o, --option=OPTION                    Set scanner options. (multiple values allowed)
  -d, --dir-mode=DIR-MODE                Mode for directories created when mirroring. [default: 511]
  -h, --help                             Display this help message
  -q, --quiet                            Do not output any message
  -V, --version                          Display this application version
      --ansi                             Force ANSI output
      --no-ansi                          Disable ANSI output
  -n, --no-interaction                   Do not ask any interactive question
  -e, --env=ENV                          The Environment name. [default: "prod"]
      --no-debug                         Switches off debug mode.
  -v|vv|vvv, --verbose                   Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

You can use `--dry-run` to only show what _could_ be done, and also `--shell`
to output `mv` and `mkdir` shell commands that you can run later.

In this latter case, you may want to pipe STDOUT in order to process them separately
from the information log that is printed (on STDERR).

Example:

```
$ bin/console phofmit:mirror --shell reference.phofmit.json /my/target/dir > run_mirroring.sh
🛈 Using shell output.
🛈 Using snapshot file at reference.phofmit.json.
🛈 Target folder is at /my/target/dir...
5/5 [============================] 🔬 somefile.ext
⏳ Searching for matching files with reference snapshot (this might take a while)...
3 [============================] 🛈 3 target file(s) found with matching reference:

✔ Finished in 00:00:00

$ cat run_mirroring.sh
mkdir -p '/my/target/dir/subfolder' ;
mv 'somefile.ext' '/my/target/dir/subfolder/somefile.ext' ;
```
