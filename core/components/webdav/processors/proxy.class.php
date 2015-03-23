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
 * @package webdav
*/

class MediaProxyProcessor extends modObjectGetProcessor {
    /** @var modMediaSource|modWebDAVMediaSource $source */
    public $source;
    public $permission = 'load';


    public function initialize() {
        $this->setDefaultProperties(array(
            'src' => '',
            'source' => 1,
        ));

        return true;
    }


    public function process() {
        @session_write_close();

        $src = $this->getProperty('src');
        if (empty($src)) return $this->failure();

        $this->getSource($this->getProperty('source'));
        if (empty($this->source)) $this->failure($this->modx->lexicon('source_err_nf'));

	// Prevent another media sources requests for security reasons
	if ($this->source->getTypeName() != 'WebDAV') $this->failure($this->modx->lexicon('source_err_nfs'));

	$body = $this->source->getObjectContents($src);
	if (empty($body)) $this->failure($this->modx->lexicon('file_err_nf'));

	header('Content-Type: ' . $body['mime']);
	echo $body['content'];
    }


    /**
     * Get the source to load the paths from
     * 
     * @param int $sourceId
     * @return modMediaSource|modFileMediaSource
     */
    public function getSource($sourceId) {
        /** @var modMediaSource|modWebDAVMediaSource $source */
        $this->modx->loadClass('sources.modMediaSource');
        $this->source = modMediaSource::getDefaultSource($this->modx, $sourceId, false);
        if (empty($this->source)) return false;

        if (!$this->source->getWorkingContext()) {
            return false;
        }
        $this->source->setRequestProperties($this->getProperties());
        $this->source->initialize();
        return $this->source;
    }
}

return 'MediaProxyProcessor';

?>