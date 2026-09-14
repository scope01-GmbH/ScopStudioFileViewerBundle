/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import React, { useMemo } from 'react'
import CodeMirror from '@uiw/react-codemirror'
import type { Extension } from '@codemirror/state'
import { EditorView } from '@codemirror/view'
import { oneDark } from '@codemirror/theme-one-dark'
import { createStyles } from '@pimcore/studio-ui-bundle/app'
import { getLanguageExtension } from '../utils/language'

interface CodeMirrorEditorProps {
  path: string
  value: string
  onChange: (value: string) => void
  readOnly: boolean
}

const useStyles = createStyles(({ token, css }) => ({
  editor: css`
    height: 100%;

    .cm-editor {
      height: 100%;
      font-size: 12px;
    }

    .cm-scroller {
      font-family: ${token.fontFamilyCode ?? 'monospace'};
      overflow: auto;
    }
  `
}))

/**
 * Studio's own TextEditor only speaks html/css/javascript/json/xml/sql/markdown, and its
 * CodeMirror is sealed inside the Studio remote (nothing is shared over module federation),
 * so no language can be added to it from the outside. This bundle therefore ships its own
 * self-contained CodeMirror, which is what makes PHP and YAML highlighting possible.
 *
 * It is loaded lazily (see FileEditorPane) so the cost is only paid once a file is opened.
 */
export const CodeMirrorEditor = ({ path, value, onChange, readOnly }: CodeMirrorEditorProps): React.JSX.Element => {
  const { styles, theme } = useStyles()

  // antd-style exposes the resolved token set rather than a light/dark flag, so the mode is
  // derived from the actual container background.
  const isDark = useMemo(() => isDarkColor(theme.colorBgContainer), [theme.colorBgContainer])

  const extensions = useMemo(() => {
    const collected: Extension[] = [EditorView.lineWrapping]
    const language = getLanguageExtension(path)

    if (language !== null) {
      collected.push(language)
    }

    return collected
  }, [path])

  return (
    <CodeMirror
      basicSetup={ {
        lineNumbers: true,
        highlightActiveLine: !readOnly,
        highlightActiveLineGutter: !readOnly,
        foldGutter: true,
        autocompletion: false
      } }
      className={ styles.editor }
      extensions={ extensions }
      height="100%"
      onChange={ onChange }
      readOnly={ readOnly }
      theme={ isDark ? oneDark : 'light' }
      value={ value }
    />
  )
}

/**
 * Relative luminance of a hex/rgb colour token; anything below the midpoint counts as dark.
 * Returns false for values that cannot be parsed, so the editor falls back to the light theme.
 */
function isDarkColor (color: string | undefined): boolean {
  if (color === undefined) return false

  let r: number, g: number, b: number

  const hex = color.trim().replace('#', '')

  if (/^[0-9a-f]{6}$/i.test(hex)) {
    r = parseInt(hex.slice(0, 2), 16)
    g = parseInt(hex.slice(2, 4), 16)
    b = parseInt(hex.slice(4, 6), 16)
  } else if (/^[0-9a-f]{3}$/i.test(hex)) {
    r = parseInt(hex[0] + hex[0], 16)
    g = parseInt(hex[1] + hex[1], 16)
    b = parseInt(hex[2] + hex[2], 16)
  } else {
    const match = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i.exec(color)
    if (match === null) return false
    r = Number(match[1]); g = Number(match[2]); b = Number(match[3])
  }

  return (0.2126 * r + 0.7152 * g + 0.0722 * b) < 128
}

export default CodeMirrorEditor
