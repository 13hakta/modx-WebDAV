<?php

/**
 * WebDAV
 *
 * Copyright 2015 by Vitaly Checkryzhev <13hakta@gmail.com>
 * Some code for XML parsing borrowed from Sabre.io
 *
 * WebDAV wrapper class. Only v1 of protocol is supported. No locking.
 * Requires CURL PHP library
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
 * Wrapper class for WebDAV access
 */
class WebDAV_Client {
    /**
     * Contains response headers
     *
     * @access private
     * @var    array
     */
    private $_headers = array();

    /**
     * Connection description
     *
     * @access private
     * @var    array
     */
    private $connection = array(
	'uri'      => false, // The http or https resource URL 
	'proxy'    => false, // Proxy url
	'user'     => false, // User name for authentication
	'password' => false, // Password for authentication
	'auth'     => false, // Authorization method
	'ssl'      => true,  // Check SSL certificate
	'path'     => '/',   // Root for operations
	'ua'       => 'PHP DAV lib' // User agent
    );

    /**
     * HTTP methods supported by the server
     *
     * @access private
     * @var    array   method entries
     */
    private $dav_allow = array();

    /**
     * Attribute of loaded options
     *
     * @access private
     * @var    bool
     */
    private $options_loaded = false;


    /**
     * Constructor
     *
     * Settings are provided through the 'settings' argument. The following
     * settings are supported:
     *
     *   * baseUri
     *   * user (optional)
     *   * password (optional)
     *   * proxy (optional)
     *   * auth_method (optional)
     *
     *  authType must be a bitmap, using self::AUTH_BASIC and
     *  self::AUTH_DIGEST. If you know which authentication method will be
     *  used, it's recommended to set it, as it will save a great deal of
     *  requests to 'discover' this information.
     *
     * @param array $settings
     */
    function __construct(array $settings) {

        if (!isset($settings['uri'])) {
            throw new Exception('URI must be provided');
        }

	$this->connection['uri'] = $settings['uri'];
	if (substr($this->connection['uri'], -1) != '/')
    	    $this->connection['uri'] .= '/';

        if (isset($settings['path']))
    	    $this->connection['path'] = $settings['path'];

        if (isset($settings['proxy']))
            $this->connection['proxy'] = $settings['proxy'];

        if (isset($settings['user'])) {
            $this->connection['user'] = $settings['user'];
            $this->connection['password'] = isset($settings['password'])? $settings['password'] : '';

	    $this->connection['auth'] = (isset($settings['auth']))?
		$settings['auth'] : 'basic';
        }

        if (isset($settings['ssl']))
    	    $this->connection['ssl'] = $settings['ssl'];

	// By default server must support
        $this->dav_allow = array();
        $this->dav_allow['OPTIONS'] = true;
        $this->dav_allow['MKCOL'] = true;
    }


    /**
     *  _check_options
     *
     * Helper function for WebDAV OPTIONS detection
     *
     * @access private
     * @return bool    true on success else false
     */
    private function _check_options() {
        // now check OPTIONS reply for WebDAV response headers
	$response = $this->request('OPTIONS');

        if ($response['statusCode'] != 200)
            return false;

        // get the supported DAV levels and extensions
        $dav = $response['headers']["dav"];
        $dav_level = array();
        foreach (explode(",", $dav) as $level) {
            $dav_level[trim($level)] = true;
        }
        if (!isset($dav_level["1"])) {
            // we need at least DAV Level 1 conformance
            return false;
        }
        
        // get the supported HTTP methods
        // TODO these are not checked for WebDAV compliance yet
        $allow = $response['headers']["allow"];
        foreach (explode(",", $allow) as $method) {
            $this->dav_allow[trim($method)] = true;
        }

	$this->options_loaded = true;
        return true;
    }


    /**
     *  _is_allowed
     *
     * Helper function to check if method is allowed
     *
     * @access private
     * @param string $method
     * @return bool    true on success else false
     */
    private function _is_allowed($method) {
	if (!($this->options_loaded || ($method == 'OPTIONS'))) {
    	    // query server for WebDAV options
	    if (!$this->_check_options()) return false;
	}

	return isset($this->dav_allow[$method]);
    }


    /**
     * dir
     *
     * List directory
     *
     * @access public
     * @param string $path
     * @return array
     *
     */
    function dir($path) {
        $body = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
 <d:prop>
  <d:resourcetype/>
  <d:getcontentlength/>
  <d:getcontenttype/>
  <d:getlastmodified/>
 </d:prop>
</d:propfind>
';

	$response = $this->request('PROPFIND', $path, $body, array(
		'Depth' => '1',
		'Content-Type' => 'text/xml')
	    );

        switch ($response['statusCode']) {
        case 207: // multistatus content
    	    try {
        	$dom = self::loadDOMDocument($response['body']);
    	    } catch (Exception $e) {
        	error_log('The body passed to parseMultiStatus could not be parsed. Is it really xml?');
		return false;
    	    }

        // now read the directory
	$response = $this->unserialize($dom->documentElement);

	$rootdir = key($response);
	$rootdir_len = strlen($rootdir);
	next($response);

	$dirs = [];
	$files = [];

	while (list($key, $val) = each ($response)) {
	 $name = urldecode(substr($key, $rootdir_len));

	 $rt = $val['resourcetype'];
	 unset($val['resourcetype']);

	 if ($rt == 'collection') {
	    $dirs[substr($name, 0, -1)] = $val;
	 } else {
	    $files[$name] = $val;
	 }
	}

        return [$dirs, $files];

        default: 
            // any other response state indicates an error
            error_log('Dir not found ' . $path);
	    return false;
        }
    }


    /**
     * props
     *
     * Retrieve properties of object
     *
     * @access public
     * @param string $path
     * @return array
     *
     */
    function props($path) {
        $body = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
 <d:prop>
  <d:resourcetype/>
  <d:getcontenttype/>
  <d:getcontentlength/>
  <d:getlastmodified/>
 </d:prop>
</d:propfind>
';

	$response = $this->request('PROPFIND', $path, $body, array(
		'Depth' => '0',
		'Content-Type' => 'text/xml')
	    );

        switch ($response['statusCode']) {
        case 207: // multistatus content
    	    try {
        	$dom = self::loadDOMDocument($response['body']);
    	    } catch (Exception $e) {
        	error_log('The body passed to parseMultiStatus could not be parsed. Is it really xml?');
		return false;
    	    }

	$response = $this->unserialize($dom->documentElement);
	return current($response);

        default: 
            // any other response state indicates an error
	    error_log("File not found: $path = " . print_r($response, true));
	    return false;
        }
    }


    /**
     *
     * readFile
     *
     * Retrieve file meta information and contents
     *
     * @access public
     * @param  string resource URL to read
     * @return array
     */
    function readFile($path) {
	return $this->request('GET', $path);
    }


    /**
     *
     * delete
     *
     * Delete collection/object
     *
     * @access public
     * @param  string collection URL to be created
     * @return bool   true on success
     */
    function delete($path) {
	$response = $this->request('DELETE', $path);

        switch ($response['statusCode']) {
        case 204: // ok
            return true;
        default: 
            return false;
        }
    }


    /**
     * Make directory
     *
     * @access public
     * @param  string collection URL to be created
     * @return bool   true on success
     */
    function mkdir($path) {
	$response = $this->request('MKCOL', $path);

        // check the response code, anything but 201 indicates a problem
        switch ($response['statusCode']) {
    	    case 201: return true;
        default:
            return false;
        }
    }


    /**
     *
     * writeFile
     *
     * Write data to file
     *
     * @access public
     * @param  string resource URL to be written
     * @return bool   true on success
     */
    function writeFile($path, $body) {
	$response = $this->request('PUT', $path, $body);

        // check the response code, anything but 201 indicates a problem
        switch ($response['statusCode']) {
    	    case 201: return true;
        default:
            return false;
        }
    }


    /**
     * Rename object
     *
     * @access public
     * @param  string resource/collection URL to rename
     * @return bool   true on success
     */
    function rename($path, $newName) {
	$dest = '/';
	if ($this->connection['path'] != '/')
	    $dest .= $this->connection['path'];
	$dest .= $newName;

	$response = $this->request('MOVE', $path, null,
		array(
		    'Destination' => $dest,
		    'Overwrite' => 'F'));

	file_put_contents('rn2', "$path -> $newName = $dest\n\n" . print_r($response, true));

        // check the response code, anything but 201 indicates a problem
        switch ($response['statusCode']) {
    	    case 404:
		error_log("Error renaming 404: $path");
		return false;
    	    case 201: return true;
        default:
	    error_log("Error renaming: $path");
            return false;
        }
    }


    /**
     * Upload file to remote directory
     *
     * @access public
     * @param  string collection URL to rename
     * @param  string resource path to upload
     * @return bool   true on success
     */
    function upload($destpath, $sourcefile) {
	$fh = fopen($sourcefile, 'r');

	if (!$fh) return false;

	$response = $this->request('PUT', $destpath, $fh);

	fclose($fh);

        // check the response code, anything but 201 indicates a problem
        switch ($response['statusCode']) {
    	    case 201: return true;
        default:
            return false;
        }
    }


    /**
     * Performs an actual HTTP request, and returns the result.
     *
     * The returned array contains 3 keys:
     *   * body - the response body
     *   * httpCode - a HTTP code (200, 404, etc)
     *   * headers - a list of response http headers. The header names have
     *     been lowercased.
     *
     * For large uploads, it's highly recommended to specify body as a stream
     * resource. You can easily do this by simply passing the result of
     * fopen(..., 'r').
     *
     * This method will throw an exception if an HTTP error was received. Any
     * HTTP status code above 399 is considered an error.
     *
     * Note that it is no longer recommended to use this method, use the send()
     * method instead.
     *
     * @param string $method
     * @param string $url
     * @param string|resource|null $body
     * @param array $headers
     * @return array
     */
    function request($method, $url = '', $body = null, array $headers = []) {
        if (!$this->_is_allowed($method)) {
    	    error_log('Method ' . $method . ' not supported by server');
	    return false;
        }

	// Prepare URL
	$uri = $this->connection['path'];
	if ($url != '/') $uri .= $url;
	$uri = $this->connection['uri'] . str_replace('%2F', '/', rawurlencode($uri));

	// Init CURL
	$ch = curl_init($uri);
	curl_setopt($ch, CURLOPT_USERAGENT, $this->connection['ua']);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HEADERFUNCTION, array($this, 'readHeader'));
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->connection['ssl']? 1 : 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->connection['ssl']? 2 : 0);

	if ($this->connection['proxy'])
            curl_setopt($ch, CURLOPT_PROXY, $this->connection['proxy']);

        if (is_string($this->connection['user']) && $this->connection['auth']) {
	    switch($this->connection['auth']) {
		case 'basic':
		    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		    break;
		case 'digest':
		    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		    break;
		default:
    		    error_log('Unknown authorization scheme');
		    return false;
	    }

    	    curl_setopt($ch, CURLOPT_USERPWD, $this->connection['user'] . ':' . @$this->connection['password']);
	}

	// Prepare headers
	if (empty($header['Content-Type']))
	    $header['Content-Type'] = 'application/octet-stream';

	$this->_headers = array();
	$req_header = array();
	foreach ($headers as $header => $value) {
	    $req_header[] = "$header: $value";
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $req_header);

	if (!empty($body)) {
	    curl_setopt($ch, CURLOPT_POST, true);

	    if (is_resource($body)) {
		curl_setopt($ch, CURLOPT_UPLOAD, 1);
		curl_setopt($ch, CURLOPT_INFILE, $body);
	    } else
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	}

	$content = curl_exec($ch);
	$res = curl_getinfo( $ch );
	curl_close($ch);

        return [
            'body' => $content,
            'statusCode' => (int)$res['http_code'],
            'headers' => array_change_key_case($this->_headers)
        ];
    }


    /**
     * Parse HTTP header
     *
     * @access private
     * @param handler $ch
     * @param string $header
     * @return int
     *
    */
    private function readHeader($ch, $header) {
	$h = substr($header, 0, -2);

	if (!empty($h)) {
	    $res = explode(': ', $h);

	    if (count($res) == 2)
    		$this->_headers[$res[0]] = $res[1];
	}

	return strlen($header);
    }


    /**
     * Parse XML DOM
     *
     * @param DOMElement dom
     * @return string
     *
    */
    static function unserialize(DOMElement $prop) {
        $xpath = new DOMXPath($prop->ownerDocument);
        $xpath->registerNamespace('d','urn:DAV');

        // Finding the 'response' element
        $xResponses = $xpath->evaluate(
            'd:response',
            $prop
        );

        $result = [];

        for($jj=0; $jj < $xResponses->length; $jj++) {
            $xResponse = $xResponses->item($jj);

            // Parsing 'href'
    	    if ($xResponse->firstChild && $xResponse->firstChild->localName == 'href')
        	$href = $xResponse->firstChild->textContent;
	    else return;

            $properties = [];

            // Parsing 'propstat'
            $xPropstat = $xpath->query('d:propstat', $xResponse);

            for($ii=0; $ii < $xPropstat->length; $ii++) {

                // Parsing 'status'
                $status = $xpath->evaluate('string(d:status)', $xPropstat->item($ii));

                list(,$statusCode,) = explode(' ', $status, 3);

                // Parsing 'prop'
		if ($statusCode == 200)
            	    $properties = [];

    		    foreach($xPropstat->item($ii)->childNodes as $propNode) {
        		if ($propNode->localName != 'prop') continue;

        		foreach($propNode->childNodes as $propNodeData) {
            		    /* If there are no elements in here, we actually get 1 text node, this special case is dedicated to netdrive */
            		    if ($propNodeData->nodeType != XML_ELEMENT_NODE) continue;

            		    $propertyName = $propNodeData->localName;
			    if ($propertyName == 'resourcetype') {
            			$properties[$propertyName] = ($propNodeData->firstChild)?
				    $propNodeData->firstChild->localName : '';
			    } else $properties[$propertyName] = $propNodeData->textContent;
        		}
		    }
            }

            $result[$href] = $properties;
        }

        return $result;
    }


    /**
     * This method provides a generic way to load a DOMDocument for WebDAV use.
     *
     * @param string $xml
     * @return DOMDocument
     */
    static function loadDOMDocument($xml) {
        if (empty($xml))
            throw new Exception('Empty XML document sent');

        // The BitKinex client sends xml documents as UTF-16. PHP 5.3.1 (and presumably lower)
        // does not support this, so we must intercept this and convert to UTF-8.
        if (substr($xml, 0, 12) === "\x3c\x00\x3f\x00\x78\x00\x6d\x00\x6c\x00\x20\x00") {

            // Note: the preceeding byte sequence is "<?xml" encoded as UTF_16, without the BOM.
            $xml = iconv('UTF-16LE','UTF-8',$xml);

            // Because the xml header might specify the encoding, we must also change this.
            // This regex looks for the string encoding="UTF-16" and replaces it with
            // encoding="UTF-8".
            $xml = preg_replace('|<\?xml([^>]*)encoding="UTF-16"([^>]*)>|u','<?xml\1encoding="UTF-8"\2>',$xml);

        }

        // Retaining old error setting
        $oldErrorSetting =  libxml_use_internal_errors(true);
        // Fixes an XXE vulnerability on PHP versions older than 5.3.23 or
        // 5.4.13.
        $oldEntityLoaderSetting = libxml_disable_entity_loader(true);

        // Clearing any previous errors
        libxml_clear_errors();

        $dom = new DOMDocument();

        // We don't generally care about any whitespace
        $dom->preserveWhiteSpace = false;

        $xml = preg_replace("/xmlns(:[A-Za-z0-9_]*)?=(\"|\')DAV:(\\2)/","xmlns\\1=\\2urn:DAV\\2", $xml);

        $dom->loadXML($xml, LIBXML_NOWARNING | LIBXML_NOERROR);

        if ($error = libxml_get_last_error()) {
            libxml_clear_errors();
            throw new Exception('The request body had an invalid XML body. (message: ' . $error->message . ', errorcode: ' . $error->code . ', line: ' . $error->line . ')');
        }

        // Restoring old mechanism for error handling
        if ($oldErrorSetting === false) libxml_use_internal_errors(false);
        if ($oldEntityLoaderSetting === false) libxml_disable_entity_loader(false);

        return $dom;
    }
}
