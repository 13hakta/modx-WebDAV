<?php

if ($object->xpdo) {
	/** @var modX $modx */
	$modx =& $object->xpdo;

	switch ($options[xPDOTransport::PACKAGE_ACTION]) {
		case xPDOTransport::ACTION_INSTALL:
			$modelPath = $modx->getOption('webdav.core_path', null, $modx->getOption('core_path') . 'components/webdav/') . 'model/';

			$modx->addPackage('webdav', $modelPath);
        		$modx->addExtensionPackage('webdav', $modelPath);

			$manager = $modx->getManager();

			break;

		case xPDOTransport::ACTION_UPGRADE:
			break;

		case xPDOTransport::ACTION_UNINSTALL:
    		    if ($modx instanceof modX) {
    		        $modx->removeExtensionPackage('webdav');
    		    }
		    break;
	}
}
return true;