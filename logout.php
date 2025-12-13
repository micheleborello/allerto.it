<?php
require __DIR__.'/auth.php';
auth_logout();
$_SESSION['CASERMA_SLUG'] = null;
$_SESSION['tenant_slug']  = null;
header('Location: login.php');