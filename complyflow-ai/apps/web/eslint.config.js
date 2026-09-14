import js from '@eslint/js'
import ts from 'typescript-eslint'
import vue from 'eslint-plugin-vue'
import globals from 'globals'

export default ts.config(
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended,
  ...ts.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.{ts,vue,js,mjs}'],
    languageOptions: { globals: { ...globals.browser, ...globals.node }, parserOptions: { parser: ts.parser } },
    rules: {
      'vue/multi-word-component-names': ['error', { ignores: ['App'] }],
      'vue/html-self-closing': ['error', { html: { void: 'always', normal: 'never', component: 'always' } }],
    },
  },
)
