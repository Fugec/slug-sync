<?php
/**
 * PHPUnit bootstrap.
 *
 * The signal detector guards against direct access like every other plugin
 * file, so the constant has to exist before it is loaded. It calls no
 * WordPress functions, which is why a bare define() is the whole bootstrap.
 *
 * @package Slug_Sync
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-slug-sync-signals.php';
