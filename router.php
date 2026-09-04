<?php

// Apply the narrowly-scoped Render compatibility layer before the upstream
// backend evaluates its local-request security check.
require __DIR__ . '/render-bootstrap.php';
require __DIR__ . '/backend.php';
