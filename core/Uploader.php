<?php
declare(strict_types=1);

/**
 * CivicShield - Secure File Upload & Media Sanitizer
 * Handles image validation, MIME verification, EXIF stripping, and filesystem protection.
 */
class Uploader
{
    public const MAX_FILE_SIZE = 5242880; // 5 MB

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Process, sanitize, and store an uploaded incident evidence image.
     *
     * @param array $file $_FILES entry
     * @param string $destinationDir Directory under UPLOADS_PATH (e.g. 'incidents')
     * @return array [bool success, ?string error, ?array data]
     */
    public static function processImage(array $file, string $destinationDir = 'incidents'): array
    {
        // 1. Check upload errors
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'error' => 'Invalid file upload parameter.'];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return ['success' => false, 'error' => 'No file was uploaded.'];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['success' => false, 'error' => 'File size exceeds maximum allowed upload limit (5MB).'];
            default:
                return ['success' => false, 'error' => 'An error occurred during file transfer.'];
        }

        // 2. Validate file size using a safe configuration fallback.
        $rawConfig = Env::get('MAX_UPLOAD_SIZE', null);
        $maxBytes = self::MAX_FILE_SIZE; // Default: 5 MiB

        if (
            is_string($rawConfig)
            && preg_match('/\A[1-9][0-9]*\z/', $rawConfig) === 1
            && (float) $rawConfig <= 104857600
        ) {
            $maxBytes = (int) $rawConfig;
        }

        $actualSize = filesize($file['tmp_name']);

        if (
            $actualSize === false
            || !isset($file['size'])
            || $file['size'] > $maxBytes
            || $actualSize > $maxBytes
        ) {
            return [
                'success' => false,
                'error' => sprintf(
                    'Attachment exceeds the maximum allowed size of %d MB.',
                    (int) ($maxBytes / 1048576)
                )
            ];
        }

        // 3. Inspect real server-side MIME type via fileinfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);

        if (!array_key_exists($detectedMime, self::ALLOWED_MIME_TYPES)) {
            return [
                'success' => false,
                'error' => 'Unsupported file format. Only JPEG, PNG, and WebP raster images are permitted.'
            ];
        }

        // 4. Verify image dimensions and structure
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return [
                'success' => false,
                'error' => 'The uploaded file appears to be corrupted or is not a valid raster image.'
            ];
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        if ($width < 10 || $height < 10 || $width > 6000 || $height > 6000) {
            return [
                'success' => false,
                'error' => 'Image dimensions must be between 10x10 and 6000x6000 pixels.'
            ];
        }

        // 5. Ensure destination directory exists and is writable
        $targetDir = UPLOADS_PATH . DIRECTORY_SEPARATOR . $destinationDir;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        if (!is_writable($targetDir)) {
            @error_log("[Uploader Error] Target upload directory not writable: {$targetDir}");
            return ['success' => false, 'error' => 'Server storage configuration error. Please contact administration.'];
        }

        // 6. Generate cryptographically random filename
        $extension = self::ALLOWED_MIME_TYPES[$detectedMime];
        $randomBaseName = 'att_' . bin2hex(random_bytes(16));
        $storedFileName = $randomBaseName . '.' . $extension;
        $targetFilePath = $targetDir . DIRECTORY_SEPARATOR . $storedFileName;
        $relativeFilePath = 'uploads/' . $destinationDir . '/' . $storedFileName;

        $originalName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename((string) $file['name']));
        if (strlen($originalName) > 200) {
            $originalName = substr($originalName, -200);
        }

        // 7. EXIF Metadata Scrubbing & Image Re-encoding
        $exifScrubbed = 0;

        if (extension_loaded('gd')) {
            $sanitized = self::reencodeImageWithGd($file['tmp_name'], $detectedMime, $targetFilePath);
            if ($sanitized) {
                $exifScrubbed = 1;
            } else {
                // Fallback to standard move if GD re-encoding fails
                if (!@move_uploaded_file($file['tmp_name'], $targetFilePath)) {
                    return ['success' => false, 'error' => 'Failed to save sanitized image attachment.'];
                }
            }
        } else {
            // GD unavailable: direct move, document limitation
            if (!@move_uploaded_file($file['tmp_name'], $targetFilePath)) {
                return ['success' => false, 'error' => 'Failed to save image attachment.'];
            }
        }

        // 8. Compute cryptographic SHA-256 content digest
        $fileSha256 = hash_file('sha256', $targetFilePath);
        $finalSize = filesize($targetFilePath);

        return [
            'success' => true,
            'error' => null,
            'data' => [
                'file_path' => $relativeFilePath,
                'file_name_original' => $originalName,
                'file_name_stored' => $storedFileName,
                'file_mime' => $detectedMime,
                'file_size_bytes' => $finalSize,
                'sha256_hash' => $fileSha256,
                'exif_scrubbed' => $exifScrubbed,
                'absolute_path' => $targetFilePath
            ]
        ];
    }

    /**
     * Re-encode raster image using GD library to completely strip EXIF metadata
     * and eliminate embedded executable polyglots.
     */
    private static function reencodeImageWithGd(string $sourcePath, string $mime, string $targetPath): bool
    {
        try {
            $sourceImage = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($sourcePath),
                'image/png' => @imagecreatefrompng($sourcePath),
                'image/webp' => @imagecreatefromwebp($sourcePath),
                default => false
            };

            if ($sourceImage === false) {
                return false;
            }

            // Preserve alpha channel for PNG/WebP
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($sourceImage, false);
                imagesavealpha($sourceImage, true);
            }

            $success = match ($mime) {
                'image/jpeg' => imagejpeg($sourceImage, $targetPath, 88),
                'image/png' => imagepng($sourceImage, $targetPath, 8),
                'image/webp' => imagewebp($sourceImage, $targetPath, 85),
                default => false
            };

            imagedestroy($sourceImage);
            return $success;
        } catch (Throwable $e) {
            @error_log('[GD Re-encoding Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete an uploaded file safely (used for rollback during transaction failures).
     */
    public static function cleanup(?string $filePath): void
    {
        if ($filePath === null || $filePath === '') {
            return;
        }

        $uploadsDir = realpath(UPLOADS_PATH);
        if ($uploadsDir === false || !is_dir($uploadsDir)) {
            return;
        }

        $rootPath = rtrim(ROOT_PATH, DIRECTORY_SEPARATOR);
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        // Recognize absolute paths without case-sensitive matching on Windows.
        $isAbsoluteProjectPath = $isWindows
            ? strncasecmp($filePath, $rootPath, strlen($rootPath)) === 0
            && isset($filePath[strlen($rootPath)])
            && in_array($filePath[strlen($rootPath)], ['\\', '/'], true)
            : str_starts_with($filePath, $rootPath . DIRECTORY_SEPARATOR);

        $absolutePath = $isAbsoluteProjectPath
            ? $filePath
            : ROOT_PATH . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($filePath, '/\\'));

        $resolvedPath = realpath($absolutePath);
        if ($resolvedPath === false || !is_file($resolvedPath)) {
            return;
        }

        $uploadsDirWithSep = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // Normalize for comparison only; retain the canonical path for filesystem operations.
        $comparisonPath = $isWindows ? strtolower($resolvedPath) : $resolvedPath;
        $comparisonUploads = $isWindows
            ? strtolower($uploadsDirWithSep)
            : $uploadsDirWithSep;

        if (str_starts_with($comparisonPath, $comparisonUploads)) {
            @unlink($resolvedPath);
        }
    }
}
