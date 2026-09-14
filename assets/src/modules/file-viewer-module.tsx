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
