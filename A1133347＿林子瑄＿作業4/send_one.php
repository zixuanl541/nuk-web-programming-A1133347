<?php
use PHPMailer\PHPMailer\PHPMailer;

include("db.php");

require("../PHPMailer-master/src/Exception.php");
require("../PHPMailer-master/src/PHPMailer.php");
require("../PHPMailer-master/src/SMTP.php");

if (!isset($_POST["send_mail"])) {
    echo "請從首頁送出表單。<br>";
    echo "<a href='index.php'>返回</a>";
    exit;
}

set_time_limit(0);
header("Content-Type: text/html; charset=utf-8");

$subject = trim($_POST["subject"]);
$body = trim($_POST["body"]);
$smtp_host = "smtp.gmail.com";
$smtp_port = 465;
$smtp_secure = "ssl";
$smtp_user = "zixuanl541@gmail.com";
$smtp_pass = "djfh kksf mtpc ribp";
$from_email = $smtp_user;
$from_name = "作業4系統";
$send_type = $_POST["send_type"];
$random_count = intval($_POST["random_count"]);
$delay = intval($_POST["delay"]);

if ($subject == "" || $body == "") {
    echo "主旨和內容不可空白<br>";
    echo "<a href='index.php'>返回</a>";
    exit;
}

if ($send_type == "random") {
    $sql = "SELECT email FROM emails ORDER BY RAND() LIMIT $random_count";
} else {
    $sql = "SELECT email FROM emails";
}

$result = mysqli_query($link, $sql);
$targets = array();

while ($row = mysqli_fetch_assoc($result)) {
    $targets[] = $row["email"];
}

$total = count($targets);

if ($total == 0) {
    echo "資料庫沒有可寄送的 email<br>";
    echo "<a href='index.php'>返回</a>";
    exit;
}

echo "<h2>開始寄送郵件</h2>";
echo "總共 " . $total . " 筆<br><br>";
flush();

for ($i = 0; $i < $total; $i++) {
    $current = $i + 1;
    $email = $targets[$i];
    $percent = round(($current / $total) * 100);

    echo "<p>進度：" . $percent . "% (" . $current . "/" . $total . ")</p>";
    echo "<progress value='" . $current . "' max='" . $total . "'></progress><br>";
    echo "正在寄送：" . htmlspecialchars($email) . " ... ";

    $mail = new PHPMailer();
    $mail->CharSet = "UTF-8";
    // SMTP 設定寫在程式裡，和範例同樣模式
    $mail->isSMTP();
    $mail->Host = $smtp_host;
    $mail->SMTPAuth = true;
    $mail->Username = $smtp_user;
    $mail->Password = $smtp_pass;
    $mail->Port = $smtp_port;
    $mail->SMTPSecure = $smtp_secure;

    $mail->setFrom($from_email, $from_name);
    $mail->addAddress($email);
    $mail->Subject = $subject;
    $mail->Body = $body;

    if ($mail->send()) {
        echo "成功<br>";
    } else {
        echo "失敗：" . $mail->ErrorInfo . "<br>";
    }

    echo "<hr>";
    flush();

    if ($current < $total && $delay > 0) {
        sleep($delay);
    }
}

echo "<h3>全部寄送完成</h3>";
echo "<p>3 秒後自動返回首頁</p>";
echo "<a href='index.php'>返回首頁</a>";
echo "<meta http-equiv='refresh' content='3;url=index.php'>";
