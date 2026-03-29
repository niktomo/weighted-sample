# Contributing

## 開発環境

```bash
composer install
```

## コマンド

```bash
composer test   # PHPUnit
composer stan   # PHPStan (level 8)
composer pint   # Pint (PSR-12 preset)
```

## コーディング規約

- `declare(strict_types=1)` を全ファイルに付与
- **PHPStan level 8** — `@phpstan-ignore` による抑制禁止。根本原因を修正する
- **Pint** — PSR-12 preset（`pint.json`）
- 変数名は略さない（`$idx` → `$selectedIndex` 等）

## テスト規約

- **TDD 必須** — 実装より先にテストを書く（Red → Green → Refactor）
- **Unit Tests**: AAA 形式（`// Arrange`, `// Act`, `// Assert`）
- **Feature Tests**: BDD/Gherkin 形式（`// Given`, `// When`, `// Then`）
- **組み合わせテスト**: `#[DataProvider]` を使用
- 全アサーションに「何を検証し、何が期待結果か」のメッセージを付与
- テストメソッド名は `test_snake_case_describing_behavior` 形式

---

## ブランチ戦略

| ブランチ | 用途 |
|---|---|
| `main` | 安定版。直接 push 禁止。PR のみ |
| `feat/xxx` | 新機能 |
| `fix/xxx` | バグ修正 |
| `docs/xxx` | ドキュメントのみの変更 |
| `refactor/xxx` | 外部仕様を変えないリファクタリング |

---

## Issue

### バグ報告

以下を含めてください：

- PHP のバージョン
- 再現できる最小コード
- 期待した動作と実際の動作

### 機能要望

- ユースケースを先に説明する（「〜がしたい」）
- 実装案がある場合は別途記載

---

## Pull Request

1. `main` から `feat/xxx` or `fix/xxx` ブランチを作成
2. 変更を実装し、テストを追加
3. CI がすべて通ることを確認
4. PR を作成し、以下を記載：
   - **何を変えたか**
   - **なぜ変えたか**
   - **どのようにテストしたか**

### PR のルール

- テストなしの機能追加は受け付けない
- PHPStan level 8 エラーがある状態でマージしない
- 1 PR = 1目的
- `main` への force push 禁止
