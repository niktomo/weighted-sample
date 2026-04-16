# コーディングルール

このファイルはプロジェクト固有のコーディング規約を定義する。

---

## クロージャ（アロー関数）

### `static fn` を使う

`$this` やクラススコープを参照しないクロージャは必ず `static fn` で書く。

```php
// Bad — $this を参照しないのに static でない
$extractor = fn (array $item): int => $item['weight'];

// Good — static fn で明示する
$extractor = static fn (array $item): int => $item['weight'];
```

**適用範囲:** `src/`・`tests/`・README コード例のすべて。

**理由:**
- `static fn` は `$this` をバインドしないことを明示し、意図を伝える
- クラス内で誤って `$this` を参照するバグを防ぐ
- PHP ランタイムのクロージャオブジェクトが軽量になる

**例外:** クロージャ内で `$this` を使う必要がある場合のみ `fn` を使う。

### 型注釈を付ける

引数と戻り値の型を明示する。

```php
// Bad
static fn ($item) => $item['weight']

// Good
static fn (array $item): int => $item['weight']
```
