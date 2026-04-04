<?php
use Infocyph\Pathwise\Utils\PathHelper;

beforeEach(function () {
    $this->tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_file_', true) . '.txt';
    file_put_contents($this->tempFilePath, 'Test Content');
});

afterEach(function () {
    if (file_exists($this->tempFilePath)) {
        unlink($this->tempFilePath);
    }
});

// Test PathHelper::join
test('it joins multiple path segments', function () {
    expect(PathHelper::join('path', 'to', 'file.txt'))->toBe('path' . DIRECTORY_SEPARATOR . 'to' . DIRECTORY_SEPARATOR . 'file.txt');
});

test('it normalizes a path', function () {
    expect(PathHelper::normalize('path/./to/../file.txt'))->toBe('path' . DIRECTORY_SEPARATOR . 'file.txt');
});

test('it preserves root prefix when normalizing absolute paths', function () {
    $normalized = PathHelper::normalize('/var/www/html');
    expect(str_starts_with($normalized, DIRECTORY_SEPARATOR))->toBeTrue();
});


// Test PathHelper::isAbsolute
test('it checks if path is absolute', function () {
    $absolutePath = DIRECTORY_SEPARATOR === '/' ? '/absolute/path' : 'C:\\absolute\\path';
    expect(PathHelper::isAbsolute($absolutePath))->toBeTrue();

    $relativePath = 'relative/path';
    expect(PathHelper::isAbsolute($relativePath))->toBeFalse();
    expect(PathHelper::isAbsolute(''))->toBeFalse();
});

// Test PathHelper::toAbsolutePath
test('it converts a relative path to absolute', function () {
    $relativePath = 'relative/path';
    $expectedPath = PathHelper::normalize(getcwd() . DIRECTORY_SEPARATOR . $relativePath);
    expect(PathHelper::toAbsolutePath($relativePath))->toBe($expectedPath);
});

// Test PathHelper::isValidPath
test('it validates a path', function () {
    expect(PathHelper::isValidPath('valid/path'))->toBeTrue();
    expect(PathHelper::isValidPath('invalid<path>'))->toBeFalse();
});

// Test PathHelper::getExtension
test('it retrieves the file extension', function () {
    expect(PathHelper::getExtension('file.txt'))->toBe('txt');
    expect(PathHelper::getExtension('file'))->toBe('');
});

// Test PathHelper::getFilename
test('it retrieves the filename with and without extension', function () {
    expect(PathHelper::getFilename('path/to/file.txt'))->toBe('file.txt');
    expect(PathHelper::getFilename('path/to/file.txt', false))->toBe('file');
});

// Test PathHelper::changeExtension
test('it changes the file extension', function () {
    expect(PathHelper::changeExtension('path/to/file.txt', 'docx'))->toBe('path' . DIRECTORY_SEPARATOR . 'to' . DIRECTORY_SEPARATOR . 'file.docx');
});

// Test PathHelper::relativePath
test('it calculates relative path', function () {
    expect(PathHelper::relativePath('/var/www/html', '/var/www/html/js/app.js'))->toBe('js' . DIRECTORY_SEPARATOR . 'app.js');
    expect(PathHelper::relativePath('/var/www/html', '/var/www/assets/css/style.css'))->toBe('..' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'style.css');
});

// Test PathHelper::sanitize
test('it sanitizes a path', function () {
    expect(PathHelper::sanitize('invalid/path*with|characters'))->toBe('invalid/pathwithcharacters');
});

// Test PathHelper::createDirectory and PathHelper::deleteDirectory
test('it creates and deletes a directory', function () {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'temp_test_dir';
    expect(PathHelper::createDirectory($tempDir))->toBeTrue();
    expect(is_dir($tempDir))->toBeTrue();
    expect(PathHelper::deleteDirectory($tempDir))->toBeTrue();
    expect(is_dir($tempDir))->toBeFalse();
});

// Test PathHelper::pathExists
test('it checks if path exists', function () {
    expect(PathHelper::pathExists($this->tempFilePath))->toBeTrue();
    expect(PathHelper::pathExists(sys_get_temp_dir() . '/non_existent_file.txt'))->toBeFalse();
});

// Test PathHelper::getPathType
test('it retrieves path type', function () {
    expect(PathHelper::getPathType($this->tempFilePath))->toBe('file');

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'temp_test_dir';
    mkdir($tempDir);
    expect(PathHelper::getPathType($tempDir))->toBe('directory');
    rmdir($tempDir);
});

// Test PathHelper::deleteFile
test('it deletes a file', function () {
    expect(PathHelper::deleteFile($this->tempFilePath))->toBeTrue();
    expect(file_exists($this->tempFilePath))->toBeFalse();
});

// Test PathHelper::createTempFile
test('it creates a temporary file', function () {
    $tempFile = PathHelper::createTempFile('temp_test_');
    expect(file_exists($tempFile))->toBeTrue();
    unlink($tempFile);
});

// Test PathHelper::createTempDirectory
test('it creates a temporary directory', function () {
    $tempDir = PathHelper::createTempDirectory('temp_test_');
    expect(is_dir($tempDir))->toBeTrue();
    rmdir($tempDir);
});
