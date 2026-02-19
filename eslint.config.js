import tsParser from '@typescript-eslint/parser'

export default [
	{
		ignores: [
			'js/**',
			'node_modules/**',
			'nextcloud-server/**',
			'nextcloud-docker-dev/**',
			'vendor/**',
		],
	},
	{
		files: ['src/**/*.{js,ts,vue}'],
		languageOptions: {
			parser: tsParser,
			ecmaVersion: 2022,
			sourceType: 'module',
		},
		rules: {},
	},
]
