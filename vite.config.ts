import path from 'node:path'
import { defineConfig } from 'vite'

export default defineConfig({
	css: {
		modules: {
			localsConvention: 'camelCase',
		},
	},
	build: {
		outDir: '.',
		emptyOutDir: false,
		sourcemap: true,
		rollupOptions: {
			input: {
				main: path.resolve(__dirname, 'src/main.ts'),
				settings: path.resolve(__dirname, 'src/settings.ts'),
				ready: path.resolve(__dirname, 'src/ready.ts'),
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
