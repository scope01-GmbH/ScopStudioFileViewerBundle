/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import { type AbstractModule, container } from '@pimcore/studio-ui-bundle'
import { serviceIds } from '@pimcore/studio-ui-bundle/app'
import { type MainNavRegistry } from '@pimcore/studio-ui-bundle/modules/app'
import { type WidgetRegistry } from '@pimcore/studio-ui-bundle/modules/widget-manager'
import { FileViewerWidget } from '../components/FileViewerWidget'

const WIDGET_ID = 'scop-file-viewer'

const TITLE_KEY = 'scop-file-viewer.title'

/**
 * Keep identical to `StudioContextPermissionsSubscriber::PERMISSION_KEY` and to
 * `perspective-editor.form.main-nav-permission.system.scopFileViewer` in the translation
 * catalogues. The perspective editor collects the permissions it finds on the registered nav
 * items, drops the ones the backend does not know about, and labels the rest from that
 * translation key - so all three have to agree or the entry cannot be configured at all.
 */
const PERSPECTIVE_PERMISSION = 'system.scopFileViewer'

/**
 * Every route checks isAdmin() server side, so for anyone else this entry only ever leads to
 * a 403. Studio's user permission check lets an admin through whatever the key is, and a
 * non-admin can never hold a key that is not in Pimcore's permission table - which makes
 * this the audience the backend already enforces.
 */
const USER_PERMISSION = 'scop_file_viewer'

export const ScopFileViewerModule: AbstractModule = {
  onInit: (): void => {
    const widgetRegistry = container.get<WidgetRegistry>(serviceIds.widgetManager)

    widgetRegistry.registerWidget({
      name: WIDGET_ID,
      component: FileViewerWidget
    })

    const mainNavRegistry = container.get<MainNavRegistry>(serviceIds.mainNavRegistry)

    mainNavRegistry.registerMainNavItem({
      // `path` is the structural position in the menu; `label` and `translationKey` are what
      // the user actually sees, so both are translation keys rather than literals.
      path: 'System/File Viewer',
      label: TITLE_KEY,
      permission: USER_PERMISSION,
      perspectivePermission: PERSPECTIVE_PERMISSION,
      widgetConfig: {
        name: 'File Viewer',
        id: WIDGET_ID,
        component: WIDGET_ID,
        config: {
          translationKey: TITLE_KEY,
          icon: {
            type: 'name',
            value: 'file'
          }
        }
      }
    })
  }
}
