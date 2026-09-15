/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import React, { useCallback, useEffect, useMemo, useState } from 'react'
import type { Key } from 'react'
import { Alert, ContextMenuWrapper, Flex, Icon, IconButton, Menu, Spin, Text, TreeElement, useFormModal, useMessage } from '@pimcore/studio-ui-bundle/components'
import type { TreeDataItem } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import {
  useLazyScopFileViewerDirectoryQuery,
  useScopFileViewerCreateEntryMutation,
  useScopFileViewerDeleteEntryMutation,
  useScopFileViewerRenameEntryMutation
} from '../api/file-viewer-api'
import type { FileEntry } from '../types'
import { toErrorMessage, UNESCAPED } from '../utils/format'

/** Derived from the component rather than imported from antd, which is not a dependency here. */
type MenuItem = NonNullable<React.ComponentProps<typeof Menu>['items']>[number]

interface FileTreePanelProps {
  onFileOpen: (entry: FileEntry) => void
  /** The entry is gone from disk; a directory takes everything below it with it. */
  onEntryRemoved: (path: string, isDirectory: boolean) => void
  onEntryRenamed: (fromPath: string, entry: FileEntry) => void
  selectedPath: string | null
}

/**
 * The API addresses the configured root as an empty path, but antd needs a non-empty, unique
 * key for the node that represents it. Everything below works in tree keys and converts at
 * the API boundary.
 */
const ROOT_KEY = '__root__'

/**
 * Hoisted so the identity stays stable across renders. TreeElement does not merely seed its
 * expansion state from `defaultExpandedKeys` on mount - it re-applies the prop in an effect
 * keyed on the array itself, and then drives the tree's `expandedKeys` from that state. An
 * inline `[ROOT_KEY]` is a new array on every render, so every re-render of this panel reset
 * the expansion: opening a file changes `selectedPath`, which re-renders us, which collapsed
 * every folder the user had opened to reach that file.
 */
const DEFAULT_EXPANDED_KEYS: Key[] = [ROOT_KEY]

function toApiPath (key: string): string {
  return ROOT_KEY === key ? '' : key
}

/**
 * The tree key of the folder holding `key`. Entry paths are root-relative with "/" as the
 * separator, so a key without one sits directly in the root.
 */
function toParentKey (key: string): string {
  const separator = key.lastIndexOf('/')

  return separator === -1 ? ROOT_KEY : key.slice(0, separator)
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

export const FileTreePanel = ({ onFileOpen, onEntryRemoved, onEntryRenamed, selectedPath }: FileTreePanelProps): React.JSX.Element => {
  const [treeData, setTreeData] = useState<TreeDataItem[]>([])
  const [treeGeneration, setTreeGeneration] = useState(0)
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { t } = useTranslation()
  const modal = useFormModal()
  const message = useMessage()
  const [loadDirectory] = useLazyScopFileViewerDirectoryQuery()
  const [createEntry] = useScopFileViewerCreateEntryMutation()
  const [renameEntry] = useScopFileViewerRenameEntryMutation()
  const [deleteEntry] = useScopFileViewerDeleteEntryMutation()

  /**
   * Loads the root listing. Reloading drops every cached child branch and collapses the
   * tree, so directories are re-read from disk the next time they are expanded - that is
   * what makes this pick up files created outside of Studio.
   */
  const loadRoot = useCallback(async (): Promise<void> => {
    setLoaded(false)

    try {
      const listing = await loadDirectory({ path: '' }, false).unwrap()

      // A node for the root itself, so it can carry a context menu: without it there is no
      // place to right-click for creating something at the top level.
      setTreeData([{
        key: ROOT_KEY,
        // The icon goes inside the title rather than into antd's `icon` slot: that slot is
        // rendered as its own block here, which pushes the label onto a second line.
        title: (
          <Flex align="center" gap="mini">
            <Icon value="home-root-folder" />
            { t('scop-file-viewer.tree.root') }
          </Flex>
        ),
        isLeaf: false,
        children: listing.entries.map(toTreeNode),
        meta: { entry: { name: '', path: '', isDirectory: true, size: null, modified: 0, readable: true, writable: true } }
      }])
      setTreeGeneration((current) => current + 1)
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

    const listing = await loadDirectory({ path: toApiPath(key) }, false).unwrap()
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
      const listing = await loadDirectory({ path: toApiPath(key) }, false).unwrap()
      setTreeData((current) => withChildren(current, key, listing.entries.map(toTreeNode)))
    } catch (reloadError) {
      setError(toErrorMessage(reloadError, t('scop-file-viewer.tree.reload-error', {
        path: ROOT_KEY === key ? t('scop-file-viewer.tree.root') : key,
        ...UNESCAPED
      })))
    }
  }, [loadDirectory, t])

  /**
   * Asks for a name, creates the entry in `parentPath` and refreshes that folder so the new
   * entry appears without rebuilding the tree. A new file is opened straight away - creating
   * one is almost always the first half of editing it.
   */
  const promptForNewEntry = useCallback((parentKey: string, type: 'file' | 'directory'): void => {
    modal.input({
      title: t(type === 'file' ? 'scop-file-viewer.tree.new-file.title' : 'scop-file-viewer.tree.new-folder.title'),
      label: t('scop-file-viewer.tree.new.label'),
      okText: t('scop-file-viewer.tree.new.ok'),
      rule: { required: true, message: t('scop-file-viewer.tree.new.required') },
      onOk: async (name: string): Promise<void> => {
        try {
          const entry = await createEntry({ path: toApiPath(parentKey), name, type }).unwrap()

          await reloadFolder(parentKey)
          message.success(t('scop-file-viewer.tree.created', { path: entry.path, ...UNESCAPED }))

          if (!entry.isDirectory) {
            onFileOpen(entry)
          }
        } catch (createError) {
          message.error(toErrorMessage(createError, t('scop-file-viewer.tree.create-error', { name, ...UNESCAPED })))
        }
      }
    })
  }, [modal, t, createEntry, reloadFolder, message, onFileOpen])

  /**
   * Renames an entry in place and refreshes the folder it lives in. The editor is told
   * separately so a tab that has the file open follows it to the new path instead of
   * pointing at something that no longer exists.
   */
  const promptForRename = useCallback((key: string, entry: FileEntry): void => {
    modal.input({
      title: t('scop-file-viewer.tree.rename.title', { name: entry.name, ...UNESCAPED }),
      label: t('scop-file-viewer.tree.rename.label'),
      okText: t('scop-file-viewer.tree.rename.ok'),
      initialValue: entry.name,
      rule: { required: true, message: t('scop-file-viewer.tree.rename.required') },
      onOk: async (name: string): Promise<void> => {
        if (name === entry.name) return

        try {
          const renamed = await renameEntry({ path: entry.path, name }).unwrap()

          await reloadFolder(toParentKey(key))
          onEntryRenamed(entry.path, renamed)
          message.success(t('scop-file-viewer.tree.renamed', { from: entry.path, to: renamed.path, ...UNESCAPED }))
        } catch (renameError) {
          message.error(toErrorMessage(renameError, t('scop-file-viewer.tree.rename-error', { path: entry.path, ...UNESCAPED })))
        }
      }
    })
  }, [modal, t, renameEntry, reloadFolder, onEntryRenamed, message])

  /**
   * Deleting is irreversible and a folder takes its whole subtree with it, so it always goes
   * through a confirmation that names what is about to disappear.
   */
  const confirmDelete = useCallback((key: string, entry: FileEntry): void => {
    modal.confirm({
      title: t(entry.isDirectory
        ? 'scop-file-viewer.tree.delete.folder-title'
        : 'scop-file-viewer.tree.delete.file-title', { name: entry.name, ...UNESCAPED }),
      content: t(entry.isDirectory
        ? 'scop-file-viewer.tree.delete.folder-content'
        : 'scop-file-viewer.tree.delete.file-content', { path: entry.path, ...UNESCAPED }),
      okText: t('scop-file-viewer.tree.delete.ok'),
      cancelText: t('scop-file-viewer.tree.delete.cancel'),
      okButtonProps: { danger: true },
      onOk: async (): Promise<void> => {
        try {
          const deleted = await deleteEntry({ path: entry.path }).unwrap()

          await reloadFolder(toParentKey(key))
          onEntryRemoved(deleted.path, deleted.isDirectory)
          message.success(t('scop-file-viewer.tree.deleted', { path: deleted.path, ...UNESCAPED }))
        } catch (deleteError) {
          message.error(toErrorMessage(deleteError, t('scop-file-viewer.tree.delete-error', { path: entry.path, ...UNESCAPED })))
        }
      }
    })
  }, [modal, t, deleteEntry, reloadFolder, onEntryRemoved, message])

  /**
   * Every node gets a right-click menu: directories can be filled and reloaded, and anything
   * but the root can be renamed and deleted.
   */
  const renderTitle = useCallback((node: TreeDataItem, initialComponent: React.ReactElement): React.ReactNode => {
    const entry = node.meta?.entry as FileEntry | undefined

    if (entry === undefined) {
      return initialComponent
    }

    // Addressed by tree key rather than entry.path: the root's path is empty, which is a
    // valid API path but not a node key.
    const key = String(node.key)
    const isRoot = ROOT_KEY === key
    const items: MenuItem[] = []

    if (entry.isDirectory) {
      items.push(
        {
          key: 'new-file',
          label: t('scop-file-viewer.tree.new-file'),
          onClick: () => { promptForNewEntry(key, 'file') }
        },
        {
          key: 'new-folder',
          label: t('scop-file-viewer.tree.new-folder'),
          onClick: () => { promptForNewEntry(key, 'directory') }
        },
        { key: 'create-divider', type: 'divider' },
        {
          key: 'reload',
          label: t('scop-file-viewer.tree.reload-folder'),
          onClick: () => { void reloadFolder(key) }
        }
      )
    }

    // The root is the configured directory itself, not an entry inside it - renaming or
    // deleting it is not the viewer's business.
    if (!isRoot) {
      if (items.length > 0) {
        items.push({ key: 'modify-divider', type: 'divider' })
      }

      items.push(
        {
          key: 'rename',
          label: t('scop-file-viewer.tree.rename'),
          onClick: () => { promptForRename(key, entry) }
        },
        {
          key: 'delete',
          label: t('scop-file-viewer.tree.delete'),
          danger: true,
          onClick: () => { confirmDelete(key, entry) }
        }
      )
    }

    return (
      <ContextMenuWrapper
        renderMenu={ () => <Menu items={ items } /> }
      >
        { initialComponent }
      </ContextMenuWrapper>
    )
  }, [reloadFolder, promptForNewEntry, promptForRename, confirmDelete, t])

  // Same reasoning as DEFAULT_EXPANDED_KEYS: TreeElement mirrors this prop into state in an
  // effect keyed on the array, so a fresh array on every render means a pointless state write
  // on every render.
  const selectedKeys = useMemo<Key[]>(
    () => (selectedPath !== null ? [selectedPath] : []),
    [selectedPath]
  )

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
            // A full reload intentionally collapses the tree; remounting is what gets the
            // expansion state back to just the root, since DEFAULT_EXPANDED_KEYS never
            // changes identity on its own.
            key={ treeGeneration }
            defaultExpandedKeys={ DEFAULT_EXPANDED_KEYS }
            hasRoot
            onLoadData={ handleLoadData }
            onSelected={ handleSelect }
            selectedKeys={ selectedKeys }
            titleRender={ renderTitle }
            treeData={ treeData }
          />
        ) }
      </div>
    </Flex>
  )
}
