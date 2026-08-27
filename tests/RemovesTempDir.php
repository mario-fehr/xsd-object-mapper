<?php

declare(strict_types=1);

namespace Xsd2Php\Tests;

/** Recursively deletes a test's temp directory - shared by every test that generates into one. */
trait RemovesTempDir
{
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
