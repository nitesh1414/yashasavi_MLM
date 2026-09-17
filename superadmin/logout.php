<?php
require_once __DIR__ . '/../includes/init.php';
admin_logout('superadmin');
flash('success', 'You have been logged out.');
redirect('login.php');
