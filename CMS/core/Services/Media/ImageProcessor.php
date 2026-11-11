<?php
declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class ImageProcessor
{
    public function convertToWebP(string $filePath, int $quality = 85): bool|WP_Error
    {
        if (!file_exists($filePath)) {
            return new WP_Error('file_not_found', 'Datei nicht gefunden');
        }

        if (!extension_loaded('gd')) {
            return new WP_Error('gd_missing', 'GD-Erweiterung ist nicht verfügbar');
        }

        $imageInfo = $this->getImageSize($filePath);
        if ($imageInfo === false) {
            return new WP_Error('invalid_image', 'Ungültige Bilddatei');
        }

        $mimeType = $imageInfo['mime'] ?? '';
        $source = match ($mimeType) {
            'image/jpeg' => $this->createImageFromJpeg($filePath),
            'image/png' => $this->createImageFromPng($filePath),
            'image/gif' => $this->createImageFromGif($filePath),
            'image/webp' => $this->createImageFromWebp($filePath),
            default => false,
        };

        if ($source === false) {
            return new WP_Error('image_load_failed', 'Bild konnte nicht geladen werden');
        }

        $targetPath = preg_replace('/\.[^.]+$/', '.webp', $filePath);
        if ($targetPath === null || $targetPath === '') {
            unset($source);
            return new WP_Error('target_path_invalid', 'WebP-Zielpfad konnte nicht erzeugt werden');
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $success = $this->writeWebp($source, $targetPath, max(1, min($quality, 100)));
        unset($source);

        if (!$success) {
            return new WP_Error('webp_conversion_failed', 'WebP-Konvertierung fehlgeschlagen');
        }

        return true;
    }

    /**
     * @param array<string, array{0:int,1:int}> $sizes
     * @return array<int, string>|WP_Error
     */
    public function generateThumbnails(string $filePath, array $sizes, int $quality = 85): array|WP_Error
    {
        if (!file_exists($filePath)) {
            return new WP_Error('file_not_found', 'Datei nicht gefunden');
        }

        if (!extension_loaded('gd')) {
            return new WP_Error('gd_missing', 'GD-Erweiterung ist nicht verfügbar');
        }

        $imageInfo = $this->getImageSize($filePath);
        if ($imageInfo === false) {
            return new WP_Error('invalid_image', 'Ungültige Bilddatei');
        }

        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
        $source = $this->createImageResource($filePath, $mimeType);

        if ($source === false) {
            return new WP_Error('image_load_failed', 'Bild konnte nicht geladen werden');
        }

        $generatedPaths = [];
        $sourceWidth = (int) ($imageInfo[0] ?? 0);
        $sourceHeight = (int) ($imageInfo[1] ?? 0);

        foreach ($sizes as $name => $dimensions) {
            $maxWidth = max(1, (int) ($dimensions[0] ?? 0));
            $maxHeight = max(1, (int) ($dimensions[1] ?? 0));
            $thumbnailPath = $this->buildDerivativePath($filePath, (string) $name);

            $resized = $this->createResizedCanvas($source, $sourceWidth, $sourceHeight, $maxWidth, $maxHeight, $mimeType);
            if ($resized === false) {
                continue;
            }

            $saved = $this->writeImageResource($resized, $thumbnailPath, $mimeType, $quality);
            unset($resized);

            if ($saved) {
                $generatedPaths[] = $thumbnailPath;
            }
        }

        unset($source);

        return $generatedPaths;
    }

    public function resizeToFit(string $filePath, int $maxWidth, int $maxHeight, int $quality = 85): bool|WP_Error
    {
        if (!file_exists($filePath)) {
            return new WP_Error('file_not_found', 'Datei nicht gefunden');
        }

        if (!extension_loaded('gd')) {
            return new WP_Error('gd_missing', 'GD-Erweiterung ist nicht verfügbar');
        }

        $imageInfo = $this->getImageSize($filePath);
        if ($imageInfo === false) {
            return new WP_Error('invalid_image', 'Ungültige Bilddatei');
        }

        $sourceWidth = (int) ($imageInfo[0] ?? 0);
        $sourceHeight = (int) ($imageInfo[1] ?? 0);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return new WP_Error('invalid_image_dimensions', 'Ungültige Bilddimensionen');
        }

        if ($sourceWidth <= $maxWidth && $sourceHeight <= $maxHeight) {
            return true;
        }

        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));
        $source = $this->createImageResource($filePath, $mimeType);
        if ($source === false) {
            return new WP_Error('image_load_failed', 'Bild konnte nicht geladen werden');
        }

        $resized = $this->createResizedCanvas($source, $sourceWidth, $sourceHeight, $maxWidth, $maxHeight, $mimeType);
        unset($source);

        if ($resized === false) {
            return new WP_Error('image_resize_failed', 'Bild konnte nicht skaliert werden');
        }

        $saved = $this->writeImageResource($resized, $filePath, $mimeType, $quality);
        unset($resized);

        if (!$saved) {
            return new WP_Error('image_save_failed', 'Skaliertes Bild konnte nicht gespeichert werden');
        }

        return true;
    }

    private function getImageSize(string $filePath): array|false
    {
        return $this->runGdOperation(static fn() => getimagesize($filePath));
    }

    private function createImageResource(string $filePath, string $mimeType)
    {
        return match ($mimeType) {
            'image/jpeg' => $this->createImageFromJpeg($filePath),
            'image/png' => $this->createImageFromPng($filePath),
            'image/gif' => $this->createImageFromGif($filePath),
            'image/webp' => $this->createImageFromWebp($filePath),
            'image/bmp', 'image/x-bmp' => $this->createImageFromBmp($filePath),
            default => false,
        };
    }

    private function createImageFromJpeg(string $filePath)
    {
        return $this->runGdOperation(static fn() => imagecreatefromjpeg($filePath));
    }

    private function createImageFromPng(string $filePath)
    {
        return $this->runGdOperation(static fn() => imagecreatefrompng($filePath));
    }

    private function createImageFromGif(string $filePath)
    {
        return $this->runGdOperation(static fn() => imagecreatefromgif($filePath));
    }

    private function createImageFromWebp(string $filePath)
    {
        return $this->runGdOperation(static fn() => imagecreatefromwebp($filePath));
    }

    private function createImageFromBmp(string $filePath)
    {
        if (!function_exists('imagecreatefrombmp')) {
            return false;
        }

        return $this->runGdOperation(static fn() => imagecreatefrombmp($filePath));
    }

    private function writeWebp(mixed $source, string $targetPath, int $quality): bool
    {
        return $this->runGdOperation(static fn() => imagewebp($source, $targetPath, $quality)) === true;
    }

    private function writeImageResource(mixed $image, string $targetPath, string $mimeType, int $quality): bool
    {
        return match ($mimeType) {
            'image/jpeg' => $this->runGdOperation(static fn() => imagejpeg($image, $targetPath, max(60, min($quality, 100)))) === true,
            'image/png' => $this->runGdOperation(static fn() => imagepng($image, $targetPath, max(0, min(9, (int) round((100 - max(1, min($quality, 100))) / 10))))) === true,
            'image/gif' => $this->runGdOperation(static fn() => imagegif($image, $targetPath)) === true,
            'image/webp' => $this->runGdOperation(static fn() => imagewebp($image, $targetPath, max(1, min($quality, 100)))) === true,
            'image/bmp', 'image/x-bmp' => function_exists('imagebmp')
                ? $this->runGdOperation(static fn() => imagebmp($image, $targetPath)) === true
                : false,
            default => false,
        };
    }

    private function createResizedCanvas(mixed $source, int $sourceWidth, int $sourceHeight, int $maxWidth, int $maxHeight, string $mimeType)
    {
        if ($sourceWidth <= 0 || $sourceHeight <= 0 || $maxWidth <= 0 || $maxHeight <= 0) {
            return false;
        }

        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            return false;
        }

        if ($mimeType === 'image/jpeg' || $mimeType === 'image/bmp' || $mimeType === 'image/x-bmp') {
            $background = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $background);
        } else {
            imagealphablending($canvas, false);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefill($canvas, 0, 0, $transparent);
            imagesavealpha($canvas, true);
        }

        $resampled = $this->runGdOperation(static fn() => imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        ));

        if ($resampled !== true) {
            unset($canvas);
            return false;
        }

        return $canvas;
    }

    private function buildDerivativePath(string $filePath, string $suffix): string
    {
        $directory = dirname($filePath);
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return $directory . DIRECTORY_SEPARATOR . $filename . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
    }

    private function runGdOperation(callable $operation): mixed
    {
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
