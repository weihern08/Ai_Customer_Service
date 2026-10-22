<?php
require __DIR__ . '/includes/bootstrap.php';

// Logout only via POST + CSRF token so other sites cannot sign users out with a link/image.
if (!is_post()) {
    redirect('index.php');
}
require_csrf();
Auth::logout();
flash('success', __('auth.logged_out'));
redirect('login.php');
