<?php

use Infocyph\Pathwise\Exceptions\FileAccessException;
use Infocyph\Pathwise\FileManager\SafeFileReader;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

beforeEach(function () {
    $this->tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_file_', true) . '.txt';
    file_put_contents($this->tempFilePath, "Hello\nWorld\nJSON\n{\"key\": \"value\"}\n<b>XML</b>\n");
    $this->mountRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('reader_mount_', true);
    mkdir($this->mountRoot, 0755, true);
    FlysystemHelper::mount('reader', new Filesystem(new LocalFilesystemAdapter($this->mountRoot)));
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

test('it reads file line by line', function () {
    $reader = new SafeFileReader($this->tempFilePath);
    $lines = array_filter(array_map('trim', iterator_to_array($reader->line(), false)), fn($line) => $line !== '');
    expect($lines)->toBe(['Hello', 'World', 'JSON', '{"key": "value"}', '<b>XML</b>']);
});

test('it reads file character by character', function () {
    $reader = new SafeFileReader($this->tempFilePath);
    $chars = iterator_to_array($reader->character(), false);
    expect(implode('', $chars))->toBe("Hello\nWorld\nJSON\n{\"key\": \"value\"}\n<b>XML</b>\n");
});

test('it reads file in binary chunks', function () {
    $reader = new SafeFileReader($this->tempFilePath);
    $chunks = iterator_to_array($reader->binary(5), false);
    $reconstructedContent = trim(implode('', $chunks));
    $expectedContent = "Hello\nWorld\nJSON\n{\"key\": \"value\"}\n<b>XML</b>";
    expect($reconstructedContent)->toBe($expectedContent);
});

test('it reads CSV data', function () {
    file_put_contents($this->tempFilePath, "name,age\nJohn,25\nDoe,30");
    $reader = new SafeFileReader($this->tempFilePath);
    $csvRows = iterator_to_array($reader->csv(','), false);
    expect($csvRows)->toBe([['name', 'age'], ['John', '25'], ['Doe', '30']]);
});

test('it handles JSON line-by-line decoding', function () {
    file_put_contents($this->tempFilePath, "{\"key\":\"value\"}\n{\"key2\":\"value2\"}");
    $reader = new SafeFileReader($this->tempFilePath);
    $jsonLines = iterator_to_array($reader->json(), false);
    expect($jsonLines)->toBe([['key' => 'value'], ['key2' => 'value2']]);
});

test('it throws exception on invalid JSON decoding', function () {
    file_put_contents($this->tempFilePath, "Invalid JSON\n{\"key\":\"value\"}");
    $reader = new SafeFileReader($this->tempFilePath);
    expect(fn() => iterator_to_array($reader->json(), false))->toThrow(Exception::class);
});

test('it applies and releases lock on file', function () {
    $reader = new SafeFileReader($this->tempFilePath, 'r', true);
    expect($reader)->toBeInstanceOf(SafeFileReader::class);
    $reader->releaseLock();
    expect(true)->toBeTrue(); // Just verifies the lock was applied and released without issue
});

test('it reads XML elements', function () {
    file_put_contents($this->tempFilePath, '<root><item>Value1</item><item>Value2</item></root>');
    $reader = new SafeFileReader($this->tempFilePath);

    $elements = iterator_to_array($reader->xml('item'), false);

    expect($elements)
        ->toHaveCount(2)
        ->and($elements[0])->toBeInstanceOf(SimpleXMLElement::class)
        ->and((string) $elements[0])->toBe('Value1')
        ->and((string) $elements[1])->toBe('Value2');
});

test('it reads serialized data', function () {
    $data = serialize(['key' => 'value']);
    file_put_contents($this->tempFilePath, $data . "\n" . $data);
    $reader = new SafeFileReader($this->tempFilePath);
    $serializedObjects = iterator_to_array($reader->serialized(), false);
    expect($serializedObjects)->toBe([['key' => 'value'], ['key' => 'value']]);
});

test('it reads fixed-width data', function () {
    file_put_contents($this->tempFilePath, '1234JohnDoe    ');
    $reader = new SafeFileReader($this->tempFilePath);
    $fields = iterator_to_array($reader->fixedWidth([4, 4, 8]), false);
    expect($fields[0])->toBe(['1234', 'John', 'Doe    ']);
});

test('it throws exception if file is not accessible', function () {
    $invalidFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('invalid_file_', true) . '.txt';
    $reader = new SafeFileReader($invalidFilePath);
    expect(fn() => $reader->line())->toThrow(FileAccessException::class);
});

test('it counts lines correctly', function () {
    $reader = new SafeFileReader($this->tempFilePath);
    iterator_to_array($reader->line());
    expect($reader->count())->toBe(5);
});

test('it resets and seeks to a position in lines', function () {
    $reader = new SafeFileReader($this->tempFilePath);
    $reader->seek(2);
    $lines = iterator_to_array($reader->line(), false);
    expect($lines[0])->toBe("JSON\n");
});

test('it reads mounted files through local staging', function () {
    FlysystemHelper::write('reader://remote.txt', "A\nB\n");
    $reader = new SafeFileReader('reader://remote.txt');
    $lines = array_map('trim', iterator_to_array($reader->line(), false));

    expect($lines)->toBe(['A', 'B']);
});
