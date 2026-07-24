<?php

declare(strict_types=1);

/** Vai var veidot ZIP (ZipArchive vai iebūvētais rakstītājs). */
function efpic_zip_supported(): bool
{
    return true;
}

/**
 * @param callable(callable(string, string): void): void $populate
 */
function efpic_zip_build_file(string $zipPath, callable $populate, ?int &$entryCount = null): bool
{
    $count = 0;
    $trackAdd = static function (callable $add) use (&$count): callable {
        return static function (string $name, string $data) use ($add, &$count): void {
            $add($name, $data);
            $count++;
        };
    };

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $add = static function (string $name, string $data) use ($zip): void {
            $zip->addFromString(str_replace('\\', '/', $name), $data);
        };
        $populate($trackAdd($add));
        $zip->close();
        if ($entryCount !== null) {
            $entryCount = $count;
        }

        return $count > 0 && is_file($zipPath) && filesize($zipPath) > 0;
    }

    $writer = new EfpicPureZipWriter($zipPath);
    $add = static function (string $name, string $data) use ($writer): void {
        $writer->addFromString($name, $data);
    };
    $populate($trackAdd($add));
    if ($entryCount !== null) {
        $entryCount = $count;
    }

    return $count > 0 && $writer->finish();
}

function efpic_zip_num_files(string $zipPath): int
{
    if (!is_file($zipPath)) {
        return 0;
    }
    if (!class_exists('ZipArchive')) {
        $size = filesize($zipPath);

        return ($size !== false && $size > 100) ? 1 : 0;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        return 0;
    }
    $count = (int) $zip->numFiles;
    $zip->close();

    return $count;
}

/** Vai fails izskatās pēc ZIP (PK…), arī ZIP64, ko vecāks ZipArchive dažreiz neatver. */
function efpic_zip_looks_valid(string $zipPath, int $minBytes = 64): bool
{
    if (!is_file($zipPath)) {
        return false;
    }
    $size = filesize($zipPath);
    if ($size === false || $size < $minBytes) {
        return false;
    }
    $fh = @fopen($zipPath, 'rb');
    if ($fh === false) {
        return false;
    }
    $magic = (string) fread($fh, 4);
    fclose($fh);

    return $magic === "PK\x03\x04"
        || $magic === "PK\x05\x06"
        || $magic === "PK\x07\x08";
}

function efpic_zip_verify_file(string $zipPath, int $minEntries = 1): bool
{
    if (!efpic_zip_looks_valid($zipPath)) {
        return false;
    }
    if (!class_exists('ZipArchive')) {
        return true;
    }
    $count = efpic_zip_num_files($zipPath);
    // ZIP64 / bojāts ZipArchive open → numFiles=0, bet fails var būt derīgs.
    if ($count <= 0) {
        return efpic_zip_looks_valid($zipPath, 1024);
    }

    return $count >= $minEntries;
}

final class EfpicPureZipWriter
{
    /** @var resource|null */
    private $fp;

    /** @var list<array{name: string, crc: int, size: int, offset: int}> */
    private array $central = [];

    private int $offset = 0;

    public function __construct(string $path)
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Nevar izveidot ZIP failu');
        }
        $this->fp = $fp;
    }

    public function addFromString(string $name, string $data): void
    {
        if ($this->fp === null) {
            return;
        }
        $name = str_replace('\\', '/', $name);
        $size = strlen($data);
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 0x100000000;
        }

        $localOffset = $this->offset;
        $header = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0
        ) . $name;

        fwrite($this->fp, $header);
        fwrite($this->fp, $data);
        $this->offset += strlen($header) + $size;
        $this->central[] = [
            'name' => $name,
            'crc' => $crc,
            'size' => $size,
            'offset' => $localOffset,
        ];
    }

    public function finish(): bool
    {
        if ($this->fp === null) {
            return false;
        }
        if ($this->central === []) {
            fclose($this->fp);
            $this->fp = null;

            return false;
        }

        $centralStart = $this->offset;
        foreach ($this->central as $entry) {
            $name = $entry['name'];
            $header = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $entry['offset']
            ) . $name;
            fwrite($this->fp, $header);
            $this->offset += strlen($header);
        }

        $centralSize = $this->offset - $centralStart;
        $count = count($this->central);
        $footer = pack('VVvvvvVVV', 0x06054b50, 0, 0, $count, $count, $centralSize, $centralStart, 0, 0);
        fwrite($this->fp, $footer);
        fclose($this->fp);
        $this->fp = null;

        return true;
    }
}
