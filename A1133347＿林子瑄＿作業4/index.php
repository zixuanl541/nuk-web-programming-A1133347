<?php
include("db.php");

$msg = "";

if (isset($_POST["add_email"])) {
    $emails = trim($_POST["emails"]);
    $email_array = preg_split("/[\s,;]+/", $emails);
    $add_count = 0;
    $error_count = 0;

    for ($i = 0; $i < count($email_array); $i++) {
        $email = trim($email_array[$i]);

        if ($email == "") {
            continue;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = mysqli_real_escape_string($link, strtolower($email));
            $sql = "INSERT IGNORE INTO emails(email) VALUES('$email')";
            mysqli_query($link, $sql);

            if (mysqli_affected_rows($link) > 0) {
                $add_count++;
            }
        } else {
            $error_count++;
        }
    }

    $msg = "新增 " . $add_count . " 筆 email";

    if ($error_count > 0) {
        $msg .= "，略過 " . $error_count . " 筆格式錯誤";
    }
}

if (isset($_GET["delete"])) {
    $no = intval($_GET["delete"]);
    mysqli_query($link, "DELETE FROM emails WHERE No=$no");
    $msg = "已刪除資料";
}

$emails = array();
$result = mysqli_query($link, "SELECT * FROM emails ORDER BY No DESC");

while ($row = mysqli_fetch_assoc($result)) {
    $emails[] = $row;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <title>垃圾郵件寄送系統</title>
    <style>
        body {
            font-family: Arial, "Microsoft JhengHei", sans-serif;
            background-color: #f5f5f5;
            margin: 30px;
        }
        h1 {
            text-align: center;
        }
        form, table {
            background-color: white;
            padding: 15px;
            margin-bottom: 20px;
        }
        input, textarea, select {
            padding: 6px;
            margin-top: 5px;
        }
        button {
            padding: 8px 16px;
            background-color: #2f6fad;
            color: white;
            border: 0;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th {
            background-color: #eeeeee;
        }
        td, th {
            border: 1px solid #cccccc;
        }
    </style></head>
<body>
<h1>作業4：郵件寄送系統</h1>

<?php if ($msg != "") { ?>
    <p><?php echo htmlspecialchars($msg); ?></p>
<?php } ?>

<hr>

<h2> 建立 email 資料庫</h2>
<form method="post">
    <p>輸入 email，可一次輸入多筆，換行或空白分隔：</p>
    <textarea name="emails" rows="5" cols="80" placeholder="test1@example.com&#10;test2@example.com"></textarea>
    <br><br>
    <button type="submit" name="add_email">新增 email</button>
</form>

<h3>目前資料庫名單</h3>
<table border="1" cellpadding="8" cellspacing="0">
    <tr>
        <th>No.</th>
        <th>Email</th>
        <th>建立時間</th>
        <th>管理</th>
    </tr>
    <?php for ($i = 0; $i < count($emails); $i++) { ?>
        <tr>
            <td><?php echo $emails[$i]["No"]; ?></td>
            <td><?php echo htmlspecialchars($emails[$i]["email"]); ?></td>
            <td><?php echo $emails[$i]["created_at"]; ?></td>
            <td><a href="index.php?delete=<?php echo $emails[$i]["No"]; ?>">刪除</a></td>
        </tr>
    <?php } ?>
    <?php if (count($emails) == 0) { ?>
        <tr>
            <td colspan="4">目前沒有資料</td>
        </tr>
    <?php } ?>
</table>

<hr>

<h2> 寄信</h2>
<form action="send_one.php" method="post" target="_blank">
    <p>
        寄件者（from 作業4 系統）：<br>
        <br>zixuanl541@gmail.com
    </p>
    <p>
        主旨：<br>
        <input type="text" name="subject" size="60" required>
    </p>
    <p>
        內容：<br>
        <textarea name="body" rows="6" cols="80" required></textarea>
    </p>
    <h3>寄送方式</h3>
    <p>
        <label><input type="radio" name="send_type" value="all" checked> 全部寄送</label>
        <label><input type="radio" name="send_type" value="random"> 隨機寄送</label>
    </p>
    <p>隨機筆數：<br><input type="number" name="random_count" value="3" min="1"></p>
    <p>間隔秒數：<br><input type="number" name="delay" value="1" min="0"></p>

    <button type="submit" name="send_mail">開始寄送</button>
</form>

</body>
</html>
