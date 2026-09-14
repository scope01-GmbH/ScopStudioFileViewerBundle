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

namespace Scop\StudioFileViewerBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;

final class ScopStudioFileViewerBundle extends AbstractPimcoreBundle
{
    public function getNiceName(): string
    {
        return 'Scop Studio File Viewer';
    }

    public function getDescription(): string
    {
        return 'Browse, edit and download server files from within Pimcore Studio.';
    }
}
