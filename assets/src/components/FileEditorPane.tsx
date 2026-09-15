/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import React, { Suspense, lazy, useCallback, useEffect, useMemo, useState } from 'react'
import { Alert, Button, Flex, Spin, Text, useMessage } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import {
  getDownloadUrl,
  useScopFileViewerFileQuery,
  useScopFileViewerWriteFileMutation
} from '../api/file-viewer-api'
import { formatBytes, toErrorMessage, UNESCAPED } from '../utils/format'
import { getLanguageLabel } from '../utils/language'

// CodeMirror and its language packages are the bulk of this bundle. Loading them on demand
// keeps the remote that Studio pulls in at startup small.
const CodeMirrorEditor = lazy(async () => await import('./CodeMirrorEditor'))

interface FileEditorPaneProps {
  path: string
  onDirtyChange: (path: string, dirty: boolean) => void
}

export const FileEditorPane = ({ path, onDirtyChange }: FileEditorPaneProps): React.JSX.Element => {
  // Only set once the user asks for the tail of an oversized file, so that opening a 2 GB
  // log never transfers anything until it is explicitly requested.
  const [showTail, setShowTail] = useState(false)
  const [draft, setDraft] = useState<string | null>(null)

  const message = useMessage()
  const { t } = useTranslation()
  const { data, error, isFetching } = useScopFileViewerFileQuery({ path, tail: showTail })
  const [writeFile, { isLoading: isSaving }] = useScopFileViewerWriteFileMutation()

  const languageLabel = useMemo(() => getLanguageLabel(path), [path])

  // Reset the local draft whenever the server content changes (first load, tail load, save).
  useEffect(() => {
    setDraft(data?.content ?? null)
  }, [data?.content])

  const isDirty = draft !== null && data?.content !== undefined && draft !== data.content

  useEffect(() => {
    onDirtyChange(path, isDirty)
  }, [path, isDirty, onDirtyChange])

  const handleSave = useCallback(async (): Promise<void> => {
    if (draft === null) return

    try {
      await writeFile({ path, content: draft }).unwrap()
      message.success(t('scop-file-viewer.editor.save-success', { path, ...UNESCAPED }))
    } catch (saveError) {
      message.error(toErrorMessage(saveError, t('scop-file-viewer.editor.save-error', { path, ...UNESCAPED })))
    }
  }, [draft, path, writeFile, message, t])

  const downloadButton = (
    <Button href={ getDownloadUrl(path) } target="_blank">
      { t('scop-file-viewer.editor.download') }
    </Button>
  )

  if (isFetching && data === undefined) {
    return <Flex align="center" justify="center" style={ { height: '100%' } }><Spin /></Flex>
  }

  if (error !== undefined && error !== null) {
    return (
      <div style={ { padding: 16 } }>
        <Alert message={ toErrorMessage(error, t('scop-file-viewer.editor.open-error', { path, ...UNESCAPED })) } showIcon type="error" />
      </div>
    )
  }

  if (data === undefined) {
    return <div style={ { padding: 16 } }><Text type="secondary">{ t('scop-file-viewer.editor.nothing-to-show') }</Text></div>
  }

  if (data.status === 'binary') {
    return (
      <div style={ { padding: 16 } }>
        <Alert
          action={ downloadButton }
          description={ t('scop-file-viewer.editor.binary.description', { path, size: formatBytes(data.size), ...UNESCAPED }) }
          message={ t('scop-file-viewer.editor.binary.title') }
          showIcon
          type="info"
        />
      </div>
    )
  }

  // An oversized file is never loaded automatically: that is the whole point of the size
  // guard, and rendering a multi-hundred-megabyte log would lock up the browser tab.
  if (data.status === 'too_large' && !showTail) {
    return (
      <div style={ { padding: 16 } }>
        <Alert
          action={
            <Flex gap="mini">
              { downloadButton }
              <Button onClick={ () => { setShowTail(true) } } type="primary">
                { t('scop-file-viewer.editor.too-large.show-tail', { size: formatBytes(data.tailBytes) }) }
              </Button>
            </Flex>
          }
          description={ t('scop-file-viewer.editor.too-large.description', {
            path,
            size: formatBytes(data.size),
            limit: formatBytes(data.maxEditableSize),
            ...UNESCAPED
          }) }
          message={ t('scop-file-viewer.editor.too-large.title') }
          showIcon
          type="warning"
        />
      </div>
    )
  }

  const isTail = data.truncated
  const canEdit = data.editable && !isTail

  return (
    <Flex vertical style={ { height: '100%', overflow: 'hidden' } }>
      <Flex align="center" gap="small" justify="space-between" style={ { padding: '6px 12px' } }>
        <Text ellipsis title={ path } type="secondary">
          { path } · { formatBytes(data.size) }
          { languageLabel !== null ? ` · ${languageLabel}` : '' }
          { isDirty ? ` · ${t('scop-file-viewer.editor.unsaved-changes')}` : '' }
        </Text>

        <Flex align="center" gap="mini">
          { downloadButton }
          { canEdit && (
            <Button
              disabled={ !isDirty || isSaving }
              loading={ isSaving }
              onClick={ () => { void handleSave() } }
              type="primary"
            >
              { t('scop-file-viewer.editor.save') }
            </Button>
          ) }
        </Flex>
      </Flex>

      { isTail && (
        <div style={ { padding: '0 12px 8px' } }>
          <Alert
            message={ t('scop-file-viewer.editor.tail-notice', { size: formatBytes(data.tailBytes) }) }
            showIcon
            type="warning"
          />
        </div>
      ) }

      { !isTail && !data.editable && (
        <div style={ { padding: '0 12px 8px' } }>
          <Alert message={ t('scop-file-viewer.editor.read-only') } showIcon type="info" />
        </div>
      ) }

      <div style={ { flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', overflow: 'hidden', padding: '0 12px 12px' } }>
        <Suspense fallback={ <Flex align="center" justify="center" style={ { height: '100%' } }><Spin /></Flex> }>
          <CodeMirrorEditor
            onChange={ (value: string) => { if (canEdit) setDraft(value) } }
            path={ path }
            readOnly={ !canEdit }
            value={ draft ?? '' }
          />
        </Suspense>
      </div>
    </Flex>
  )
}
