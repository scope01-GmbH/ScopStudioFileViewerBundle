/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import React, { useCallback, useEffect, useState } from 'react'
import type { Key } from 'react'
import { Alert, ContextMenuWrapper, Flex, IconButton, Menu, Spin, Text, TreeElement, useFormModal, useMessage } from '@pimcore/studio-ui-bundle/components'
import type { TreeDataItem } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { useLazyScopFileViewerDirectoryQuery, useScopFileViewerCreateEntryMutation } from '../api/file-viewer-api'
import type { FileEntry } from '../types'
import { toErrorMessage } from '../utils/format'

interface FileTreePanelProps {
  onFileOpen: (entry: FileEntry) => void
  selectedPath: string | null
}

function toTreeNode (entry: FileEntry): TreeDataItem {
  return {
    key: entry.path,
    title: entry.name,
    isLeaf: !entry.isDirectory,
    meta: { entry }
  }
}

/**
 * Replaces the children of the node with `key` anywhere in the tree.
 *
 * Rebuilding only the branch that changed (rather than refetching the whole tree) is what
 * keeps expanding a deep directory cheap, and it preserves every other expanded branch.
 */
function withChildren (nodes: TreeDataItem[], key: string, children: TreeDataItem[]): TreeDataItem[] {
  return nodes.map((node) => {
    if (node.key === key) {
      return { ...node, children }
    }

    if (node.children !== undefined && node.children.length > 0) {
      return { ...node, children: withChildren(node.children as TreeDataItem[], key, children) }
    }

    return node
  })
}

export const FileTreePanel = ({ onFileOpen, selectedPath }: FileTreePanelProps): React.JSX.Element => {
  const [treeData, setTreeData] = useState<TreeDataItem[]>([])
  const [expandedKeys, setExpandedKeys] = useState<Key[]>([])
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { t } = useTranslation()
  const modal = useFormModal()
  const message = useMessage()
  const [loadDirectory] = useLazyScopFileViewerDirectoryQuery()
  const [createEntry] = useScopFileViewerCreateEntryMutation()

  /**
   * Loads the root listing. Reloading drops every cached child branch and collapses the
   * tree, so directories are re-read from disk the next time they are expanded - that is
   * what makes this pick up files created outside of Studio.
   */
  const loadRoot = useCallback(async (): Promise<void> => {
    setLoaded(false)

    try {
      const listing = await loadDirectory({ path: '' }, false).unwrap()
      setTreeData(listing.entries.map(toTreeNode))
      setExpandedKeys([])
      setError(null)
    } catch (loadError) {
      setError(toErrorMessage(loadError, t('scop-file-viewer.tree.load-error')))
    } finally {
      setLoaded(true)
    }
  }, [loadDirectory, t])

  useEffect(() => {
    void loadRoot()
  }, [loadRoot])

  const handleLoadData = useCallback(async (node: TreeDataItem): Promise<void> => {
    const key = String(node.key)

    // antd calls onLoadData again for a node whose children array is empty, which would
    // re-request every empty directory on each expand. Loading it once is enough.
    if (node.children !== undefined) return

    const listing = await loadDirectory({ path: key }, false).unwrap()
    setTreeData((current) => withChildren(current, key, listing.entries.map(toTreeNode)))
  }, [loadDirectory])

  const handleSelect = useCallback((_key: unknown, node: TreeDataItem): void => {
    const entry = node.meta?.entry as FileEntry | undefined

    if (entry !== undefined && !entry.isDirectory) {
      onFileOpen(entry)
    }
  }, [onFileOpen])

  /**
   * Re-reads a single directory and swaps its children in place, leaving the rest of the
   * tree and the expanded state untouched - the point of a per-folder reload is not having
   * to rebuild the whole tree to pick up one new file.
   */
  const reloadFolder = useCallback(async (key: string): Promise<void> => {
    try {
      const listing = await loadDirectory({ path: key }, false).unwrap()
      setTreeData((current) => withChildren(current, key, listing.entries.map(toTreeNode)))
      setExpandedKeys((current) => (current.includes(key) ? current : [...current, key]))
    } catch (reloadError) {
      setError(toErrorMessage(reloadError, t('scop-file-viewer.tree.reload-error', { path: key })))
    }
  }, [loadDirectory, t])

  /**
   * Asks for a name, creates the entry in `parentPath` and refreshes that folder so the new
   * entry appears without rebuilding the tree. A new file is opened straight away - creating
   * one is almost always the first half of editing it.
   */
  const promptForNewEntry = useCallback((parentPath: string, type: 'file' | 'directory'): void => {
    modal.input({
      title: t(type === 'file' ? 'scop-file-viewer.tree.new-file.title' : 'scop-file-viewer.tree.new-folder.title'),
      label: t('scop-file-viewer.tree.new.label'),
      okText: t('scop-file-viewer.tree.new.ok'),
      rule: { required: true, message: t('scop-file-viewer.tree.new.required') },
      onOk: async (name: string): Promise<void> => {
        try {
          const entry = await createEntry({ path: parentPath, name, type }).unwrap()

          await reloadFolder(parentPath)
          message.success(t('scop-file-viewer.tree.created', { path: entry.path }))

          if (!entry.isDirectory) {
            onFileOpen(entry)
          }
        } catch (createError) {
          message.error(toErrorMessage(createError, t('scop-file-viewer.tree.create-error', { name })))
        }
      }
    })
  }, [modal, t, createEntry, reloadFolder, message, onFileOpen])

  /**
   * Directories get a right-click menu; files keep the default title so a right-click there
   * falls through to the browser menu.
   */
  const renderTitle = useCallback((node: TreeDataItem, initialComponent: React.ReactElement): React.ReactNode => {
    const entry = node.meta?.entry as FileEntry | undefined

    if (entry === undefined || !entry.isDirectory) {
      return initialComponent
    }

    return (
      <ContextMenuWrapper
        renderMenu={ () => (
          <Menu
            items={ [
              {
                key: 'new-file',
                label: t('scop-file-viewer.tree.new-file'),
                onClick: () => { promptForNewEntry(entry.path, 'file') }
              },
              {
                key: 'new-folder',
                label: t('scop-file-viewer.tree.new-folder'),
                onClick: () => { promptForNewEntry(entry.path, 'directory') }
              },
              { key: 'create-divider', type: 'divider' },
              {
                key: 'reload',
                label: t('scop-file-viewer.tree.reload-folder'),
                onClick: () => { void reloadFolder(entry.path) }
              }
            ] }
          />
        ) }
      >
        { initialComponent }
      </ContextMenuWrapper>
    )
  }, [reloadFolder, promptForNewEntry, t])

  return (
    <Flex vertical gap="mini" style={ { height: '100%', overflow: 'hidden', padding: 8 } }>
      <Flex align="center" gap="mini" justify="space-between">
        <Text type="secondary">{ t('scop-file-viewer.tree.title') }</Text>
        <IconButton
          disabled={ !loaded }
          icon={ { value: 'refresh' } }
          onClick={ () => { void loadRoot() } }
          tooltip={ { title: t('scop-file-viewer.tree.reload') } }
          variant="minimal"
        />
      </Flex>

      { error !== null && <Alert message={ error } showIcon type="error" /> }

      <div style={ { flex: 1, minHeight: 0, overflow: 'auto' } }>
        { !loaded && <Spin /> }
        { loaded && error === null && (
          <TreeElement
            expandedKeys={ expandedKeys }
            onExpand={ (keys: Key[]) => { setExpandedKeys(keys) } }
            onLoadData={ handleLoadData }
            onSelected={ handleSelect }
            selectedKeys={ selectedPath !== null ? [selectedPath] : [] }
            titleRender={ renderTitle }
            treeData={ treeData }
          />
        ) }
      </div>
    </Flex>
  )
}
