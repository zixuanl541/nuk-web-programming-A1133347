# 作業4：郵件寄送系統

這一版使用比較基本的 PHP 寫法：

- `mysqli_connect()`
- `mysqli_query()`
- `mysqli_fetch_assoc()`
- phpMyAdmin / MySQL 資料庫
- PHPMailer 寄信

## phpMyAdmin 資料庫

資料庫名稱：

```text
homework4_mailer
```

資料表名稱：

```text
emails
```

欄位：

```text
No, email, created_at
```

可以直接開網頁，程式會自動建立資料庫和資料表。
也可以到 phpMyAdmin 匯入 `setup.sql`。

## MySQL 密碼

如果 phpMyAdmin 的 root 有密碼，請修改：

```text
config.php
```

把 `$db_pass = "";` 改成你的 MySQL 密碼。

## 開啟網址

啟動 XAMPP 的 Apache 和 MySQL 後開啟：

```text
http://localhost/PHPMailer-master/homework4/index.php
```
