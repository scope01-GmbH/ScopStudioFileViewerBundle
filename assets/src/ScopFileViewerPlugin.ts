/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import { type IAbstractPlugin } from '@pimcore/studio-ui-bundle'
import { ScopFileViewerModule } from './modules/file-viewer-module'

export const ScopFileViewerPlugin: IAbstractPlugin = {
  name: 'ScopFileViewerPlugin',

  onStartup ({ moduleSystem }) {
    moduleSystem.registerModule(ScopFileViewerModule)
  }
}
