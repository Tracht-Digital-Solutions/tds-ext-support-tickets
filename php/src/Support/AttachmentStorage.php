<?php
declare(strict_types=1);

namespace Tds\Ext\SupportTickets\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * On-disk attachment storage (ported from tds-customer-api's AttachmentStorage).
 * Bytes live under TICKET_UPLOAD_DIR/{scope}/{uuid}-{name}; only metadata is
 * persisted in ticket_attachment. MIME + size are whitelisted. No-ops (available()
 * false) when the dir is unconfigured/unwritable, so the caller returns 503.
 */
final class AttachmentStorage
{
    public const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function rootDir(): string
    {
        return (string) (getenv('TICKET_UPLOAD_DIR') ?: '');
    }

    public function available(): bool
    {
        $root = $this->rootDir();
        return $root !== '' && is_dir($root) && is_writable($root);
    }

    /**
     * Store an uploaded file, return its metadata.
     *
     * @return array{filename:string,storage_path:string,mime_type:string,size_bytes:int}
     */
    public function store(int $scope, UploadedFileInterface $file): array
    {
        $mime = $file->getClientMediaType() ?? 'application/octet-stream';
        $root = $this->rootDir();
        $dir = $root . DIRECTORY_SEPARATOR . $scope . DIRECTORY_SEPARATOR . 'tickets';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) $file->getClientFilename()) ?: 'file';
        $rel = $scope . '/tickets/' . bin2hex(random_bytes(8)) . '-' . $safeName;
        $file->moveTo($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));

        return [
            'filename' => $safeName,
            'storage_path' => $rel,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ];
    }

    /**
     * Store raw bytes (a decoded email MIME part), or null when the part is
     * disallowed / oversize / unwritable — the IMAP ingester skips it rather
     * than failing the whole message. Same layout + name sanitising as store().
     *
     * @return array{filename:string,storage_path:string,mime_type:string,size_bytes:int}|null
     */
    public function storeBytes(int $scope, string $filename, string $bytes, string $mime): ?array
    {
        $size = strlen($bytes);
        if (!$this->available() || $size === 0 || $size > self::MAX_BYTES) {
            return null;
        }
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return null;
        }
        $root = $this->rootDir();
        $dir = $root . DIRECTORY_SEPARATOR . $scope . DIRECTORY_SEPARATOR . 'tickets';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'file';
        $rel = $scope . '/tickets/' . bin2hex(random_bytes(8)) . '-' . $safeName;
        if (file_put_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel), $bytes) === false) {
            return null;
        }
        return ['filename' => $safeName, 'storage_path' => $rel, 'mime_type' => $mime, 'size_bytes' => $size];
    }

    /** Absolute path of a stored attachment, or null when it's missing on disk. */
    public function absolutePath(string $storagePath): ?string
    {
        $abs = $this->rootDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        return is_file($abs) ? $abs : null;
    }
}
