<?php
header('Content-Type: text/plain; charset=utf-8');
echo extension_loaded('gd') ? 'GD_ENABLED' : 'GD_DISABLED';
