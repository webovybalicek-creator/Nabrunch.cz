import { defineConfig } from 'astro/config';

export default defineConfig({
  site: 'https://nabrunch.cz',
  trailingSlash: 'always',
  build: {
    format: 'directory',
    inlineStylesheets: 'auto',
  },
});
