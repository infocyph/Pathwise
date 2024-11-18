# Pathwise: File Management Made Simple

[![Codacy Badge](https://app.codacy.com/project/badge/Grade/5028848d26e34f5e883aa248a8885811)](https://app.codacy.com/gh/infocyph/Pathwise/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
![Libraries.io dependency status for GitHub repo](https://img.shields.io/librariesio/github/infocyph/pathwise)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/pathwise)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/pathwise)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/infocyph/pathwise)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/infocyph/pathwise)

Pathwise is a robust PHP library designed for streamlined file and directory management. With features like safe reading/writing, metadata extraction, path utilities, compression, and permission management, it ensures a developer-friendly experience while handling complex file operations.

## **Table of Contents**
1. [Introduction](#pathwise-file-management-made-simple)
2. [Prerequisites](#prerequisites)
3. [Installation](#installation)
4. [Features Overview](#features-overview)
5. [FileManager](#filemanager)
    - [SafeFileReader](#safefilereader)
    - [SafeFileWriter](#safefilewriter)
    - [FileOperations](#fileoperations)
    - [FileCompression](#filecompression)
6. [DirectoryManager](#directorymanager)
    - [DirectoryOperations](#directoryoperations)
7. [Utils](#utils)
    - [PathHelper](#pathhelper)
    - [PermissionsHelper](#permissionshelper)
    - [MetadataHelper](#metadatahelper)
8. [Handy Functions](#handy-functions)
    - [File and Directory Utilities](#file-and-directory-utilities)
9. [Support](#support)
10. [License](#license)

## **Prerequisites**
- Language: PHP 8.2/+



## **Installation**
Pathwise is available via Composer:

```bash
composer require infocyph/pathwise
```

Requirements:
- PHP 8.2 or higher
- Optional Extensions:
    - `ext-zip`: Required for compression features.
    - `ext-posix`: Required for permission handling.
    - `ext-xmlreader` and `ext-simplexml`: Required for XML parsing.

---

## **Features Overview**

## **FileManager**

The `FileManager` module provides classes for handling files, including reading, writing, compressing, and general file operations.

### **SafeFileReader**

A memory-safe file reader supporting various reading modes (line-by-line, binary chunks, JSON, CSV, XML, etc.) and iterator interfaces.

#### **Key Features**
- Supports multiple reading modes.
- Provides locking to prevent concurrent access issues.
- Implements `Countable`, `Iterator`, and `SeekableIterator`.

#### **Usage Example**

```php
use Infocyph\Pathwise\FileManager\SafeFileReader;

$reader = new SafeFileReader('/path/to/file.txt');

// Line-by-line iteration
foreach ($reader->line() as $line) {
    echo $line;
}

// JSON decoding with error handling
foreach ($reader->json() as $data) {
    print_r($data);
}
```

### **SafeFileWriter**

A memory-safe file writer with support for various writing modes, including CSV, JSON, binary, and more.

#### **Key Features**
- Supports multiple writing modes.
- Ensures file locking and robust error handling.
- Tracks write operations and supports flush and truncate methods.

#### **Usage Example**

```php
use Infocyph\Pathwise\FileManager\SafeFileWriter;

$writer = new SafeFileWriter('/path/to/file.txt');

// Writing lines
$writer->line('Hello, World!');

// Writing JSON data
$writer->json(['key' => 'value']);
```

### **FileOperations**

General-purpose file handling class for creating, deleting, copying, renaming, and manipulating files.

#### **Key Features**
- File creation and deletion.
- Append and update content.
- Rename, copy, and metadata retrieval.

#### **Usage Example**

```php
use Infocyph\Pathwise\FileManager\FileOperations;

$fileOps = new FileOperations('/path/to/file.txt');

// Check existence
if ($fileOps->exists()) {
    echo 'File exists';
}

// Read content
echo $fileOps->read();
```

### **FileCompression**

Provides utilities for compressing and decompressing files using the ZIP format with optional password protection and encryption.

#### **Key Features**
- Compress files/directories.
- Decompress ZIP archives.
- Support for AES encryption and password-protected ZIPs.

#### **Usage Example**

```php
use Infocyph\Pathwise\FileManager\FileCompression;

$compression = new FileCompression('/path/to/archive.zip');

// Compress a directory
$compression->compress('/path/to/directory');

// Decompress
$compression->decompress('/path/to/extract/');
```



## **DirectoryManager**

The `DirectoryManager` module offers tools for handling directory creation, deletion, and traversal.


### **DirectoryOperations**

Provides comprehensive tools for managing directories, including creation, deletion, copying, and listing contents.

#### **Key Features**
- Create, delete, and copy directories.
- Retrieve directory size, depth, and contents.
- Supports recursive operations and filtering.

#### **Usage Example**

```php
use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

$dirOps = new DirectoryOperations('/path/to/directory');

// Create a directory
$dirOps->create();

// List contents
$contents = $dirOps->listContents(detailed: true);
print_r($contents);
```



## **Utils**

Utility classes for managing paths, permissions, and metadata.


### **PathHelper**

Provides utilities for working with file paths, including joining, normalizing, and converting between relative and absolute paths.

#### **Key Features**
- Path joining and normalization.
- Convert between relative and absolute paths.
- Retrieve and manipulate file extensions.

#### **Usage Example**

```php
use Infocyph\Pathwise\Utils\PathHelper;

$absolutePath = PathHelper::toAbsolutePath('relative/path');
echo $absolutePath;

$joinedPath = PathHelper::join('/var', 'www', 'html');
echo $joinedPath;
```

### **PermissionsHelper**

Handles file and directory permissions, ownership, and access control.

#### **Key Features**
- Retrieve and set permissions.
- Check read, write, and execute access.
- Retrieve and set ownership details.

#### **Usage Example**

```php
use Infocyph\Pathwise\Utils\PermissionsHelper;

// Get human-readable permissions
echo PermissionsHelper::getHumanReadablePermissions('/path/to/file');

// Check if writable
if (PermissionsHelper::canWrite('/path/to/file')) {
    echo 'File is writable';
}
```

### **MetadataHelper**

Extracts metadata for files and directories, such as size, timestamps, MIME type, and more.

#### **Key Features**
- Retrieve file size and type.
- Compute checksums and timestamps.
- Get ownership and visibility details.

#### **Usage Example**

```php
use Infocyph\Pathwise\Utils\MetadataHelper;

// Get file size
$size = MetadataHelper::getFileSize('/path/to/file');
echo "File size: $size bytes";

// Retrieve metadata
$metadata = MetadataHelper::getAllMetadata('/path/to/file');
print_r($metadata);
```

## **Handy Functions**

### **File and Directory Utilities**

Pathwise provides standalone utility functions to simplify common file and directory operations.

#### **1. Get Human-Readable File Size**
Formats a file size in bytes into a human-readable format (e.g., `1.23 KB`, `4.56 GB`).

**Usage Example:**
```php
$size = getHumanReadableFileSize(123456789);
echo $size; // Output: "117.74 MB"
```

#### **2. Check if a Directory is Empty**
Checks whether the given directory contains any files or subdirectories.

**Usage Example:**
```php
$isEmpty = isDirectoryEmpty('/path/to/directory');
echo $isEmpty ? 'Empty' : 'Not Empty';
```

#### **3. Delete a Directory Recursively**
Deletes a directory and all its contents (files and subdirectories).

**Usage Example:**
```php
$success = deleteDirectory('/path/to/directory');
echo $success ? 'Deleted successfully' : 'Failed to delete';
```

#### **4. Get Directory Size**
Calculates the total size of a directory, including all its files and subdirectories.

**Usage Example:**
```php
$size = getDirectorySize('/path/to/directory');
echo "Directory size: " . getHumanReadableFileSize($size);
```

#### **5. Create a Directory**
Creates a directory (including parent directories) with specified permissions.

**Usage Example:**
```php
$success = createDirectory('/path/to/new/directory');
echo $success ? 'Directory created' : 'Failed to create directory';
```

#### **6. List Files in a Directory**
Lists all files in a directory, excluding subdirectories.

**Usage Example:**
```php
$files = listFiles('/path/to/directory');
print_r($files);
```

#### **7. Copy a Directory Recursively**
Copies a directory and all its contents to a new location.

**Usage Example:**
```php
$success = copyDirectory('/source/directory', '/destination/directory');
echo $success ? 'Copied successfully' : 'Failed to copy';
```

---
## **Support**
Having trouble? Create an issue!

## **License**
Pathwise is licensed under the [MIT License](LICENSE).
