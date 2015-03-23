<?php
/**
 * WebDAV
 *
 * Copyright 2015 by Vitaly Checkryzhev <13hakta@gmail.com>
 *
 * WebDAV is a network media source for MODX Revolution.
 *
 * WebDAV is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation version 3,
 *
 * WebDAV is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * WebDAV; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package WebDAV
*/

define('MODX_CONNECTOR_INCLUDED', 1);

require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/config.core.php';
require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
require_once MODX_CONNECTORS_PATH . 'index.php';

if (empty($_SERVER['HTTP_MODAUTH']))
    $_SERVER['HTTP_MODAUTH'] = $modx->user->getUserToken($modx->context->get('key'));

// handle request
$modx->request->handleRequest(array(
    'processors_path' => MODX_CORE_PATH . 'components/webdav/processors/',
    'location' => '',
));

?>