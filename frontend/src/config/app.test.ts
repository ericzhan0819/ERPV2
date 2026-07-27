/// <reference types="node" />

import { readdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import * as ts from 'typescript'
import { describe, expect, it } from 'vitest'
import { createAppBrowserTitlePlugin } from '../../vite.config'
import { appConfig } from './app'
import type { AppConfig } from './app'

const frontendRoot = fileURLToPath(new URL('../../', import.meta.url))
const sourceRoot = fileURLToPath(new URL('../', import.meta.url))
const genericLengthThreshold = 6

function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = `${directory}/${entry.name}`

    if (entry.isDirectory()) return sourceFiles(path)
    return /\.(?:ts|tsx)$/.test(entry.name) ? [path] : []
  })
}

function parseSource(path: string): ts.SourceFile {
  return ts.createSourceFile(
    path,
    readFileSync(path, 'utf8'),
    ts.ScriptTarget.Latest,
    true,
    path.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  )
}

function appConfigProperties(path: string): Set<string> {
  const properties = new Set<string>()

  function visit(node: ts.Node) {
    if (
      ts.isPropertyAccessExpression(node) &&
      ts.isIdentifier(node.expression) &&
      node.expression.text === 'appConfig'
    ) {
      properties.add(node.name.text)
    }
    ts.forEachChild(node, visit)
  }

  visit(parseSource(path))
  return properties
}

function officialStringInLiteral(
  text: string,
  config: AppConfig = appConfig,
): string | undefined {
  const normalizedText = text.trim()

  return Object.values(config).find((officialString) => {
    // 過短的識別字串容易出現在一般文案中，只做完全比對避免誤報。
    const useExactMatch =
      Array.from(officialString).length < genericLengthThreshold

    return useExactMatch
      ? normalizedText === officialString
      : normalizedText.includes(officialString)
  })
}

describe('app config source contract', () => {
  it('keeps official presentation strings in app.ts only', () => {
    const configPath = `${sourceRoot}/config/app.ts`

    for (const path of sourceFiles(sourceRoot)) {
      if (path === configPath) continue

      function visit(node: ts.Node) {
        if (ts.isStringLiteralLike(node) || ts.isJsxText(node)) {
          const officialString = officialStringInLiteral(node.text)

          if (officialString) {
            throw new Error(`${path} 不可硬編碼「${officialString}」`)
          }
        }
        ts.forEachChild(node, visit)
      }

      visit(parseSource(path))
    }

    const indexHtml = readFileSync(`${frontendRoot}/index.html`, 'utf8')
    for (const officialString of new Set(Object.values(appConfig))) {
      expect(
        indexHtml,
        `index.html 不可硬編碼「${officialString}」`,
      ).not.toContain(officialString)
    }
  })

  it('detects identity strings with suffixes without flagging company prose', () => {
    expect(
      officialStringInLiteral(`${appConfig.systemShortName} 管理後台`),
    ).toBe(appConfig.systemShortName)
    expect(officialStringInLiteral(appConfig.companyName)).toBe(
      appConfig.companyName,
    )
    expect(
      officialStringInLiteral(`${appConfig.companyName}保留一般營運文案`),
    ).toBeUndefined()

    const dealershipConfig = {
      ...appConfig,
      companyName: '永順中古車行',
    }
    expect(
      officialStringInLiteral(
        `${dealershipConfig.companyName} 管理後台`,
        dealershipConfig,
      ),
    ).toBe(dealershipConfig.companyName)
  })

  it('keeps every official presentation consumer connected to app config', () => {
    const loginProperties = appConfigProperties(`${sourceRoot}/pages/Login.tsx`)

    expect(loginProperties).toContain('systemName')
    expect(loginProperties).toContain('loginSubtitle')
    expect(
      appConfigProperties(`${sourceRoot}/layouts/AppLayout.tsx`),
    ).toContain('systemShortName')
    expect(
      readFileSync(`${sourceRoot}/main.tsx`, 'utf8'),
    ).toMatch(
      /document\s*\.\s*title\s*=\s*appConfig\s*\.\s*browserTitle/,
    )
    expect(appConfigProperties(`${frontendRoot}/vite.config.ts`)).toContain(
      'browserTitle',
    )
  })

  it('injects and escapes browser titles without interpreting dollar patterns', () => {
    const browserTitle = "A$&B $'C $`D $$E <車行>&"
    const plugin = createAppBrowserTitlePlugin(browserTitle)
    const html = '<html><head><title>ERPV2</title></head><body></body></html>'

    expect(plugin.transformIndexHtml(html)).toBe(
      "<html><head><title>A$&amp;B $'C $`D $$E &lt;車行&gt;&amp;</title></head><body></body></html>",
    )
  })

  it('fails explicitly when the browser title fallback is missing', () => {
    const plugin = createAppBrowserTitlePlugin(appConfig.browserTitle)

    expect(() =>
      plugin.transformIndexHtml('<html><head></head><body></body></html>'),
    ).toThrow('找不到 ERPV2 browser title fallback')
  })
})
