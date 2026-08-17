// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt(
  {
    rules: {
      // ---- 代码风格（@stylistic）：遵循项目现有写法，不做强制重排 ----
      '@stylistic/comma-dangle': 'off', // 项目普遍使用尾逗号
      '@stylistic/arrow-parens': 'off', // 项目使用 (x) => 写法
      '@stylistic/member-delimiter-style': 'off', // 类型成员用分号分隔
      '@stylistic/quote-props': 'off', // 语言映射对象使用带引号的键
      '@stylistic/max-statements-per-line': 'off', // 允许单行多条语句
      '@stylistic/no-multiple-empty-lines': 'off',
      '@stylistic/eol-last': 'off',

      // ---- Vue 模板风格 ----
      'vue/singleline-html-element-content-newline': 'off', // 允许 <p>text</p> 单行
      'vue/multiline-html-element-content-newline': 'off',
      'vue/max-attributes-per-line': 'off', // 允许一行多属性
      'vue/html-self-closing': 'off',

      // ---- TypeScript：渲染器 / composables / 页面广泛使用 any 与解构 ----
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-unused-vars': 'off',
      '@typescript-eslint/no-unused-expressions': 'off',

      // ---- 通用 ----
      'prefer-const': 'off',
      'no-control-regex': 'off', // wiki.ts 需用 \x00 处理正文中的控制字符
      'no-useless-assignment': 'off'
    }
  }
)
