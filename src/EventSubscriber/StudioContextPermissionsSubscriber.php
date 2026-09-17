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

namespace Scop\StudioFileViewerBundle\EventSubscriber;

use Pimcore\Bundle\StudioBackendBundle\Perspective\Model\ContextPermissionData;
use Pimcore\Bundle\StudioBackendBundle\Perspective\Service\ContextPermissionsServiceInterface;
use Pimcore\Bundle\StudioBackendBundle\Perspective\Util\Constant\ContextPermissionGroups;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the file viewer's main navigation entry configurable per perspective.
 *
 * The frontend hands the perspective editor the permissions it finds on the registered nav
 * items, but the editor only renders the ones the backend also knows about - an unregistered
 * permission is dropped with a console error and the entry can never be switched off. So the
 * key registered here has to stay identical to the `perspectivePermission` in
 * assets/src/modules/file-viewer-module.tsx and to the translation key in
 * src/Resources/translations/studio.*.yaml.
 *
 * This is presentation only: who may actually read and write files is decided by
 * {@see \Scop\StudioFileViewerBundle\Security\AdminAccessGuard} on every request.
 *
 * @internal
 */
final readonly class StudioContextPermissionsSubscriber implements EventSubscriberInterface
{
    public const PERMISSION_KEY = 'scopFileViewer';

    public function __construct(
        private ContextPermissionsServiceInterface $permissionsService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'addContextPermissions',
        ];
    }

    public function addContextPermissions(): void
    {
        $this->permissionsService->add(
            new ContextPermissionData(
                self::PERMISSION_KEY,
                ContextPermissionGroups::SYSTEM->value,
                true,
            ),
        );
    }
}
