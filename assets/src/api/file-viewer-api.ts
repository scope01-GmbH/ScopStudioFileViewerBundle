/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import { api } from '@pimcore/studio-ui-bundle/api'
import type { DirectoryListing, FileContent, FileViewerConfig } from '../types'

/**
 * The literal Studio API prefix, matching AbstractFileViewerController::ROUTE_PREFIX.
 *
 * Studio's base query rewrites a URL that starts with this literal to the prefix the
 * installation actually configured, so it must be spelled out rather than resolved here.
 */
const PREFIX = '/pimcore-studio/api/scop-file-viewer'

const TAG = 'ScopFileViewerFile'

export const fileViewerApi = api
  .enhanceEndpoints({ addTagTypes: [TAG] })
  .injectEndpoints({
    endpoints: (builder) => ({
      scopFileViewerConfig: builder.query<FileViewerConfig, void>({
        query: () => ({ url: `${PREFIX}/config` })
      }),

      scopFileViewerDirectory: builder.query<DirectoryListing, { path: string }>({
        query: ({ path }) => ({ url: `${PREFIX}/directory`, params: { path } })
      }),

      scopFileViewerFile: builder.query<FileContent, { path: string, tail?: boolean }>({
        query: ({ path, tail }) => ({
          url: `${PREFIX}/file`,
          params: { path, ...(tail === true ? { tail: 1 } : {}) }
        }),
        providesTags: (_result, _error, { path }) => [{ type: TAG, id: path }]
      }),

      scopFileViewerWriteFile: builder.mutation<FileContent, { path: string, content: string }>({
        query: (body) => ({ url: `${PREFIX}/file`, method: 'PUT', body }),
        invalidatesTags: (_result, _error, { path }) => [{ type: TAG, id: path }]
      })
    }),
    overrideExisting: false
  })

/**
 * URL for the streaming download endpoint, used as a plain navigation rather than a fetch:
 * the Studio firewall is stateful, so the browser's existing session authenticates it, and
 * the file never has to pass through the browser's memory.
 */
export function getDownloadUrl (path: string): string {
  return `${PREFIX}/file/download?path=${encodeURIComponent(path)}`
}

export const {
  useScopFileViewerConfigQuery,
  useScopFileViewerDirectoryQuery,
  useLazyScopFileViewerDirectoryQuery,
  useScopFileViewerFileQuery,
  useScopFileViewerWriteFileMutation
} = fileViewerApi
