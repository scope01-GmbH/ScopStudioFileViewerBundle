<?php

declare(strict_types=1);

/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in
 * LICENSE which is distributed with this source code.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

namespace Scop\StudioFileViewerBundle\Exception;

use RuntimeException;

/**
 * Base class for every error the file viewer reports back to the client.
 *
 * The message of these exceptions is shown to the (admin) user, so it must never
 * contain absolute server paths - callers pass paths relative to the configured root.
 */
abstract class FileViewerException extends RuntimeException
{
    abstract public function getStatusCode(): int;
}
