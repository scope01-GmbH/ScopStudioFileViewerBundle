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
import { createStyles, useTranslation } from '@pimcore/studio-ui-bundle/app'
import { FileTreePanel } from './FileTreePanel'
import { FileEditorPane } from './FileEditorPane'
import type { FileEntry } from '../types'

/**
 * antd sizes the active tab pane from its content, so the pane grew to fit the whole file
 * (1141px in a 772px content box) and every height below it inherited that. The editor then
 * had no bounded height and .cm-scroller had nothing to scroll within, so long files were
 * clipped rather than scrollable. Constraining the pane to its content box is what gives the
 * editor a real box to scroll inside; `fullHeight` on Tabs does not reach this element.
 */
const useStyles = createStyles(({ css }) => ({
  tabs: css`
    height: 100%;
    min-height: 0;

    .ant-tabs-content {
      height: 100%;
    }

    .ant-tabs-tabpane {
      height: 100%;
      min-height: 0;
    }
  `
}))

interface OpenFile {
  path: string
  name: string
  dirty: boolean
}

/** True when `path` is the entry itself, or sits inside it when the entry is a directory. */
function isAtOrUnder (path: string, entryPath: string, isDirectory: boolean): boolean {
  return path === entryPath || (isDirectory && path.startsWith(`${entryPath}/`))
}

export const FileViewerWidget = (): React.JSX.Element => {
  const [openFiles, setOpenFiles] = useState<OpenFile[]>([])
  const [activePath, setActivePath] = useState<string | null>(null)

  const { styles } = useStyles()
  const { t } = useTranslation()

  const hasOpenFile = openFiles.length > 0

  // SplitLayout sizes are percentages and both items need one: given only the left size the
  // right pane keeps its default instead of claiming the remainder, which leaves dead space
  // on the right. Studio's own layouts always pass a complementary pair (e.g. 25 / 75).
  const leftSize = hasOpenFile ? 15 : 22

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
   * Closes the tabs of an entry the tree just deleted - including everything below it when a
   * whole folder went away, since those files no longer exist either.
   */
  const handleEntryRemoved = useCallback((removedPath: string, isDirectory: boolean): void => {
    setOpenFiles((current) => {
      const remaining = current.filter((file) => !isAtOrUnder(file.path, removedPath, isDirectory))

      if (remaining.length === current.length) return current

      setActivePath((active) => {
        if (active !== null && !isAtOrUnder(active, removedPath, isDirectory)) return active

        return remaining.length > 0 ? remaining[remaining.length - 1].path : null
      })

      return remaining
    })
  }, [])

  /**
   * Moves open tabs along with an entry the tree just renamed, rather than leaving them
   * pointing at a path that no longer resolves. Renaming a folder moves everything open
   * beneath it too. The pane refetches under the new path, so unsaved changes in a file that
   * is renamed while dirty are lost - renaming what you are in the middle of editing is an
   * odd thing to do, and guessing which of the two states to keep would be worse.
   */
  const handleEntryRenamed = useCallback((fromPath: string, entry: FileEntry): void => {
    const toPath = entry.path

    const moved = (path: string): string => {
      if (path === fromPath) return toPath

      return entry.isDirectory && path.startsWith(`${fromPath}/`)
        ? `${toPath}${path.slice(fromPath.length)}`
        : path
    }

    setOpenFiles((current) => current.map((file) => {
      const path = moved(file.path)

      if (path === file.path) return file

      // Only the renamed entry itself gets a new label; a file inside a renamed folder keeps
      // its own name and merely moves.
      return { ...file, path, name: path === toPath ? entry.name : file.name }
    }))

    setActivePath((active) => (active === null ? active : moved(active)))
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
        //
        // With nothing open the tree is the content, so it gets the room; once a file is
        // open the editor is what the user is looking at and the tree steps back.
        minSize: 200,
        maxSize: hasOpenFile ? 320 : 460,
        size: leftSize,
        children: (
          <FileTreePanel
            onEntryRemoved={ handleEntryRemoved }
            onEntryRenamed={ handleEntryRenamed }
            onFileOpen={ handleFileOpen }
            selectedPath={ activePath }
          />
        )
      } }
      resizeAble
      rightItem={ {
        minSize: 320,
        size: 100 - leftSize,
        children: !hasOpenFile
          ? (
            <Flex align="center" justify="center" style={ { height: '100%' } }>
              <Text type="secondary">{ t('scop-file-viewer.editor.empty-state') }</Text>
            </Flex>
            )
          : (
            <Tabs
              activeKey={ activePath ?? undefined }
              className={ styles.tabs }
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
