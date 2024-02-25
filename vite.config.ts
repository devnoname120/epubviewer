import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.ts',
}, {
	config: {
		css: {
			modules: {
				localsConvention: 'camelCase',
			},
		},
	},
})
