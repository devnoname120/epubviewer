import { copyFileSync, mkdirSync, rmSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const assets = path.join(root, 'assets')
if (path.dirname(assets) !== root || path.basename(assets) !== 'assets') {
	throw new Error('Refusing to clean an unexpected build output path.')
}
rmSync(assets, { force: true, recursive: true })
mkdirSync(assets)
copyFileSync(path.join(root, 'src/vendor/libarchive/COPYING'), path.join(assets, 'libarchive-COPYING.txt'))
copyFileSync(path.join(root, 'src/vendor/libarchive/LICENSE-EMSCRIPTEN.txt'), path.join(assets, 'emscripten-LICENSE.txt'))
