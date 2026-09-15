/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

/** Mirrors FileViewerService::STATUS_* on the PHP side. */
export type FileStatus = 'ok' | 'too_large' | 'binary'

export interface FileEntry {
  name: string
  /** Path relative to the configured root; '' is the root itself. */
  path: string
  isDirectory: boolean
  size: number | null
  modified: number
  readable: boolean
  writable: boolean
}

/** What the delete endpoint reports back about the entry it removed. */
export interface DeletedEntry {
  path: string
  isDirectory: boolean
}

export interface DirectoryListing {
  path: string
  entries: FileEntry[]
}

export interface FileContent extends FileEntry {
  status: FileStatus
  /** null when the file was not loaded because it is too large or binary. */
  content: string | null
  /** true when `content` is only the tail of a larger file. */
  truncated: boolean
  editable: boolean
  maxEditableSize: number
  tailBytes: number
}

export interface FileViewerConfig {
  root: string
  writable: boolean
  maxEditableSize: number
  tailBytes: number
}

export interface ApiError {
  error: string
}
