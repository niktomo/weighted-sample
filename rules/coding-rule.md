# コーディング規約

## 引数の命名

### 原則

引数名は **その引数が何をするものか（振る舞い）** または **何であるか（役割）** を正確に表すこと。
略語・接尾辞による型表現（`Fn`, `Cb`, `Cls`）は使わない。

### クロージャ引数

クロージャ引数は「何を取り出すか」を動詞＋目的語、または目的語＋抽出者（Extractor）で表す。

| 悪い例 | 良い例 | 理由 | 適用バージョン |
|--------|--------|------|---------------|
| `$weightFn` | `$weightExtractor` | `Fn` は「関数である」という型情報に過ぎない。`Extractor` は「何をするか」を示す | **v1.1.0**（named args 破壊的変更） |
| `$countFn` | `$countExtractor` | 同上 | **v1.1.0** |
| `$cb` | `$onSuccess` | 「コールバック」は型名。「成功時に何をするか」が振る舞い |
| `$fn` | `$transform` | 型名のみ。何を変換するかが不明 |

### クラス名引数

クラス名（`class-string`）を渡す引数には `Class` サフィックスをつけない。
代わりに「何を選択/生成するか」を示す名前にする。

| 悪い例 | 良い例 | 理由 |
|--------|--------|------|
| `$selectorClass` | `$selectorClass` | ※ `class-string<SelectorInterface>` なので Class サフィックスは許容。ただし将来インスタンス渡しに変更する場合は `$selector` にすること |

### その他の引数

| 悪い例 | 良い例 | 理由 |
|--------|--------|------|
| `$n` | `$itemCount` | 単文字は何を意味するか不明 |
| `$val` | `$newCount` | `val` は型名。何の値かを示す |
| `$idx` | `$selectedIndex` | 略語。`Index` まで書く |
| `$rand` | `$randomizer` | 略語 |

## 変数の命名

引数と同じ原則を内部変数にも適用する。
ループカウンタ（`$i`, `$j`）は慣用的に許容するが、意味のある名前が付けられるなら付ける。

```php
// Bad
foreach ($items as $k => $v) { ... }

// Good
foreach ($items as $index => $item) { ... }
```

## 型アノテーション

- `declare(strict_types=1)` を全ファイルに付与する
- PHPStan level 8 を通過させる。`@phpstan-ignore` / baseline / `@var` 上書きは禁止
- クロージャの型は PHPDoc で明示する

```php
// Good
/** @param \Closure(TItem): int $weightExtractor */
\Closure $weightExtractor,
```
