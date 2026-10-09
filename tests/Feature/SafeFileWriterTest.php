<?php

declare(strict_types=1);

use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\FileManager\SafeFileWriter;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->tempFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('test_file_', true).'.txt';
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('writer_mount_', true);
    mkdir($this->mountRoot, 0755, true);
    FlysystemHelper::mount('writer', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
});

afterEach(function () {
    FlysystemHelper::reset();

    if (file_exists($this->tempFilePath)) {
        unlink($this->tempFilePath);
    }

    if (is_dir($this->mountRoot)) {
        foreach (glob($this->mountRoot . DIRECTORY_SEPARATOR . '*') as $item) {
            if (is_file($item)) {
                unlink($item);
            }
        }
        rmdir($this->mountRoot);
    }
});

test('it creates a file and writes a single character', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeCharacters('A');

    expect(file_get_contents($this->tempFilePath))
        ->toBe('A')
        ->and($writer->count())->toBe(1);
});

test('it appends lines to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath, true);
    $writer->writeLine('Hello');
    $writer->writeLine('World');

    $fileContent = file_get_contents($this->tempFilePath);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $fileContent);

    expect($normalizedContent)
        ->toBe("Hello\nWorld\n")
        ->and($writer->count())->toBe(2);
});

test('it writes CSV data to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeCsv(['Name', 'Age']);
    $writer->writeCsv(['John', 30]);

    $content = file_get_contents($this->tempFilePath);
    expect($content)->toBe("Name,Age\nJohn,30\n");
});

test('it writes binary data to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeBinary('BinaryData');

    expect(file_get_contents($this->tempFilePath))->toBe('BinaryData');
});

test('it writes JSON data with pretty print', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeJson(['key' => 'value'], true);

    $fileContent = file_get_contents($this->tempFilePath);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $fileContent);

    $expectedJson = "{\n    \"key\": \"value\"\n}\n";
    expect($normalizedContent)->toBe($expectedJson);
});

test('it writes XML data to the file', function () {
    $xml = new SimpleXMLElement('<root><item>Value</item></root>');
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeXml($xml);

    expect(file_get_contents($this->tempFilePath))->toContain('<root><item>Value</item></root>');
});

test('it writes serialized data to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeSerialized(['key' => 'value']);
    $writer->writeSerialized(['another' => 'entry']);

    $lines = file($this->tempFilePath, FILE_IGNORE_NEW_LINES);
    $content = array_map(fn($line) => unserialize($line), $lines);
    expect($content)->toBe([
        ['key' => 'value'],
        ['another' => 'entry'],
    ]);
});

test('it writes a JSON array to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeJsonArray([['key' => 'value']]);

    $content = json_decode(file_get_contents($this->tempFilePath), true);
    expect($content)->toBe([['key' => 'value']]);
});

test('it throws an exception if file cannot be written', function () {
    $invalidPath = '/invalid_path/test_file.txt';
    $writer = new SafeFileWriter($invalidPath);

    expect(fn () => $writer->writeLine('test'))->toThrow(FileAccessException::class);
});

test('it locks and unlocks the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->lock();
    $writer->writeLine('Locked Content');
    $writer->unlock();

    $fileContent = file_get_contents($this->tempFilePath);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $fileContent);

    expect($normalizedContent)->toBe("Locked Content\n");
});

test('it counts total write operations', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeLine('Line 1');
    $writer->writeLine('Line 2');
    $writer->writeCsv(['Name', 'Age']);
    $writer->writeJson(['key' => 'value']);

    expect($writer->count())->toBe(4);
});

test('it flushes and truncates the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeLine('Data before flush');
    $writer->flush();
    $writer->truncate();

    expect(file_get_contents($this->tempFilePath))->toBe('');
});

test('it returns file size and modification date', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeLine('Size Test');

    expect($writer->getSize())
        ->toBeGreaterThan(0)
        ->and($writer->getModificationDate())->toBeInstanceOf(DateTime::class);
});

test('it converts to string and JSON serializes', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->writeLine('Test for JSON');

    expect((string)$writer)
        ->toContain($this->tempFilePath)
        ->and(json_encode($writer))->toContain('"filename"');
});

test('it supports atomic local replacement mode', function () {
    file_put_contents($this->tempFilePath, 'before');

    $writer = (new SafeFileWriter($this->tempFilePath))
        ->enableAtomicWrite();

    $writer->writeLine('after');
    expect(file_get_contents($this->tempFilePath))->toBe('before');

    $writer->close();
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", file_get_contents($this->tempFilePath));
    expect($normalizedContent)->toBe("after\n");
});

test('it rejects atomic mode for adapter-backed paths', function () {
    $writer = new SafeFileWriter('writer://remote.txt');

    expect(fn () => $writer->enableAtomicWrite())
        ->toThrow(FileAccessException::class, 'requires a direct-local filesystem path');
});

test('it verifies checksum after writing', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $result = $writer->writeAndVerify('checksum-content');

    expect($result)->toBe($writer)
        ->and($writer->verifyChecksum(hash('sha256', 'checksum-content')))->toBeTrue();
});

test('it writes mounted files through local staging and sync', function () {
    $writer = new SafeFileWriter('writer://remote.txt');
    $writer->writeLine('hello');
    $writer->close();

    $normalizedContent = str_replace(["\r\n", "\r"], "\n", FlysystemHelper::read('writer://remote.txt'));

    expect($normalizedContent)->toBe("hello\n");
});

test('matching-line writer returns zero for no match and rejects invalid regex', function () {
    $writer = new SafeFileWriter($this->tempFilePath);

    expect($writer->writeMatchingLine('hello', '/world/'))->toBe(0)
        ->and($writer->count())->toBe(0)
        ->and(fn () => $writer->writeMatchingLine('hello', '['))->toThrow(FileAccessException::class);
});

test('fixed-width and serialized writers reject unsafe values', function () {
    $writer = new SafeFileWriter($this->tempFilePath);

    expect(fn () => $writer->writeFixedWidth(['x'], [0]))->toThrow(FileAccessException::class)
        ->and(fn () => $writer->writeSerialized((object) ['x' => 1]))->toThrow(FileAccessException::class);
});

test('serialized validation uses a finite total work budget for shared reference graphs', function (): void {
    $parts = [['leaf']];
    for ($depth = 0; $depth < 25; $depth++) {
        $parent = count($parts) - 1;
        $parts[] = [&$parts[$parent], &$parts[$parent]];
    }
    $sharedGraph = $parts[array_key_last($parts)];
    $validator = \Infocyph\Pathwise\Utils\SerializedValueValidator::class;

    expect($validator::containsUnsupportedValue(['safe' => ['value' => 123]]))->toBeFalse()
        ->and($validator::containsUnsupportedValue($sharedGraph))->toBeTrue()
        ->and(fn () => (new SafeFileWriter($this->tempFilePath))->writeSerialized($sharedGraph))
        ->toThrow(FileAccessException::class, 'safe scalar and array types');
});

test('failed and timed lock attempts do not truncate an existing local file', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(PHP_OS_FAMILY)->toBe('Windows');

        return;
    }

    file_put_contents($this->tempFilePath, 'preserve-until-locked');
    $holder = fopen($this->tempFilePath, 'c+');
    if (!is_resource($holder)) {
        throw new RuntimeException('Unable to open lock fixture.');
    }

    try {
        expect(flock($holder, LOCK_EX | LOCK_NB))->toBeTrue();
        $writer = new SafeFileWriter($this->tempFilePath);
        expect(fn () => $writer->lock(LOCK_EX, true, 2, 5))
            ->toThrow(FileAccessException::class, 'Failed to acquire lock')
            ->and(file_get_contents($this->tempFilePath))->toBe('preserve-until-locked');

        flock($holder, LOCK_UN);
        $writer->lock();
        $writer->writeLine('committed-after-lock');
        $writer->close();

        expect(file_get_contents($this->tempFilePath))->toBe('committed-after-lock' . PHP_EOL);
    } finally {
        flock($holder, LOCK_UN);
        fclose($holder);
    }
});

test('serialized line framing rejects embedded line breaks without writing', function (): void {
    $writer = new SafeFileWriter($this->tempFilePath);
    expect(fn () => $writer->writeSerialized("one\ntwo"))
        ->toThrow(FileAccessException::class, 'line breaks')
        ->and($writer->count())->toBe(0);
    $writer->writeSerialized(['safe' => 'value']);
    $writer->close();
    $reader = new \Infocyph\Pathwise\FileManager\SafeFileReader($this->tempFilePath);
    expect(iterator_to_array($reader->serializedValues()))->toBe([['safe' => 'value']]);
});

test('reacquiring and changing a writer lock preserves initialized contents', function (): void {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->lock();
    $writer->writeBinary('preserved');
    $writer->flush();
    $writer->unlock();
    $writer->lock();
    expect(file_get_contents($this->tempFilePath))->toBe('preserved');
    $writer->lock(LOCK_SH);
    $writer->lock(LOCK_EX);
    expect(file_get_contents($this->tempFilePath))->toBe('preserved');
    $writer->close();
});

test('an exclusive upgrade acquires actual exclusive ownership', function (): void {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->lock(LOCK_SH);
    $writer->lock(LOCK_EX);
    $other = fopen($this->tempFilePath, 'rb');
    try {
        expect(flock($other, LOCK_SH | LOCK_NB))->toBeFalse();
    } finally {
        fclose($other);
        $writer->close();
    }
});
