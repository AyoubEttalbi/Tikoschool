<?php

namespace App\Services;

use App\Support\ProfileImageUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;

/**
 * Stores, replaces and deletes profile images.
 *
 * Pipeline (synchronous by decision — revisit if bulk uploads start timing out,
 * per spec Phase 7): validate → decode safely → dimension guard → resize →
 * WebP → store only the processed file. The original upload never survives.
 *
 * Everything user-facing is a ValidationException so the existing Inertia forms
 * render it in their profile_image error slot; the technical cause goes to the
 * server log instead of the browser. Processing failures must NEVER surface as
 * an uncontrolled 500.
 */
class ProfileImageService
{
    public const MAX_KILOBYTES = 5120;          // 5 MB, matches the previous Cloudinary rule

    public const MAX_EDGE = 8000;               // header-level guard before any decode

    public const MAX_PIXELS = 25_000_000;       // ~25 MP: far above real photos, far below bomb territory

    public const TARGET = 512;                  // max edge after resize

    public const WEBP_QUALITY = 82;

    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new GdDriver);
    }

    /**
     * Validate and process an upload; returns the logical path to store in the DB
     * (e.g. "students/8f3c…deb.webp"). Throws ValidationException on ANY failure.
     */
    public function store(UploadedFile $file, string $type, string $errorField = 'profile_image'): string
    {
        $tempPath = null;

        try {
            $this->assertType($type);

            if (! $file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('upload error code '.$file->getError());
            }

            if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
                throw ValidationException::withMessages([
                    $errorField => "L'image ne doit pas dépasser ".(self::MAX_KILOBYTES / 1024).' Mo.',
                ]);
            }

            // Content sniffing only — client MIME and filename are untrusted.
            $detected = $file->getMimeType();

            // AVIF: websites routinely serve it with a .png/.jpg filename, so users
            // keep hitting "must be an image" on perfectly valid downloads. GD can
            // decode it natively but Intervention v3 cannot, so convert to WebP here
            // and let the normal pipeline continue with the converted file.
            if ($detected === 'image/avif') {
                $file = $this->convertAvifToWebp($file, $errorField);
                $tempPath = $file->getRealPath();
                $detected = 'image/webp';
            }

            if (! isset(self::ALLOWED_MIMES[$detected])) {
                throw ValidationException::withMessages([
                    $errorField => "Format d'image non pris en charge. Formats acceptés : JPG, PNG, WebP, AVIF.",
                ]);
            }

            // Decompression-bomb guard: read HEADER dimensions only, before GD
            // allocates pixels for the full bitmap.
            $info = @getimagesize($file->getRealPath());
            if ($info === false) {
                throw ValidationException::withMessages([
                    $errorField => 'Fichier image corrompu ou illisible.',
                ]);
            }
            [$width, $height] = $info;
            if ($width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > self::MAX_PIXELS) {
                throw ValidationException::withMessages([
                    $errorField => "Dimensions d'image trop grandes.",
                ]);
            }

            // Decode + process. orient() applies EXIF rotation (phone photos);
            // scaleDown() preserves aspect ratio and never upscales.
            // NOTE: pinned to intervention/image ^3.11 — v4 requires PHP >= 8.3 and
            // production runs PHP 8.2, so the v4-only decodePath()/encode() APIs are off-limits.
            $image = $this->manager->read($file->getRealPath());
            $image->orient();
            $image->scaleDown(self::TARGET, self::TARGET);
            $binary = (string) $image->toWebp(self::WEBP_QUALITY);

            if ($binary === '') {
                throw new \RuntimeException('WebP encoding produced empty output');
            }

            $path = $type.'/'.bin2hex(random_bytes(20)).'.webp';

            $stored = Storage::disk('profile-images')->put($path, $binary);
            if ($stored === false) {
                throw new \RuntimeException('failed writing processed image to disk');
            }

            return $path;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Profile image processing failed', [
                'type' => $type,
                'original_size' => $file->getSize(),
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                $errorField => 'Impossible de traiter cette image. Essayez un autre fichier.',
            ]);
        } finally {
            // The AVIF conversion works on a temp WebP copy; the original upload
            // stays untouched, so only the temp needs cleaning up.
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Decode an AVIF upload with native GD and re-encode it as WebP in a temp
     * file. Intervention Image v3.11 has no AVIF decoder, so this bridge is the
     * only way to let AVIF downloads through the normal pipeline.
     */
    private function convertAvifToWebp(UploadedFile $file, string $errorField): UploadedFile
    {
        if (! function_exists('imagecreatefromavif') || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages([
                $errorField => 'Les images AVIF ne sont pas prises en charge par ce serveur. Convertissez-les en PNG ou JPG.',
            ]);
        }

        $image = @imagecreatefromavif($file->getRealPath());
        if ($image === false) {
            throw ValidationException::withMessages([
                $errorField => 'Fichier image corrompu ou illisible.',
            ]);
        }

        $temp = tempnam(sys_get_temp_dir(), 'avif').'.webp';
        $written = imagewebp($image, $temp, 90);
        imagedestroy($image);

        if ($written === false || ! is_file($temp) || filesize($temp) === 0) {
            @unlink($temp);

            throw ValidationException::withMessages([
                $errorField => 'Impossible de traiter cette image AVIF. Essayez un PNG ou JPG.',
            ]);
        }

        // test=true: keep the temp file alive — store() unlinks it in its finally.
        return new UploadedFile($temp, 'converted.webp', 'image/webp', null, true);
    }

    /**
     * Idempotent, path-constrained delete. Only ever touches files that match our
     * own naming scheme under our own directories — legacy Cloudinary values,
     * absolute URLs and anything malformed are ignored, so this can never become
     * a filesystem traversal primitive.
     *
     * Replace ordering (spec Phase 9) lives with the callers: store() the NEW image,
     * swap the DB reference optimistically, delete the old file only on success, and
     * discard() the fresh file whenever a later step fails. Every controller in this
     * repo follows that shape; keep new ones consistent.
     */
    public function delete(?string $rawValue): void
    {
        if (! ProfileImageUrl::isLogicalPath($rawValue)) {
            return;
        }

        Storage::disk('profile-images')->delete($rawValue);
    }

    /** Remove a freshly-created file after a failed DB write (orphan cleanup). */
    public function discard(?string $logicalPath): void
    {
        if ($logicalPath !== null && ProfileImageUrl::isLogicalPath($logicalPath)) {
            Storage::disk('profile-images')->delete($logicalPath);
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, ProfileImageUrl::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown profile-image type [{$type}]");
        }
    }
}
