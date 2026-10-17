import path from 'node:path'
import { defineConfig } from 'vite'

export default defineConfig({
	base: './',
	css: {
		modules: {
			localsConvention: 'camelCase',
		},
	},
	worker: {
		format: 'es',
		rollupOptions: {
			output: {
					entryFileNames: 'js/epubviewer-rar-worker-[hash].mjs',
			},
		},
	},
	build: {
		outDir: '.',
		emptyOutDir: false,
		sourcemap: true,
		rollupOptions: {
			preserveEntrySignatures: 'strict',
			input: {
				'epub-highlights': path.resolve(import.meta.dirname, 'src/epubHighlights/index.js'),
				main: path.resolve(import.meta.dirname, 'src/main.ts'),
				rar: path.resolve(import.meta.dirname, 'src/rar.ts'),
				settings: path.resolve(import.meta.dirname, 'src/settings.ts'),
				ready: path.resolve(import.meta.dirname, 'src/ready.ts'),
			},
			output: {
				entryFileNames: 'js/epubviewer-[name].mjs',
				chunkFileNames: 'js/[name]-[hash].chunk.mjs',
				assetFileNames: (assetInfo) => {
					const name = assetInfo.names?.[0] ?? ''
					if (name === 'settings.css') {
						return 'css/epubviewer-settings.css'
					}
					if (name.endsWith('.css')) {
						return 'css/[name]-[hash][extname]'
					}
					return 'js/[name]-[hash][extname]'
				},
			},
		},
	},
})
