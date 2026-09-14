/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

import type { Extension } from '@codemirror/state'
import { css } from '@codemirror/lang-css'
import { html } from '@codemirror/lang-html'
import { javascript } from '@codemirror/lang-javascript'
import { json } from '@codemirror/lang-json'
import { markdown } from '@codemirror/lang-markdown'
import { php } from '@codemirror/lang-php'
import { sql } from '@codemirror/lang-sql'
import { xml } from '@codemirror/lang-xml'
import { yaml } from '@codemirror/lang-yaml'

/**
 * These extensions come from this bundle's own CodeMirror, which is also the one that renders
 * the editor. Studio's CodeMirror is a separate copy that shares nothing over module
 * federation, so its language helpers must never be mixed in here - CodeMirror resolves
 * extensions by facet identity and would reject them at runtime.
 */
type LanguageFactory = () => Extension

const BY_EXTENSION: Record<string, LanguageFactory> = {
  // PHP files usually open with `<?php` but may contain markup, which the default
  // HTML-aware PHP parser handles in both directions.
  php: php,
  phtml: php,
  inc: php,

  yaml: yaml,
  yml: yaml,

  js: () => javascript(),
  cjs: () => javascript(),
  mjs: () => javascript(),
  jsx: () => javascript({ jsx: true }),
  ts: () => javascript({ typescript: true }),
  tsx: () => javascript({ jsx: true, typescript: true }),

  json: json,
  lock: json,
  map: json,

  html: html,
  htm: html,
  twig: html,

  css: css,
  scss: css,
  less: css,

  xml: xml,
  xsd: xml,
  xsl: xml,
  svg: xml,

  sql: sql,
  md: markdown,
  markdown: markdown
}

/** Filenames without a useful extension that still have a known syntax. */
const BY_FILENAME: Record<string, LanguageFactory> = {
  'composer.lock': json,
  'package-lock.json': json,
  '.htaccess': () => xml()
}

export function getLanguageExtension (path: string): Extension | null {
  const filename = (path.split('/').pop() ?? path).toLowerCase()

  const byFilename = BY_FILENAME[filename]
  if (byFilename !== undefined) return byFilename()

  const dotIndex = filename.lastIndexOf('.')
  if (dotIndex <= 0) return null

  const factory = BY_EXTENSION[filename.slice(dotIndex + 1)]

  return factory !== undefined ? factory() : null
}

/** Human readable label for the status bar, e.g. "PHP". */
export function getLanguageLabel (path: string): string | null {
  const filename = (path.split('/').pop() ?? path).toLowerCase()
  const dotIndex = filename.lastIndexOf('.')

  if (BY_FILENAME[filename] !== undefined) return 'JSON'
  if (dotIndex <= 0) return null

  const extension = filename.slice(dotIndex + 1)

  return BY_EXTENSION[extension] !== undefined ? extension.toUpperCase() : null
}
