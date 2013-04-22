<?php

include('lib/Sky/Autoloader.php');
spl_autoload_register(array('\Sky\Autoloader', 'namespaceLoader'));
spl_autoload_register(array('\Sky\Autoloader', 'globalLoader'));

