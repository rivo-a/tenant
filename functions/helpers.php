<?php
// functions/helpers.php

// Check if admin is logged in
function checkLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

// Redirect helper
function redirect($url) {
    header("Location: $url");
    exit;
}
?>
