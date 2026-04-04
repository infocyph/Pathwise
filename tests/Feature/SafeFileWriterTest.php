<?php

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
    $writer->character('A');

    expect(file_get_contents($this->tempFilePath))
        ->toBe('A')
        ->and($writer->count())->toBe(1);
});

test('it appends lines to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath, true);
    $writer->line('Hello');
    $writer->line('World');

    $fileContent = file_get_contents($this->tempFilePath);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $fileContent);

    expect($normalizedContent)
        ->toBe("Hello\nWorld\n")
        ->and($writer->count())->toBe(2);
});


test('it writes CSV data to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->csv(['Name', 'Age']);
    $writer->csv(['John', 30]);

    $content = file_get_contents($this->tempFilePath);
    expect($content)->toBe("Name,Age\nJohn,30\n");
});

test('it writes binary data to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->binary('BinaryData');

    expect(file_get_contents($this->tempFilePath))->toBe('BinaryData');
});

test('it writes JSON data with pretty print', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->json(['key' => 'value'], true);

    $fileContent = file_get_contents($this->tempFilePath);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $fileContent);

    $expectedJson = "{\n    \"key\": \"value\"\n}\n";
    expect($normalizedContent)->toBe($expectedJson);
});


test('it writes XML data to the file', function () {
    $xml = new SimpleXMLElement('<root><item>Value</item></root>');
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->xml($xml);

    expect(file_get_contents($this->tempFilePath))->toContain('<root><item>Value</item></root>');
});

test('it writes serialized data to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->serialized(['key' => 'value']);
    $writer->serialized(['another' => 'entry']);

    // Deserialize all lines
    $lines = file($this->tempFilePath, FILE_IGNORE_NEW_LINES);
    $content = array_map(fn($line) => unserialize($line), $lines);
    expect($content)->toBe([
        ['key' => 'value'],
        ['another' => 'entry'],
    ]);
});


test('it writes a JSON array to the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->jsonArray([['key' => 'value']]);

    $content = json_decode(file_get_contents($this->tempFilePath), true);
    expect($content)->toBe([['key' => 'value']]);
});

test('it throws an exception if file cannot be written', function () {
    $invalidPath = '/invalid_path/test_file.txt';
    $writer = new SafeFileWriter($invalidPath);

    expect(fn () => $writer->line('test'))->toThrow(FileAccessException::class);
});

test('it locks and unlocks the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->lock();
    $writer->line('Locked Content');
    $writer->unlock();

    $fileContent = file_get_contents($this->tempFilePath);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", $fileContent);

    expect($normalizedContent)->toBe("Locked Content\n");
});


test('it counts total write operations', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->line('Line 1');
    $writer->line('Line 2');
    $writer->csv(['Name', 'Age']);
    $writer->json(['key' => 'value']);

    expect($writer->count())->toBe(4);
});

test('it flushes and truncates the file', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->line('Data before flush');
    $writer->flush();
    $writer->truncate();

    expect(file_get_contents($this->tempFilePath))->toBe('');
});

test('it returns file size and modification date', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->line('Size Test');

    expect($writer->getSize())
        ->toBeGreaterThan(0)
        ->and($writer->getModificationDate())->toBeInstanceOf(DateTime::class);
});

test('it converts to string and JSON serializes', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $writer->line('Test for JSON');

    expect((string)$writer)
        ->toContain($this->tempFilePath)
        ->and(json_encode($writer))->toContain('"filename"');
});

test('it supports atomic write mode', function () {
    file_put_contents($this->tempFilePath, 'before');

    $writer = (new SafeFileWriter($this->tempFilePath))
        ->enableAtomicWrite();

    $writer->line('after');
    expect(file_get_contents($this->tempFilePath))->toBe('before');

    $writer->close();
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", file_get_contents($this->tempFilePath));
    expect($normalizedContent)->toBe("after\n");
});

test('it verifies checksum after writing', function () {
    $writer = new SafeFileWriter($this->tempFilePath);
    $ok = $writer->writeAndVerify('checksum-content');

    expect($ok)->toBeTrue()
        ->and($writer->verifyChecksum(hash('sha256', 'checksum-content')))->toBeTrue();
});

test('it writes mounted files through local staging and sync', function () {
    $writer = new SafeFileWriter('writer://remote.txt');
    $writer->line('hello');
    $writer->close();

    $normalizedContent = str_replace(["\r\n", "\r"], "\n", FlysystemHelper::read('writer://remote.txt'));

    expect($normalizedContent)->toBe("hello\n");
});
