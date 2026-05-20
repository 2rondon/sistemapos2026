<?php
// index.php - Redirige al dashboard o login según sesión
require_once 'includes/functions.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
?>