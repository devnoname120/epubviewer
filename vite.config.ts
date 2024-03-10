import { createAppConfig } from '@nextcloud/vite-config';

export default createAppConfig(
  {
    main: 'src/main.ts',
    settings: 'src/settings.ts',
    ready: 'src/ready.ts',
    public: 'src/public.ts',
  },
  {
    emptyOutputDirectory: false,
    config: {
      css: {
        modules: {
          localsConvention: 'camelCase',
        },
      },
    },
  },
);
