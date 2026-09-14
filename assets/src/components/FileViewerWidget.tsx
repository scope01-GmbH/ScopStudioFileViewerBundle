/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import React, { useCallback, useState } from 'react'
import { Flex, SplitLayout, Tabs, Text } from '@pimcore/studio-ui-bundle/components'
import { FileTreePanel } from './FileTreePanel'
import { FileEditorPane } from './FileEditorPane'
import type { FileEntry } from '../types'

interface OpenFile {
  path: string
  name: string
  dirty: boolean
}

export const FileViewerWidget = (): React.JSX.Element => {
  const [openFiles, setOpenFiles] = useState<OpenFile[]>([])
  const [activePath, setActivePath] = useState<string | null>(null)

  const handleFileOpen = useCallback((entry: FileEntry): void => {
    setOpenFiles((current) => (
      current.some((file) => file.path === entry.path)
        ? current
        : [...current, { path: entry.path, name: entry.name, dirty: false }]
    ))
    setActivePath(entry.path)
  }, [])

  const handleClose = useCallback((targetPath: string): void => {
    setOpenFiles((current) => {
      const remaining = current.filter((file) => file.path !== targetPath)

      setActivePath((active) => {
        if (active !== targetPath) return active

        return remaining.length > 0 ? remaining[remaining.length - 1].path : null
      })

      return remaining
    })
  }, [])

  /**
   * Kept here rather than in the pane so the tab label can show the unsaved marker while
   * another tab is in front.
   */
  const handleDirtyChange = useCallback((path: string, dirty: boolean): void => {
    setOpenFiles((current) => {
      const file = current.find((candidate) => candidate.path === path)

      if (file === undefined || file.dirty === dirty) return current

      return current.map((candidate) => (
        candidate.path === path ? { ...candidate, dirty } : candidate
      ))
    })
  }, [])

  return (
    <SplitLayout
      leftItem={ {
        // `size` is a percentage of the container; `minSize`/`maxSize` are pixels. Passing a
        // pixel value as `size` makes the left pane swallow the whole width.
        minSize: 220,
        maxSize: 460,
        size: 22,
        children: (
          <FileTreePanel onFileOpen={ handleFileOpen } selectedPath={ activePath } />
        )
      } }
      resizeAble
      rightItem={ {
        minSize: 320,
        children: openFiles.length === 0
          ? (
            <Flex align="center" justify="center" style={ { height: '100%' } }>
              <Text type="secondary">Select a file in the tree to open it.</Text>
            </Flex>
            )
          : (
            <Tabs
              activeKey={ activePath ?? undefined }
              fullHeight
              hideAdd
              items={ openFiles.map((file) => ({
                key: file.path,
                label: `${file.dirty ? '● ' : ''}${file.name}`,
                children: (
                  <FileEditorPane onDirtyChange={ handleDirtyChange } path={ file.path } />
                )
              })) }
              noPadding
              onChange={ (key: string) => { setActivePath(key) } }
              onEdit={ (targetKey: unknown, action: string) => {
                if (action === 'remove' && typeof targetKey === 'string') {
                  handleClose(targetKey)
                }
              } }
              type="editable-card"
            />
            )
      } }
      withDivider
    />
  )
}

export default FileViewerWidget
