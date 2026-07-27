/// <reference types="node" />

import { readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { appConfig } from './app'

const frontendRoot = fileURLToPath(new URL('../../', import.meta.url))
const sourceRoot = fileURLToPath(new URL('../', import.meta.url))

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = `${directory}/${entry.name}`

    if (entry.isDirectory()) return sourceFiles(path)
    return /\.(?:ts|tsx)$/.test(entry.name) ? [path] : []
  })
}

describe('app config source contract', () => {
  it('keeps official presentation strings in app.ts only', () => {
    const configPath = `${sourceRoot}/config/app.ts`
    const officialStrings = new Set(Object.values(appConfig))

    for (const path of sourceFiles(sourceRoot)) {
      if (path === configPath) continue

      const source = readFileSync(path, 'utf8')
      for (const value of officialStrings) {
        expect(source, `${path} 不可硬編碼「${value}」`).not.toContain(value)
      }
    }

    expect(readFileSync(`${frontendRoot}/index.html`, 'utf8')).not.toContain(
      appConfig.browserTitle,
    )
  })

  it('keeps every official presentation consumer connected to app config', () => {
    expect(readFileSync(`${sourceRoot}/pages/Login.tsx`, 'utf8')).toContain(
      '{appConfig.systemName}',
    )
    expect(readFileSync(`${sourceRoot}/pages/Login.tsx`, 'utf8')).toContain(
      '{appConfig.loginSubtitle}',
    )
    expect(readFileSync(`${sourceRoot}/layouts/AppLayout.tsx`, 'utf8')).toContain(
      '{appConfig.systemShortName}',
    )
    expect(readFileSync(`${sourceRoot}/main.tsx`, 'utf8')).toContain(
      'document.title = appConfig.browserTitle',
    )
    expect(readFileSync(`${frontendRoot}/vite.config.ts`, 'utf8')).toContain(
      'escapeHtmlText(appConfig.browserTitle)',
    )
  })
})
