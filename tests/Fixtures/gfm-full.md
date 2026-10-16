# 標題一 H1

## 標題二 H2

### H3 with `code` and **bold**

#### H4

##### H5

###### H6

Setext 標題
===========

段落文字，含 **粗體**、*斜體*、***粗斜體***、~~刪除線~~、`inline code`、跳脫字元 \*not italic\*。
同一段落的第二行（軟換行）。  
硬換行（兩個空白）之後的一行。\
反斜線硬換行之後的一行。

自動連結：https://www.gss.com.tw 與 <https://example.com> 與 www.example.org，email: dev@example.com。

[行內連結](https://www.gss.com.tw "標題") 與 [參考連結][ref]。

[ref]: https://example.com/ref "Ref Title"

![圖片 alt](/images/easyblog_articles/12/abcd1234_shot.png "圖說")

> 引用第一段
>
> > 巢狀引用
>
> - 引用中的清單

- 無序項目
- 第二項
  - 巢狀二層
    - 巢狀三層
  - 巢狀二層之二
- 第三項，含多段落

  第三項的第二段

1. 有序
2. 第二
   1. 巢狀有序
   2. 巢狀有序之二
3. 第三

7. 從 7 開始的清單
8. 八

- [x] 已完成任務
- [ ] 未完成任務
  - [ ] 巢狀任務

* 星號清單
+ 加號清單

| 左對齊 | 置中 | 右對齊 | 預設 |
|:-------|:----:|-------:|------|
| a      | b    | c      | d    |
| `code` | **粗** | ~~刪~~ | [連結](https://example.com) |
| 管線 \| 跳脫 | | 空白格 | |

```js
const greet = (name) => `Hello, ${name}`;
console.log('<script>');
```

```csharp
public class Foo { public int Bar { get; set; } }
```

```
無語言的圍欄程式碼
```

~~~python
def f(x):
    return x ** 2
~~~

    縮排程式碼區塊
    第二行

---

***

___

<kbd>Ctrl</kbd> + <kbd>C</kbd> 與 <div class="x">原始 HTML 區塊</div>

<!-- HTML 註解 -->

腳註語法不支援[^1]，會以文字呈現。

[^1]: 腳註內容

HTML 實體：&copy; &amp; &lt;tag&gt; &#x1F600;

最後一段，結尾沒有換行
