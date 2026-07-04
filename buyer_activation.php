<?php
$accessToken = trim((string) ($_GET['access'] ?? $_POST['access'] ?? ''));
$redirectUrl = '/CPMraffle/verification.php';
if ($accessToken !== '') {
    $redirectUrl .= '?access=' . rawurlencode($accessToken);
}

header('Location: ' . $redirectUrl, true, 302);
exit();
