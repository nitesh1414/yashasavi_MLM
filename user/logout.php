<?php
require_once __DIR__ . '/../includes/init.php';
user_logout();
flash('success', 'You have been logged out successfully.');
redirect('../login.php');
