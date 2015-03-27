<?php
/**
 * Class Pydio Flysystem Adapter
 * @author  Artem Bondarenko taxist0@gmail.com
 */
namespace Tprog\Adapter;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use League\Flysystem\Adapter\AbstractAdapter;


class Pydio extends AbstractAdapter
{

    /**
     * @var string path prefix
     */
    protected $pathPrefix = '/';

    /**
     * File permission value
     * @var array
     */
    protected static $permissions = [
        'public'  => 744,
        'private' => 700,
    ];

    /**
     * Allow actions and url
     * @var array
     */
    protected static $actions = [
        'upload'      => '/upload/',
        'mkfile'      => '/mkfile/',
        'mkdir'       => '/mkdir/',
        'purge'       => '/purge/',
        'ls'          => '/ls/',
        'download'    => '/download/',
        'get_content' => '/get_content/',
        'put_content' => '/put_content/put/',
        'rename'      => '/rename/',
        'copy'        => '/copy/',
        'move'        => '/move/',
        'delete'      => '/delete/',
        'chmod'       => '/chmod/',

    ];

    /**
     * We will need the following two actions "KEYSTORE_GENERATE_AUTH_TOKEN" and "UPLOAD"
     * @var string generateAuthTokenUrl
     */
    protected static $generateAuthTokenUrl = "pydio/keystore_generate_auth_token";

    /**
     * The current version of the python client is appending a device-id
     * @var string deviceId
     */
    protected static $deviceId = "";

    /**
     * @var
     */
    protected $pydioRestUser;

    /**
     * @var
     */
    protected $pydioRestPw;

    /**
     * @var
     */
    protected $pydioRestApi;

    /**
     * @var string authToken
     */
    protected $authToken = null;

    /**
     * @var
     */
    protected $authPrivate = null;

    /**
     * Target workspace-id
     * @var string workspaceId
     */
    protected $workspaceId = '';

    /**
     * Constructor.
     *
     * @param AuthService $auth
     * @param string $api_url
     * @param string $key
     */
    public function __construct($pydioRestUser, $pydioRestPw, $pydioRestApi, $workspaceId)
    {
        $this->workspaceId = $workspaceId;
        $this->pydioRestUser = $pydioRestUser;
        $this->pydioRestPw = $pydioRestPw;
        $this->pydioRestApi = $pydioRestApi;
    }

    /**
     * Generate authentication token
     * @param $actionUrl
     * @return bool|string
     * @throws \Exception
     */
    protected function getAuthToken($actionUrl)
    {

        // Generate authentication token first...
        if (!$this->authToken) {

            $apiUrl = $this->pydioRestApi . self::$generateAuthTokenUrl . "/" . self::$deviceId;

            $curl = curl_init($apiUrl);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_USERPWD, $this->pydioRestUser . ':' . $this->pydioRestPw);

            if (!$response = curl_exec($curl)) {
                return false;
            }
            if (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
                throw new \Exception ($response);
            }

            curl_close($curl);

            $jsonResponse = json_decode($response);
            $this->authToken = $jsonResponse->t;
            $this->authPrivate = $jsonResponse->p;
        }
        // Build the authentication hash...
        $nonce = sha1(mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax());
        $uri = "/api/" . $this->workspaceId . $actionUrl;
        $message = $uri . ":" . $nonce . ":" . $this->authPrivate;
        $hash = hash_hmac("sha256", $message, $this->authToken);
        $authHash = $nonce . ":" . $hash;
        return $authHash;
    }

    /**
     * API request
     * @param $action
     * @param $path
     * @param array $postData
     * @return mixed
     * @throws \Exception
     */
    protected function request($action, $path, $postData = [])
    {
        $actionUrl = self::$actions[$action] . $path;

        $apiUrl = $this->pydioRestApi . $this->workspaceId . $actionUrl;

        $authHash = $this->getAuthToken($actionUrl);

        $curl = curl_init($apiUrl);
        $curlPostData = [
            "force_post"  => urlencode("true"),
            "auth_hash"   => $authHash,
            "auth_token"  => $this->authToken,
            "auto_rename" => urlencode("false"),
        ];
        $curlPostData = array_merge($curlPostData, $postData);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $curlPostData);
        $response = curl_exec($curl);

        if (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
            throw new \Exception ($response);
        }
        curl_close($curl);
        return $response;

    }

    /**
     * Ensure the root directory exists.
     *
     * @param   string $root root directory path
     * @return  string  real path to root
     */
    protected function ensureDirectory($root)
    {
        if (!$this->has($root)) {
            return false;
        }
        return $root;
    }

    /**
     * Check whether a file is present
     *
     * @param   string $path
     * @return  boolean
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Write a file
     *
     * @param $path
     * @param $contents
     * @param null $config
     * @return array|bool
     */
    public function write($path, $contents, Config $config)
    {
        if ($this->getDirname($path) && !$this->has($this->getDirname($path))) {
            $this->createDir($this->getDirname($path), $config);
        }

        $this->request('mkfile', $path);

        $postData = [
            "file"    => '/' . $path,
            'content' => $contents,
            '_method' => 'put',
        ];
        $this->request('put_content', $path, $postData);

        $size = strlen($contents);
        $type = 'file';

        if ($visibility = $config->get('visibility')) {
            $result['visibility'] = $visibility;
            $this->setVisibility($path, $visibility);
        }

        return compact('contents', 'type', 'size', 'path');
    }

    /**
     * Write using a stream
     *
     * @param $path
     * @param $resource
     * @param null $config
     * @return array|bool
     */
    public function writeStream($path, $resource, Config $config)
    {
        if ($this->getDirname($path) && !$this->has($this->getDirname($path))) {
            $this->createDir($this->getDirname($path), $config);
        }

        $string = '';
        while (!feof($resource)) {
            $string .= fread($resource, 1024);
        }

        $postData = [
            "dir"    => $this->getDirname($path),
            "file"    => '/' . $path,
            "xhr_uploader"                      => urlencode("true"),
            "auto_rename"                       => urlencode("false"),
            "urlencoded_filename"               => '/'.(basename($path)),
            'userfile_0"; filename="fake-name"' => $string,
        ];

        $url=$this->getDirname($path)?'put/'.$this->getDirname($path):$path;
        $this->request('upload', $url , $postData);

        if ($visibility = $config->get('visibility')) {
            $this->setVisibility($path, $visibility);
        }

        return compact('path', 'visibility');
    }

    /**
     * Get a read-stream for a file
     *
     * @param $path
     * @return array|bool
     */
    public function readStream($path)
    {
        $response = $this->read($path);
        $stream = fopen('data://text/plain;base64,' . base64_encode($response['contents']), 'r');

        return compact('stream', 'path');
    }

    /**
     * Update a file using a stream
     *
     * @param   string $path
     * @param   resource $resource
     * @param   mixed $config Config object or visibility setting
     * @return  array|bool
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Update a file
     *
     * @param   string $path
     * @param   string $contents
     * @param   mixed $config Config object or visibility setting
     * @return  array|bool
     */
    public function update($path, $contents, Config $config)
    {
        if (!$this->has($path)) {
            throw new \Exception ('File not found' . $path);
        }

        $postData = [
            "file"    => '/' . $path,
            'content' => $contents,
            '_method' => 'put',
        ];
        $this->request('put_content', $path, $postData);

        $size = strlen($contents);
        $mimetype = $this->getMimetype($path);

        if ($visibility = $config->get('visibility')) {
            $result['visibility'] = $visibility;
            $this->setVisibility($path, $visibility);
        }

        return compact('path', 'size', 'contents', 'mimetype');
    }

    /**
     * Read a file
     *
     * @param   string $path
     * @return  array|bool
     */
    public function read($path)
    {
        $contents = $this->request('download', $path);
        return compact('contents', 'path');
    }

    /**
     * Move a file
     *
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function move($path, $new_name)
    {

        if (!$this->has($this->getDirname($new_name))) {
            $this->createDir($this->getDirname($new_name), new Config());
        }

        $postData = [
            "file"         => '/' . $path,
            "filename_new" => '/' . basename($new_name),
            "dest"         => '/' . $this->getDirname($new_name),
            "dir"          => '/' . $this->getDirname($path),
        ];
        $contents = $this->request('move', $path, $postData);
        return compact('contents', 'path');

    }

    /**
     * Rename a file
     *
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function rename($path, $new_name)
    {

        if (!$this->has($this->getDirname($new_name))) {
            $this->createDir($this->getDirname($new_name), new Config());
        }

        $postData = [
            "file"         => '/' . $path,
            "filename_new" => '/' . basename($new_name),
//            "dest"         => '/' . $this->getDirname($new_name),
//            "dir"          => '/' . $this->getDirname($path),
        ];
        $contents = $this->request('rename', $path, $postData);
        return compact('contents', 'path');

    }
    /**
     * Copy a file
     *
     * @param $path
     * @param $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        if (!$this->has($this->getDirname($newpath))) {
            $this->createDir($this->getDirname($newpath), new Config());
        }

        $postData = [
            "file" => '/' . $path,
            "dest" => '/' . $this->getDirname($newpath),
            "dir"  => '/' . $this->getDirname($path),
        ];
        return $this->request('copy', $path, $postData);
    }

    /**
     * Delete a file
     *
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        $postData = [
            "file" => '/' . $path,
        ];
        $contents = $this->request('delete', $path, $postData);

        return compact('contents', 'path');
    }

    /**
     * List contents of a directory
     *
     * @param string $directory
     * @param bool $recursive
     * @return array
     */
    public function listContents($directory='', $recursive = false)
    {
          $response = $this->request('ls', $directory, ["dir" => '/'.($directory)]);

        if ($xml = simplexml_load_string($response)) {
            $result = $this->normalizeFileInfo($response);
        } else {
            throw new \Exception ('Request error');
        }

        return $result;
    }

    /**
     * Get the metadata of a file
     *
     * @param $path
     * @return array
     */
    public function getMetadata($path)
    {
       if (!$path){
           return true;
       }
        $response = $this->request('ls', $path, ["file" => '/'.($path)]);

        $xml = simplexml_load_string($response);
        if (count($xml)) {
            return $this->normalizeFileInfo($xml);
        } else {
            return false;
        }
    }

    /**
     * Get the size of a file
     *
     * @param $path
     * @return array
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file
     *
     * @param $path
     * @return array
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file
     *
     * @param $path
     * @return array
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the permissions of a file
     *
     * @param $path
     * @return array
     */
    public function getPermission($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the visibility of a file
     *
     * @param $path
     * @return array|void
     */
    public function getVisibility($path)
    {
        $permissions = octdec(substr(sprintf('%o', $this->getPermission($path)), -4));
        $visibility = $permissions & 0044 ? AdapterInterface::VISIBILITY_PUBLIC : AdapterInterface::VISIBILITY_PRIVATE;

        return compact('visibility');
    }

    /**
     * Set the visibility of a file
     *
     * @param $path
     * @param $visibility
     * @return array|void
     */
    public function setVisibility($path, $visibility)
    {
        $this->request('chmod', $this->getDirname($path), [
            "file"        => '/' . ($path),
            "chmod_value" => static::$permissions[$visibility]]);
        return compact('visibility');
    }

    /**
     * Create a directory
     *
     * @param   string $dirname directory name
     * @param   array|Config $options
     *
     * @return  bool
     */
    public function createDir($dirname, Config $config)
    {

        if ($this->has($dirname)) {
            return ['path' => $dirname, 'type' => 'dir'];
        }

        $create = '';
        foreach (explode('/', $dirname) as $dirname) {
            $create .= '/' . $dirname;
            if (!$this->request('mkdir', $create)) {
                return false;
            }
        }

        return ['path' => $create, 'type' => 'dir'];
    }

    /**
     * Delete a directory
     *
     * @param $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    /**
     * Normalize the file info
     * @param xml $xml
     * @return array
     */
    protected function normalizeFileInfo($xml)
    {
        $normalized = [
            'type'          => ($xml->tree['is_file'] == 'true') ? 'file' : 'dir',
            'path'          => (string)$xml->tree['filename'],
            'timestamp'     => (string)$xml->tree['ajxp_modiftime'],
            'permission'    => (string)$xml->tree['file_perms'],
            'mimestring_id' => (string)$xml->tree['mimestring_id'],
            'mimestring'    => (string)$xml->tree['mimestring'],
            'mimetype'      => (string)$xml->tree['mimestring'],
        ];
        if ($normalized['type'] === 'file') {
            $normalized['size'] = (string)$xml->tree['bytesize'];
        }

        return $normalized;
    }

    /**
     * Check root folder
     * @param $path
     * @return string
     */
    protected function getDirname($path)
    {
        if (dirname($path) == '.'){

        }
        return (dirname($path) == '.') ? '' : dirname($path);
    }

}
