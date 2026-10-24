<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$excludedDirectories = ['.git', '.idea', '.style', '.vscode', 'vendor'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($excludedDirectories): bool {
            return !$file->isDir() || !in_array($file->getFilename(), $excludedDirectories, true);
        }
    )
);

$invalidFiles = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    try {
        $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        $formatted = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $invalidFiles[] = sprintf('%s: %s', substr($path, strlen($root) + 1), $exception->getMessage());
        continue;
    }

    $normalizedContent = rtrim(str_replace(["\r\n", "\r"], "\n", $content), "\n");
    if ($normalizedContent !== $formatted) {
        $invalidFiles[] = sprintf('%s: JSON formatting differs from the project style.', substr($path, strlen($root) + 1));
    }
}

if ($invalidFiles !== []) {
    fwrite(STDERR, implode(PHP_EOL, $invalidFiles) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "All JSON files are valid and match the project style." . PHP_EOL);
