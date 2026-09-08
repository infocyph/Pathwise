<?php

declare(strict_types=1);

namespace Infocyph\Pathwise\Security;

use Infocyph\Pathwise\Exceptions\CompressionException;
use Infocyph\Pathwise\Exceptions\UnsafeArchiveEntryException;
use Infocyph\Pathwise\FileManager\FileTransactionJournal;
use Infocyph\Pathwise\Utils\PathHelper;
use ZipArchive;

/** @internal */
final class ZipArchiveExtractor
{
    private const int COPY_BUFFER_BYTES = 65_536;

    /**
     * @param array<int, ZipArchiveManifestEntry> $entries
     */
    public static function extractToLocal(ZipArchive $archive, array $entries, string $root): void
    {
        $root = PathHelper::normalize($root);
        if (PathHelper::hasScheme($root)) {
            throw new CompressionException('Secure ZIP extraction requires a local staging directory.');
        }

        $rootExisted = is_dir($root);
        if (is_link($root)) {
            throw new UnsafeArchiveEntryException('ZIP extraction root must not be a symbolic link.');
        }
        if (!$rootExisted && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new CompressionException("Unable to create ZIP extraction root: {$root}");
        }

        $journal = new FileTransactionJournal($root);
        $createdDirectories = [];

        try {
            foreach ($entries as $entry) {
                self::extractEntry($archive, $entry, $root, $journal, $createdDirectories);
            }

            $journal->commit();
        } catch (\Throwable $exception) {
            try {
                $journal->rollback($exception);
            } finally {
                self::cleanupCreatedDirectories($createdDirectories, $root, $rootExisted);
            }

            throw $exception;
        }
    }

    /**
     * @param list<string> $createdDirectories
     */
    private static function cleanupCreatedDirectories(array $createdDirectories, string $root, bool $rootExisted): void
    {
        for ($index = count($createdDirectories) - 1; $index >= 0; $index--) {
            $directory = $createdDirectories[$index];
            if (is_dir($directory) && !is_link($directory)) {
                self::runSilently(static fn(): bool => rmdir($directory));
            }
        }

        if (!$rootExisted && is_dir($root) && !is_link($root)) {
            self::runSilently(static fn(): bool => rmdir($root));
        }
    }

    /**
     * @param resource $input
     * @param resource $output
     */
    private static function copyValidatedBytes(mixed $input, mixed $output, ZipArchiveManifestEntry $entry): void
    {
        $written = 0;
        while (!feof($input)) {
            $chunk = fread($input, self::COPY_BUFFER_BYTES);
            if ($chunk === false) {
                throw new CompressionException("Unable to read ZIP entry: {$entry->archiveName}");
            }
            if ($chunk === '') {
                break;
            }

            $length = strlen($chunk);
            if ($length > $entry->uncompressedBytes - $written) {
                throw new UnsafeArchiveEntryException(
                    "ZIP entry expanded beyond validated size: {$entry->archiveName}",
                );
            }

            self::writeChunk($output, $chunk, $entry->archiveName);
            $written += $length;
        }

        if ($written !== $entry->uncompressedBytes) {
            throw new UnsafeArchiveEntryException(
                "ZIP entry expanded size does not match validated metadata: {$entry->archiveName}",
            );
        }
    }

    /**
     * @param list<string> $createdDirectories
     */
    private static function ensureDirectory(
        string $root,
        string $relative,
        string $entry,
        array &$createdDirectories,
    ): void {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return;
        }

        $current = rtrim($root, '/\\');
        foreach (explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new UnsafeArchiveEntryException("ZIP destination traverses a symbolic link: {$entry}");
            }
            if (file_exists($current) && !is_dir($current)) {
                throw new UnsafeArchiveEntryException("ZIP directory conflicts with an existing file: {$entry}");
            }
            if (is_dir($current)) {
                continue;
            }
            if (!mkdir($current, 0755) && !is_dir($current)) {
                throw new CompressionException("Unable to create ZIP extraction directory for: {$entry}");
            }

            $createdDirectories[] = $current;
        }
    }

    /**
     * @param list<string> $createdDirectories
     */
    private static function extractEntry(
        ZipArchive $archive,
        ZipArchiveManifestEntry $entry,
        string $root,
        FileTransactionJournal $journal,
        array &$createdDirectories,
    ): void {
        $validatedPath = ZipEntryValidator::validate($entry->path, $root);
        $relative = rtrim($validatedPath, '/');
        $target = PathHelper::join($root, $relative);

        if ($entry->directory) {
            if (file_exists($target) && !is_dir($target)) {
                throw new UnsafeArchiveEntryException("ZIP directory conflicts with an existing file: {$entry->archiveName}");
            }

            self::ensureDirectory($root, $relative, $entry->archiveName, $createdDirectories);

            return;
        }

        self::ensureDirectory($root, dirname(str_replace('\\', '/', $relative)), $entry->archiveName, $createdDirectories);
        ZipEntryValidator::validate($entry->path, $root);
        if (is_link($target) || is_dir($target)) {
            throw new UnsafeArchiveEntryException("ZIP file target is not a regular file path: {$entry->archiveName}");
        }

        $journal->record($target);
        $temporary = self::temporarySibling($target);

        try {
            self::writeEntry($archive, $entry, $temporary);
            ZipEntryValidator::validate($entry->path, $root);
            if (is_dir($target)) {
                throw new UnsafeArchiveEntryException("ZIP file target changed during extraction: {$entry->archiveName}");
            }

            self::replaceTarget($temporary, $target, $entry->archiveName);
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                self::runSilently(static fn(): bool => unlink($temporary));
            }
        }
    }

    private static function removeExistingTarget(string $target, string $entry): void
    {
        self::runSilently(static fn(): bool => unlink($target));
        clearstatcache(true, $target);
        if (file_exists($target) || is_link($target)) {
            throw new CompressionException("Unable to replace extracted ZIP entry: {$entry}");
        }
    }

    private static function replaceTarget(string $temporary, string $target, string $entry): void
    {
        if (self::runSilently(static fn(): bool => rename($temporary, $target))) {
            return;
        }

        if (!is_file($target)) {
            throw new CompressionException("Unable to publish extracted ZIP entry: {$entry}");
        }

        self::removeExistingTarget($target, $entry);
        if (self::runSilently(static fn(): bool => rename($temporary, $target))) {
            return;
        }

        throw new CompressionException("Unable to publish extracted ZIP entry: {$entry}");
    }

    private static function runSilently(callable $operation): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /** @param resource $output */
    private static function synchronizeOutput(mixed $output, string $entry): void
    {
        if (!fflush($output)) {
            throw new CompressionException("Unable to flush ZIP entry: {$entry}");
        }
        if (function_exists('fsync') && !fsync($output)) {
            throw new CompressionException("Unable to synchronize ZIP entry: {$entry}");
        }
    }

    private static function temporarySibling(string $target): string
    {
        $directory = dirname($target);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $directory . DIRECTORY_SEPARATOR . '.pathwise-zip-' . bin2hex(random_bytes(16)) . '.tmp';
            $handle = self::runSilently(static fn() => fopen($candidate, 'xb'));
            if (!is_resource($handle)) {
                continue;
            }

            fclose($handle);
            if (!self::runSilently(static fn(): bool => chmod($candidate, 0600))) {
                self::runSilently(static fn(): bool => unlink($candidate));

                throw new CompressionException('Unable to secure ZIP extraction staging file.');
            }

            return $candidate;
        }

        throw new CompressionException('Unable to allocate ZIP extraction staging file.');
    }

    /** @param resource $output */
    private static function writeChunk(mixed $output, string $chunk, string $entry): void
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $bytes = fwrite($output, substr($chunk, $offset));
            if (!is_int($bytes) || $bytes < 1) {
                throw new CompressionException("Unable to write ZIP entry: {$entry}");
            }

            $offset += $bytes;
        }
    }

    private static function writeEntry(ZipArchive $archive, ZipArchiveManifestEntry $entry, string $temporary): void
    {
        $input = $archive->getStream($entry->archiveName);
        $output = self::runSilently(static fn() => fopen($temporary, 'wb'));
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            throw new CompressionException("Unable to extract ZIP entry: {$entry->archiveName}");
        }

        try {
            self::copyValidatedBytes($input, $output, $entry);
            self::synchronizeOutput($output, $entry->archiveName);
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
