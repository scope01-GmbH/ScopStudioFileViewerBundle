import { defineConfig } from '@rsbuild/core'
import { pluginReact } from '@rsbuild/plugin-react'
import { pluginModuleFederation } from '@module-federation/rsbuild-plugin'
import { pluginGenerateEntrypoints } from '@pimcore/studio-ui-bundle/rsbuild/plugins'
import { createDynamicRemote } from '@pimcore/studio-ui-bundle/rsbuild/utils'
import path from 'path'
import fs from 'fs'
import crypto from 'crypto'

const repoRoot = path.resolve(__dirname, '..')

/**
 * The build id names the output directory, the public asset prefix and the shipped archive,
 * so it has to change whenever the emitted assets change - and only then. Deriving it from a
 * hash of the sources (rather than a random uuid) keeps an unchanged frontend from producing
 * a new archive on every build, which would otherwise show up as noise in every commit.
 */
function collectSourceFiles (dir: string, files: string[] = []): string[] {
  const ignored = new Set(['node_modules', 'dist', '.rsbuild', '@mf-types'])

  let entries: fs.Dirent[]
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true })
  } catch {
    return files
  }

  for (const entry of entries) {
    if (ignored.has(entry.name)) continue

    const full = path.resolve(dir, entry.name)
    if (entry.isDirectory()) {
      collectSourceFiles(full, files)
    } else if (entry.isFile()) {
      files.push(full)
    }
  }

  return files
}

function computeBuildId (): string {
  const hash = crypto.createHash('sha256')
  const files = collectSourceFiles(__dirname).filter((file) => fs.existsSync(file)).sort()

  for (const file of files) {
    // Hash the repo-relative path so the id does not depend on the checkout location.
    hash.update(`${path.relative(repoRoot, file).split(path.sep).join('/')}\0`)
    hash.update(fs.readFileSync(file))
  }

  return hash.digest('hex').slice(0, 32)
}

const nodeEnv = process.env.NODE_ENV
const isDevServer = nodeEnv === 'dev-server'
const env: 'development' | 'production' = nodeEnv === 'production' ? 'production' : 'development'
const buildId = process.env.PIMCORE_BUILD_ID ?? (isDevServer ? 'dev' : computeBuildId())

// `npm run build` packs this directory into src/Resources/build-dist/build-<id>.zip; Studio's
// BuildArchiveExtractor unpacks it again at cache warmup. The expanded build is not committed.
const studioDir = path.resolve(repoRoot, 'src', 'Resources', 'public', 'studio')
const buildPath = path.resolve(studioDir, buildId)

// Drop stale build directories but keep the current one, so an unchanged rebuild re-emits
// byte-identical assets instead of churning the archive.
if (fs.existsSync(studioDir)) {
  fs.readdirSync(studioDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && entry.name !== buildId)
    .forEach((entry) => fs.rmSync(path.resolve(studioDir, entry.name), { recursive: true, force: true }))
}
fs.mkdirSync(buildPath, { recursive: true })

/**
 * studio-package-build expects a `.build-id` file in the output directory. It is written
 * after the build so a cleaning bundler pass cannot drop it again.
 */
const pluginWriteBuildId = {
  name: 'write-build-id',
  setup (api: { onAfterBuild: (fn: () => void) => void }): void {
    api.onAfterBuild(() => {
      fs.writeFileSync(path.join(buildPath, '.build-id'), `${buildId}\n`)
    })
  }
}

// Symfony serves bundle assets under the lowercased bundle name without the "Bundle" suffix.
const assetPrefix = '/bundles/scopstudiofileviewer/studio/' + buildId

export default defineConfig({
  mode: env,
  server: {
    port: 3033
  },
  dev: {
    ...(!isDevServer ? { assetPrefix } : {}),
    client: {
      host: 'localhost',
      port: 3033,
      protocol: 'ws'
    }
  },
  source: {
    entry: {
      main: './src/main.ts'
    },
    decorators: {
      version: 'legacy'
    }
  },
  output: {
    manifest: true,
    assetPrefix,
    distPath: {
      root: buildPath
    }
  },
  tools: {
    bundlerChain: (chain) => {
      chain.output.uniqueName('scop_studio_file_viewer_bundle')
    }
  },
  plugins: [
    pluginWriteBuildId,
    pluginGenerateEntrypoints(),
    pluginReact(),
    pluginModuleFederation({
      name: 'scop_studio_file_viewer_bundle',
      filename: 'static/js/remoteEntry.js',
      exposes: {
        '.': './src/plugin.ts'
      },
      dts: false,
      remotes: {
        // Studio core is loaded at runtime from the host page, never bundled into this build.
        '@pimcore/studio-ui-bundle': createDynamicRemote('pimcore_studio_ui_bundle')
      },
      // Deliberately explicit rather than spreading every dependency: Studio shares no
      // modules over module federation, so anything listed here has no counterpart to join.
      // CodeMirror in particular must stay bundled in this remote - a second, federated copy
      // would break extension identity ("Unrecognized extension value").
      shared: {
        react: {
          singleton: true,
          eager: true,
          requiredVersion: false
        },
        'react-dom': {
          singleton: true,
          eager: true,
          requiredVersion: false
        }
      }
    })
  ]
})
