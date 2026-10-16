<?php

/**
 * Unit tests run against the real Joomla vendor libraries (InputFilter, Path, svg-sanitize)
 * of a local Joomla 5.3 checkout, without booting the CMS.
 */

\define('_JEXEC', 1);

$joomla = getenv('JOOMLA_ROOT') ?: dirname(__DIR__) . '/../gss';
$joomla = realpath(str_starts_with($joomla, '/') ? $joomla : dirname(__DIR__) . '/' . $joomla);

if (!$joomla || !is_file($joomla . '/libraries/vendor/autoload.php')) {
    fwrite(STDERR, "Set JOOMLA_ROOT to a Joomla 5.3 installation (libraries/vendor is needed).\n");
    exit(1);
}

\define('JPATH_ADMINISTRATOR', $joomla . '/administrator');

require $joomla . '/libraries/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/libraries/gssmarkdown/vendor/autoload.php';
