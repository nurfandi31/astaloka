import { defineConfig, globalIgnores } from 'eslint/config'
import globals from 'globals'
import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import pluginOxlint from 'eslint-plugin-oxlint'
import skipFormatting from 'eslint-config-prettier/flat'

export default defineConfig([
  {
    name: 'app/files-to-lint',
    files: ['**/*.{vue,js,mjs,jsx}'],
  },

  globalIgnores(['**/dist/**', '**/dist-ssr/**', '**/coverage/**', '**/clean_views.cjs']),

  {
    languageOptions: {
      globals: {
        ...globals.browser,
      },
    },
  },

  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],

  {
    files: ['**/registrasi.vue'],
    rules: {
      'vue/multi-word-component-names': 'off',
    },
  },

  // File build/config berjalan di Node, bukan browser. Tanpa ini `process` di
  // `vite.config.js` dianggap undefined dan kena `no-undef`.
  {
    name: 'node-files',
    files: ['vite.config.js', 'vite.config.mjs', 'eslint.config.js', 'tailwind.config.js'],
    languageOptions: {
      globals: {
        ...globals.node,
      },
    },
  },

  // Harness test dan skrip pemeriksaan juga berjalan di Node.
  //
  // Tanpa ini `Buffer` di `test/loaderSfc.mjs` dan `process` di
  // `test/vueHarness.mjs` + `scripts/check-text.mjs` kena `no-undef` --
  // padahal ketiganya memang kode Node, bukan browser. Globals Node
  // yang dipakai di sini sengaja DAHULU tidak dideklarasikan karena
  // `scripts/` ikut di-lint bersama `src/`, dan `src/` tidak boleh
  // memakai `process`.
  {
    name: 'node-test-files',
    files: ['test/**/*.js', 'test/**/*.mjs', 'scripts/**/*.js', 'scripts/**/*.mjs'],
    languageOptions: {
      globals: {
        ...globals.node,
      },
    },
  },

  ...pluginOxlint.buildFromOxlintConfigFile('.oxlintrc.json'),

  skipFormatting,
])
