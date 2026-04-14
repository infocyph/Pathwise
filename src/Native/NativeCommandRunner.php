<?php

namespace Infocyph\Pathwise\Native;

final class NativeCommandRunner
{
    /**
     * Check if a command exists on the system.
     *
     * @param string $command The command to check.
     * @return bool True if the command exists, false otherwise.
     */
    public static function commandExists(string $command): bool
    {
        $lookup = PHP_OS_FAMILY === 'Windows'
            ? "where " . escapeshellcmd($command) . " >NUL 2>&1"
            : "command -v " . escapeshellarg($command) . " >/dev/null 2>&1";

        $result = self::run($lookup);

        return $result['success'];
    }
    /**
     * @return array{success: bool, output: array<int, string>, code: int}
     */
    public static function run(string $command): array
    {
        $output = [];
        $exitCode = 1;

        exec($command . ' 2>&1', $output, $exitCode);

        return [
            'success' => $exitCode === 0,
            'output' => $output,
            'code' => $exitCode,
        ];
    }
}
