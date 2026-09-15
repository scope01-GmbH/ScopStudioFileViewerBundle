/*
 * Scop Studio File Viewer Bundle
 *
 * This source file is available under the MIT license.
 * Full copyright and license information is available in LICENSE.
 *
 * @copyright Copyright (c) scope01 GmbH (https://scope01.com)
 * @license   MIT
 */

/**
 * i18next escapes interpolated values by default, which is meant for translations rendered as
 * raw HTML. Ours are rendered as text by React, which escapes on its own, so the default only
 * corrupts what it touches: a path comes out as "var&#x2F;log" instead of "var/log".
 */
export const UNESCAPED = { interpolation: { escapeValue: false } } as const

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB']

export function formatBytes (bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined) return ''
  if (bytes < 1024) return `${bytes} B`

  let value = bytes
  let unit = 0

  while (value >= 1024 && unit < UNITS.length - 1) {
    value /= 1024
    unit++
  }

  return `${value.toFixed(value < 10 ? 1 : 0)} ${UNITS[unit]}`
}

/** Extracts the error message the PHP controllers return as `{ error: string }`. */
export function toErrorMessage (error: unknown, fallback: string): string {
  if (typeof error === 'object' && error !== null && 'data' in error) {
    const data = (error as { data?: unknown }).data

    if (typeof data === 'object' && data !== null && 'error' in data) {
      const message = (data as { error?: unknown }).error
      if (typeof message === 'string' && message !== '') return message
    }
  }

  return fallback
}
