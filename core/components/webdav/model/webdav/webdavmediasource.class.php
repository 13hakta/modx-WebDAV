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

/**
 * Implements a file-system-based media source, allowing manipulation and management
 * of files on the remote WebDAV server.
 */

include_once dirname(__FILE__) . "/webdav.php";

class WebDAVMediaSource extends modMediaSource implements modMediaSourceInterface {
    private $client;
    private $basePath;
    private $baseUrl;
    private $cached = false;
    private $cacheTime = false;
    private $proxified = false;
    private $preview = false;
    private $tmp_file = '';

    /**
     * {@inheritDoc}
     * @return boolean
     */
    public function initialize() {
        parent::initialize();

        $properties = $this->getPropertyList();

	$this->cached = $this->getOption('cached', $properties, false);
	$this->cacheTime = $this->getOption('cacheTime', $properties, 10) * 60;

	$this->proxified = $this->getOption('proxy', $properties, false);

	$this->preview = $this->getOption('preview', $properties, false);

	$this->basePath = $this->getOption('basePath', $properties);
	if (substr($this->basePath, -1) != '/')
    	    $this->basePath .= '/';

	if ($this->proxified) {
	    $this->baseUrl = $this->xpdo->getOption('site_url') .
		ltrim($this->xpdo->getOption('assets_url'), '/') .
		'components/webdav/index.php?action=proxy&source=' . $this->get('id') . '&ctx=' . $this->xpdo->context->key . '&src=';
	} else {
	    $this->baseUrl = $this->getOption('baseUrl', $properties) . '/';
	    if ($this->basePath != '/')
		$this->baseUrl .= $this->basePath;
	}

	$this->client = new WebDAV_Client(array(
	    'uri'      => $this->getOption('server', $properties),
	    'path'     => $this->basePath,
	    'user'     => $this->getOption('login', $properties),
	    'password' => $this->getOption('password', $properties),
	    'auth'     => $this->getOption('authMethod', $properties),
	    'ssl'      => $this->getOption('verifySSL', $properties)));

        $this->xpdo->lexicon->load('webdav:default', 'webdav:source');
        return true;
    }


    /**
     * Clear directory list cache
     * 
     * @param string $path
     */
    function clearPathCache($path) {
	// Clear cache
	$key = 'webdav-' . $this->get('id') . '-cl-' . str_replace('/', '_', $path);
	$this->xpdo->cacheManager->delete($key);

	$key = 'webdav-' . $this->get('id') . '-oic-' . str_replace('/', '_', $path);
	$this->xpdo->cacheManager->delete($key);
    }


    /**
     * Get the ID of the edit file action
     *
     * @return boolean|int
     */
    public function getEditActionId() {
        return 'system/file/edit';
    }


    /**
     * Return an array of containers at this current level in the container structure. Used for the tree
     * navigation on the files tree.
     *
     * @param string $path
     * @return array
     */
    public function getContainerList($path) {
	$res = false;

	if ($this->cached) {
	    $key = 'webdav-' . $this->get('id') . '-cl-' . str_replace('/', '_', $path);
	    $res = $this->xpdo->cacheManager->get($key);
	}

	try {
	    if ($res == false) {
		// Perform request
		$res = $this->client->dir($path);
		if ($res == false) throw new Exception('Bad data ' . $path);

		if ($this->cached)
		    $this->xpdo->cacheManager->set($key, $res, $this->cacheTime);
	    }

	    $dirs = $res[0];
	    $files = $res[1];
	} catch (Exception $e) {
	    $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, "[DAV] Error opening dir $path: " . $e->getMessage());
	    return false;
	}

	// Initialize config
        $properties = $this->getPropertyList();

	// Get cls
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $canCreate = $this->checkPolicy('create');

        $cls = array('folder');
        if ($this->hasPermission('directory_chmod') && $canSave) $cls[] = 'pchmod';
        if ($this->hasPermission('directory_create') && $canCreate) $cls[] = 'pcreate';
        if ($this->hasPermission('directory_remove') && $canRemove) $cls[] = 'premove';
        if ($this->hasPermission('directory_update') && $canSave) $cls[] = 'pupdate';
        if ($this->hasPermission('file_upload') && $canCreate) $cls[] = 'pupload';
        if ($this->hasPermission('file_create') && $canCreate) $cls[] = 'pcreate';
	$folder_cls = implode(' ', $cls);

        $cls = array();
        if ($this->hasPermission('file_remove') && $canRemove) $cls[] = 'premove';
        if ($this->hasPermission('file_update') && $canSave) $cls[] = 'pupdate';
	$file_cls = implode(' ', $cls);

	// Get options
        $imagesExts = $this->getOption('imageExtensions', $properties, 'jpg,jpeg,png,gif');
        $imagesExts = explode(',', $imagesExts);

        $skipFiles = $this->getOption('skipFiles', $properties, '.svn,.git,_notes,.DS_Store,nbproject,.idea');
        $skipFiles = explode(',', $skipFiles);

        if ($this->xpdo->getParser()) {
            $this->xpdo->parser->processElementTags('', $skipFiles, true, true);
        }
        $allowedExtensions = $this->getOption('allowedFileTypes', $properties);

        if (is_string($allowedExtensions)) {
            if (empty($allowedExtensions)) {
                $allowedExtensions = array();
            } else {
                $allowedExtensions = explode(',', $allowedExtensions);
            }
        }

	$relpath = ltrim($path, '/');

	// Get menu items
	$menu1 = $this->getListContextMenu(true);
	$menu2 = $this->getListContextMenu(false);

        $editAction = $this->getEditActionId();

        $dirlist = array();
        $dirnames = array();
        $filelist = array();
        $filenames = array();

	foreach ($dirs as $dir => $opts) {
    	    if (in_array($dir, $skipFiles)) continue;
    	    $dirnames[] = strtoupper($dir);

    	    $dirlist[] = array(
        	'id' => $relpath . $dir . '/',
            	'text' => $dir,
                'cls' => $folder_cls,
            	'type' => 'dir',
		'iconCls' => 'icon icon-folder',
            	'leaf' => false,
		'path' => $relpath . $dir,
                'pathRelative' => $relpath . $dir,
		'menu' => array('items' => $menu2)
            );
	}

	foreach ($files as $file => $opts) {
            if (in_array($file, $skipFiles)) continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!empty($allowedExtensions) && !in_array($ext, $allowedExtensions)) {
		continue;
	    }

            $page = null;
	    if (explode('/', $opts['getcontenttype'])[0] == 'text') {
	     $page = '?a=' . $editAction . '&file=' . $path . $file . '&wctx=' . $this->ctx->get('key') . '&source=' . $this->get('id');
	    }

	    $filenames[] = strtoupper($file);

    	    $fileItem = array(
                'id' => $relpath . $file,
            	'text' => $file,
		'directory' => $this->basePath,
                'cls' => $file_cls,
		'iconCls' => 'icon icon-file icon-' . $ext,
            	'type' => 'file',
            	'leaf' => true,
                'perms' => '',
	        'page' => $page,
                'pathRelative' => $relpath . $file,
	        'url' => $path . $file,
        	'urlAbsolute' => $path . $file,
		'menu' => array('items' => ($page)? array_merge($menu1[1], $menu1[0]) : $menu1[0])
            );

	    if (!empty($this->baseUrl)) {
		if (in_array($ext, $imagesExts))
        	    $fileItem['qtip'] = '<img src="' . $this->baseUrl . $path . $file . '" alt="' . $file . '" />';
	    }

    	    $filelist[] = $fileItem;
	}

    	/* now sort files/directories */
    	array_multisort($dirnames,  SORT_ASC, SORT_STRING, $dirlist);
    	array_multisort($filenames, SORT_ASC, SORT_STRING, $filelist);

    	return array_merge($dirlist, $filelist);
    }


    /**
     * Get a list of files in a specific directory.
     *
     * @param string $path
     * @return array
     */
    public function getObjectsInContainer($path) {
	$res = false;

	if ($this->cached) {
	    $key = 'webdav-' . $this->get('id') . '-oic-' . str_replace('/', '_', $path);
	    $res = $this->xpdo->cacheManager->get($key);
	}

	try {
	    if ($res == false) {
		// Perform request
		$res = $this->client->dir($path);
		if ($res == false) throw new Exception('Bad data ' . $path);

		if ($this->cached)
		    $this->xpdo->cacheManager->set($key, $res, $this->cacheTime);
	    }

	    $dirs = $res[0];
	    $files = $res[1];
	} catch (Exception $e) {
	    $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, "[DAV] Error opening dir $path: " . $e->getMessage());
	    return false;
	}

	// Initialize config
        $properties = $this->getPropertyList();

        $modAuth = $this->xpdo->user->getUserToken($this->xpdo->context->get('key'));

        $thumbnailType = $this->getOption('thumbnailType', $properties, 'png');
        $thumbnailQuality = $this->getOption('thumbnailQuality', $properties, 90);
        $thumbWidth = $this->xpdo->context->getOption('filemanager_thumb_width', 100);
        $thumbHeight = $this->xpdo->context->getOption('filemanager_thumb_height', 80);

        $thumb_default = $this->xpdo->context->getOption('manager_url', MODX_MANAGER_URL) . 'templates/default/images/restyle/nopreview.jpg';
        $thumbUrl = $this->xpdo->context->getOption('connectors_url', MODX_CONNECTORS_URL) . 'system/phpthumb.php?';

        $imagesExts = $this->getOption('imageExtensions', $properties, 'jpg,jpeg,png,gif');
        $imagesExts = explode(',', $imagesExts);

        $skipFiles = $this->getOption('skipFiles', $properties, '.svn,.git,_notes,.DS_Store,nbproject,.idea');
        $skipFiles = explode(',', $skipFiles);
        if ($this->xpdo->getParser()) {
            $this->xpdo->parser->processElementTags('', $skipFiles, true, true);
        }

        $allowedExtensions = $this->getOption('allowedFileTypes', $properties);
        if (is_string($allowedExtensions)) {
            if (empty($allowedExtensions)) {
                $allowedExtensions = array();
            } else {
                $allowedExtensions = explode(',', $allowedExtensions);
            }
        }

        $filelist = array();
        $filenames = array();

	foreach ($files as $file => $opts) {
            if (in_array($file, $skipFiles)) continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!empty($allowedExtensions) && !in_array($ext, $allowedExtensions)) {
		continue;
	    }

	    $filenames[] = strtoupper($file);
	    $fileItem = array(
                    'id' => $path . $file,
            	    'name' => $file,
		    'ext' => $ext,
            	    'type' => 'file',
                    'lastmod' => strtotime($opts['getlastmodified']),
                    'size' => $opts['getcontentlength'],
            	    'leaf' => true,
            	    'perms' => '',
        	    'thumb_width' => $thumbWidth,
        	    'thumb_height' => $thumbHeight,
                    'disabled' => false,
                    'menu' => array(
                        array('text' => $this->xpdo->lexicon('file_remove'), 'handler' => 'this.removeFile'),
                    ),
            	);

	    $fileItem['url']             = $path . $file;
	    $fileItem['relativeUrl']     = $fileItem['url'];
	    $fileItem['fullRelativeUrl'] = $fileItem['url'];

	    if (!empty($this->baseUrl) && in_array($ext, $imagesExts) && $this->preview) {
                /* get thumbnail */
                $preview = 1;

                /* generate thumb/image URLs */
                $thumbQuery = http_build_query(array(
                    'src' => $fileItem['url'],
                    'w' => $thumbWidth,
                    'h' => $thumbHeight,
                    'f' => $thumbnailType,
                    'q' => $thumbnailQuality,
                    'far' => 'C',
                    'HTTP_MODAUTH' => $modAuth,
                    'wctx' => $this->xpdo->context->get('key'),
                    'source' => $this->get('id'),
                ));

                $thumb = $thumbUrl . urldecode($thumbQuery);
    		$fileItem['image'] = $this->baseUrl . $path . $file;
            } else {
                $preview = 0;
                $thumb = $thumb_default;
		$fileItem['image'] = $thumb;
            }

	    $fileItem['thumb']   = $thumb;
	    $fileItem['preview'] = $preview;

    	    $filelist[] = $fileItem;
 	}

    	/* now sort files/directories */
    	array_multisort($filenames, SORT_ASC, SORT_STRING, $filelist);

    	return $filelist;
    }


    /**
     * Rename container
     * 
     * @param string $oldPath
     * @param string $newName
     * @return bool
     */
    public function renameContainer($oldPath, $newName) {
	$newPath = $newName;

	$parentDir = dirname($oldPath);
	if ($parentDir != '.')
	    $newPath = $parentDir . '/' . $newPath;

	if (!$this->client->rename($oldPath, $newPath)) {
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_rename'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerDirRename', array(
            'directory' => $newPath,
            'source' => &$this,
        ));

	if ($this->cached)
	    $this->clearPathCache(dirname($oldPath));

        /* log manager action */
        $this->xpdo->logManagerAction('directory_rename', '', $newPath);

        return rawurlencode($newPath);
    }


    /**
     * Rename object
     * 
     * @param string $oldPath
     * @param string $newName
     * @return bool
     */
    public function renameObject($oldPath, $newName) {
	$newPath = $newName;

	$parentDir = dirname($oldPath);
	if ($parentDir != '.')
	    $newPath = $parentDir . '/' . $newPath;

	if (!$this->client->rename($oldPath, $newPath)) {
            $this->addError('name', $this->xpdo->lexicon('file_file_err_rename'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerFileRename', array(
            'directory' => $newPath,
            'source' => &$this,
        ));

	if ($this->cached)
	    $this->clearPathCache(dirname($oldPath));

        /* log manager action */
        $this->xpdo->logManagerAction('file_rename','', $newPath);

        return rawurlencode($newPath);
    }


    /**
     * Move a file or folder to a specific location
     *
     * @param string $from The location to move from
     * @param string $to The location to move to
     * @param string $point
     * @return boolean
     */
    public function moveObject($from, $to, $point = 'append') {
	$newPath = '';
	if ($to != '/') $newPath .= $to;
	$newPath .= basename($from);

	if (!$this->client->rename($from, $newPath)) {
            $this->addError('name', $this->xpdo->lexicon('file_file_err_rename'));
	    return false;
	}

	if ($this->cached) {
	    $this->clearPathCache($from);
	    $this->clearPathCache($to);
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerMoveObject', array(
            'from' => $from,
            'to' => $newPath,
            'source' => &$this,
        ));

        /* log manager action */
        $this->xpdo->logManagerAction('filetree_move', '', "$from - $newPath");
        return true;
    }


    /**
     * Get the contents of a specified file
     *
     * @param string $objectPath
     * @return array
     */
    public function getObjectContents($objectPath) {
	$response = false;

	if ($this->cached) {
	    $key = 'webdav-' . $this->get('id') . '-op-' . str_replace('/', '_', $objectPath);
	    $response = $this->xpdo->cacheManager->get($key);
	}

	if ($response === false) {
	    // Perform request
	    $response = $this->client->props($objectPath);

    	    if ($response === false) {
    		$this->addError('file', $this->xpdo->lexicon('file_err_nf'));
		return false;
	    }

	    if ($this->cached)
	        $this->xpdo->cacheManager->set($key, $response, $this->cacheTime);
	}

	$response_content = $this->client->readFile($objectPath);

        // check the response code, anything but 200 indicates a problem
        if ($response_content['statusCode'] != 200) {
            $this->addError('file', $this->xpdo->lexicon('file_err_nf'));
	    return false;
        }

        $imageExtensions = 'jpg,jpeg,png,gif';
        $imageExtensions = explode(',', $imageExtensions);
        $fileExtension = pathinfo($objectPath, PATHINFO_EXTENSION);

        $fa = array(
            'name' => $objectPath,
            'basename' => basename($objectPath),
            'path' => dirname($objectPath),
            'mime' => $response['getcontenttype'],
            'size' => $response['getcontentlength'],
            'last_modified' => $response['getlastmodified'],
            'content' => $response_content['body'],
            'image' => in_array($fileExtension, $imageExtensions) ? true : false,
            'is_writable' => true,
            'is_readable' => true,
        );

        return $fa;
    }


    /**
     * Create a filesystem folder
     *
     * @param string $name
     * @param string $parentContainer
     * @return boolean
     */
    public function createContainer($name, $parentContainer) {
	$abspath = (($parentContainer == '/')? '' : $parentContainer . '/') . $name;

	if (!$this->client->mkdir($abspath)) {
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_create'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerDirCreate', array(
            'directory' => $abspath,
            'source' => &$this,
        ));

	if ($this->cached)
	    $this->clearPathCache($parentContainer);

        /* log manager action */
        $this->xpdo->logManagerAction('directory_create','', $abspath);

        return true;
    }


    /**
     * Remove file
     *
     * @param string $objectPath
     * @return boolean|string
     */
    public function removeObject($objectPath) {
	if (!$this->client->delete($objectPath)) {
            $this->addError('file', $this->xpdo->lexicon('file_err_remove'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerFileRemove', array(
            'path' => $objectPath,
            'source' => &$this,
        ));

        /* log manager action */
        $this->xpdo->logManagerAction('file_remove', '', $objectPath);
        return true;
    }


    /**
     * Update the contents of a file
     *
     * @param string $objectPath
     * @param string $content
     * @return boolean|string
     */
    public function updateObject($objectPath, $content) {
	if ($this->client->writeFile($objectPath, $content)) {
            $this->addError('file', $this->xpdo->lexicon('file_err_save'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerFileUpdate', array(
            'path' => $objectPath,
            'source' => &$this,
        ));

        /* log manager action */
        $this->xpdo->logManagerAction('file_update', '', $objectPath);

        return rawurlencode($objectPath);
    }


    /**
     * Remove a folder at the specified location
     *
     * @param string $path
     * @return boolean
     */
    public function removeContainer($path) {
	if (!$this->client->delete($path)) {
            $this->addError('path', $this->xpdo->lexicon('file_folder_err_remove'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerDirRemove', array(
            'directory' => $path,
            'source' => &$this,
        ));

	if ($this->cached)
	    $this->clearPathCache($path);

        /* log manager action */
        $this->xpdo->logManagerAction('directory_remove', '', $path);

        return true;
    }


    /**
     * Create a file
     *
     * @param string $objectPath
     * @param string $name
     * @param string $content
     * @return boolean|string
     */
    public function createObject($objectPath, $name, $content) {
	$abspath = $objectPath . $name;

	if (!$this->client->writeFile($abspath, $content)) {
            $this->addError('file', $this->xpdo->lexicon('file_err_save'));
	    return false;
	}

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerFileCreate', array(
            'path' => $abspath,
            'source' => &$this,
        ));

	if ($this->cached)
	    $this->clearPathCache($objectPath);

        /* log manager action */
        $this->xpdo->logManagerAction('file_create', '', $abspath);

        return rawurlencode($abspath);
    }


    /**
     * Upload files to a specific folder
     *
     * @param string $container
     * @param array $objects
     * @return boolean
     */
    public function uploadObjectsToContainer($container, array $objects = array()) {
        $this->xpdo->context->prepare();
        $allowedFileTypes = explode(',', $this->xpdo->getOption('upload_files', null, ''));
        $allowedFileTypes = array_merge(explode(',', $this->xpdo->getOption('upload_images')), explode(',', $this->xpdo->getOption('upload_media')), explode(',', $this->xpdo->getOption('upload_flash')), $allowedFileTypes);
        $allowedFileTypes = array_unique($allowedFileTypes);
        $maxFileSize = $this->xpdo->getOption('upload_maxsize', null, 1048576);

        /* loop through each file and upload */
        foreach ($objects as $file) {
            if ($file['error'] != 0) continue;
            if (empty($file['name'])) continue;
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);

            if (empty($ext) || !in_array($ext, $allowedFileTypes)) {
                $this->addError('path', $this->xpdo->lexicon('file_err_ext_not_allowed', array(
                    'ext' => $ext,
                )));
                continue;
            }
            $size = filesize($file['tmp_name']);

            if ($size > $maxFileSize) {
                $this->addError('path', $this->xpdo->lexicon('file_err_too_large', array(
                    'size' => $size,
                    'allowed' => $maxFileSize,
                )));
                continue;
            }

    	    /* invoke event */
    	    $this->xpdo->invokeEvent('OnFileManagerBeforeUpload', array(
        	'files' => &$objects,
        	'file' => &$file,
        	'directory' => $container,
        	'source' => &$this,
    	    ));

    	    if (!$this->client->upload($container . $file['name'], $file['tmp_name'])) {
        	$this->addError('path', $this->xpdo->lexicon('file_err_upload'));
        	continue;
    	    }
        }

	if ($this->cached)
	    $this->clearPathCache($path);

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerUpload', array(
            'files' => &$objects,
            'directory' => $container,
            'source' => &$this,
        ));

        /* log manager action */
        $this->xpdo->logManagerAction('file_upload', '', $container);

        return !$this->hasErrors();
    }


    /**
     * Get the context menu items for a specific object in the list view
     *
     * @param boolean $is_single
     * @return array
     */
    public function getListContextMenu($is_single) {
        $canSave = $this->checkPolicy('save');
        $canRemove = $this->checkPolicy('remove');
        $canCreate = $this->checkPolicy('create');
        $canView = $this->checkPolicy('view');

        if ($is_single) { /* files */
    	    $menu = array(array(), array());
            if ($this->hasPermission('file_update') && $canSave) {
                $menu[1][] = array(
                        'text' => $this->xpdo->lexicon('file_edit'),
                        'handler' => 'this.editFile',
                );
                $menu[1][] = array(
                        'text' => $this->xpdo->lexicon('quick_update_file'),
                	'handler' => 'this.quickUpdateFile',
                );
                $menu[0][] = array(
                    'text' => $this->xpdo->lexicon('rename'),
                    'handler' => 'this.renameFile',
                );
            }
            if ($this->hasPermission('file_view') && $canView) {
                $menu[0][] = array(
                    'text' => $this->xpdo->lexicon('file_download'),
                    'handler' => 'this.downloadFile',
                );
            }
            if ($this->hasPermission('file_remove') && $canRemove) {
                if (!empty($menu)) $menu[0][] = '-';
                $menu[0][] = array(
                    'text' => $this->xpdo->lexicon('file_remove'),
                    'handler' => 'this.removeFile',
                );
            }
        } else { /* directories */
    	    $menu = array();
            if ($this->hasPermission('directory_create') && $canCreate) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_create_here'),
                    'handler' => 'this.createDirectory',
                );
            }
            if ($this->hasPermission('directory_update') && $canSave) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('rename'),
                    'handler' => 'this.renameDirectory',
                );
            }
            $menu[] = array(
                'text' => $this->xpdo->lexicon('directory_refresh'),
                'handler' => 'this.refreshActiveNode',
            );
            if ($this->hasPermission('file_upload') && $canCreate) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('upload_files'),
                    'handler' => 'this.uploadFiles',
                );
            }
            if ($this->hasPermission('file_create') && $canCreate) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_create'),
                    'handler' => 'this.createFile',
                );
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('quick_create_file'),
                    'handler' => 'this.quickCreateFile',
                );
            }
            if ($this->hasPermission('directory_remove') && $canRemove) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_remove'),
                    'handler' => 'this.removeDirectory',
                );
            }
        }
        return $menu;
    }


    /**
     * Get the base URL for this source. Only applicable to sources that are streams.
     *
     * @param string $object An optional object to find the base url of
     * @return string
     */
    public function getBaseUrl($object = '') {
        return $this->baseUrl;
    }


    /**
     * Get the absolute URL for a specified object. Only applicable to sources that are streams.
     *
     * @param string $object
     * @return string
     */
    public function getObjectUrl($value = '') {
	return $this->baseUrl . str_replace('%2F', '/', urlencode($value));
    }


    /**
     * Prepare the output values for image/file TVs by prefixing the baseUrl property to them
     *
     * @param string $value
     * @return string
     */
    public function prepareOutputUrl($value) {
        return $this->getObjectUrl($value);
    }


    /**
     * Prepare the source path for phpThumb
     * 
     * @param string $src
     * @return string
     */
    public function prepareSrcForThumb($value) {
	if (!$this->proxified)
	    return $this->getObjectUrl($value);

	// Retrieve file to temp
	$this->tmp_file = $this->xpdo->getCachePath() . 'default/webdav-' . md5($this->get('id') . ':'  . $value) . '.cache.php';
	if ($response_content = $this->client->readFile($value)) {
	    file_put_contents($this->tmp_file, $response_content['body']);

	    register_shutdown_function(array($this, '_deleteTempFile'));
    	    return $this->tmp_file;
	}

	return false;
    }


    /**
     * Remove source image file if media source is not cached
     */
    public function _deleteTempFile() {
	// Check for some correctness and delete temporary image file
	if (substr($this->tmp_file, -10) == '.cache.php')
	    @unlink($this->tmp_file);
    }


    /**
     * Get the default properties for the filesystem media source type.
     *
     * @return array
     */
    public function getDefaultProperties() {
	return array(
	    'server' => array(
		'name' => 'server',
		'desc' => 'setting_webdav.server_desc',
		'type' => 'textfield',
		'lexicon' => 'webdav:setting',
		'options' => '',
		'value' => ''
	    ),
	    'login' => array(
		'name' => 'login',
		'desc' => 'setting_webdav.login_desc',
		'type' => 'textfield',
		'lexicon' => 'webdav:setting',
	        'options' => '',
		'value' => ''
	    ),
	    'password' => array(
		'name' => 'password',
		'desc' => 'setting_webdav.password_desc',
		'type' => 'textfield',
		'lexicon' => 'webdav:setting',
	        'options' => '',
		'value' => ''
	    ),
	    'authMethod' => array(
		'name' => 'authMethod',
		'desc' => 'setting_webdav.auth_desc',
                'type' => 'list',
                'options' => array(
                    array('name' => '-','value' => ''),
                    array('name' => 'Basic','value' => 'basic'),
                    array('name' => 'Digest','value' => 'digest'),
                ),
		'lexicon' => 'webdav:setting',
		'value' => ''
	    ),
            'verifySSL' => array(
                'name' => 'verifySSL',
                'desc' => 'setting_webdav.verify_ssl_desc',
                'type' => 'combo-boolean',
                'options' => '',
                'value' => true,
                'lexicon' => 'core:source',
            ),
	    'basePath' => array(
		'name' => 'basePath',
                'desc' => 'prop_file.basePath_desc',
		'type' => 'textfield',
		'options' => '',
		'value' => '',
                'lexicon' => 'core:source'
	    ),
            'baseUrl' => array(
                'name' => 'baseUrl',
                'desc' => 'prop_file.baseUrl_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'cached' => array(
                'name' => 'cached',
                'desc' => 'setting_webdav.cached_desc',
                'type' => 'combo-boolean',
                'options' => '',
                'value' => false,
		'lexicon' => 'webdav:setting',
            ),
            'cacheTime' => array(
                'name' => 'cacheTime',
                'desc' => 'setting_webdav.cachetime_desc',
		'type' => 'textfield',
                'options' => '',
                'value' => 10,
		'lexicon' => 'webdav:setting',
            ),
            'proxy' => array(
                'name' => 'proxy',
                'desc' => 'setting_webdav.proxy_desc',
                'type' => 'combo-boolean',
                'options' => '',
                'value' => false,
                'lexicon' => 'core:source',
            ),
            'preview' => array(
                'name' => 'preview',
                'desc' => 'setting_webdav.preview_desc',
                'type' => 'combo-boolean',
                'options' => '',
                'value' => false,
		'lexicon' => 'webdav:setting',
            ),
            'allowedFileTypes' => array(
                'name' => 'allowedFileTypes',
                'desc' => 'prop_file.allowedFileTypes_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'imageExtensions' => array(
                'name' => 'imageExtensions',
                'desc' => 'prop_file.imageExtensions_desc',
                'type' => 'textfield',
                'value' => 'jpg,jpeg,png,gif',
                'lexicon' => 'core:source',
            ),
            'skipFiles' => array(
                'name' => 'skipFiles',
                'desc' => 'prop_file.skipFiles_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '.svn,.git,_notes,nbproject,.idea,.DS_Store',
                'lexicon' => 'core:source',
            'thumbnailType' => array(
                'name' => 'thumbnailType',
                'desc' => 'prop_file.thumbnailType_desc',
                'type' => 'list',
                'options' => array(
                    array('name' => 'PNG','value' => 'png'),
                    array('name' => 'JPG','value' => 'jpg'),
                    array('name' => 'GIF','value' => 'gif'),
                ),
                'value' => 'png',
                'lexicon' => 'core:source',
            ),
            'thumbnailQuality' => array(
                'name' => 'thumbnailQuality',
                'desc' => 'prop_s3.thumbnailQuality_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 90,
                'lexicon' => 'core:source',
            )
            )
	);
    }


    /**
     * Get the name of this source type
     * @return string
     */
    public function getTypeName() {
        $this->xpdo->lexicon->load('webdav:source');
        return $this->xpdo->lexicon('webdav.source_name');
    }


    /**
     * Get the description of this source type
     * @return string
     */
    public function getTypeDescription() {
        $this->xpdo->lexicon->load('webdav:source');
        return $this->xpdo->lexicon('webdav.source_desc');
    }
}