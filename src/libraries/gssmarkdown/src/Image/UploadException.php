<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Image;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Upload failure with one of the error codes the editor understands.
 */
final class UploadException extends \RuntimeException
{
    public const NO_AUTH        = 'NO_AUTH';
    public const INVALID_TOKEN  = 'INVALID_TOKEN';
    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    public const INVALID_TYPE   = 'INVALID_TYPE';
    public const WRITE_FAILED   = 'WRITE_FAILED';
    public const QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const BAD_REQUEST    = 'BAD_REQUEST';

    private const HTTP_STATUS = [
        self::NO_AUTH        => 403,
        self::INVALID_TOKEN  => 403,
        self::FILE_TOO_LARGE => 413,
        self::INVALID_TYPE   => 415,
        self::WRITE_FAILED   => 500,
        self::QUOTA_EXCEEDED => 507,
        self::BAD_REQUEST    => 400,
    ];

    /**
     * @param   string  $errorCode  One of the class constants.
     * @param   string  $message    Human readable, already translated.
     */
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS[$this->errorCode] ?? 400;
    }
}
