<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Repository to access Evernote content.
 *
 * @package    repository
 * @subpackage evernote
 * @copyright  2012 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');
require_once($CFG->libdir . '/oauthlib.php');

// Allow for co-existence with another plugin using Evernote API.
if (!isset($GLOBALS['THRIFT_ROOT'])) {
    $GLOBALS['THRIFT_ROOT'] = __DIR__ . '/lib/evernote/lib';
}
if (!class_exists('TException')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/Thrift.php');
}
if (!class_exists('THttpClient')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/transport/THttpClient.php');
}
if (!class_exists('TBinaryProtocol')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/protocol/TBinaryProtocol.php');
}
if (!class_exists('\EDAM\NoteStore\NoteStoreClient')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/NoteStore/NoteStore.php');
}
if (!class_exists('\EDAM\Types\NoteSortOrder')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/Types/Types_types.php');
}
if (!class_exists('\EDAM\UserStore\UserStoreClient')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/UserStore/UserStore.php');
}
if (!class_exists('\EDAM\Error\EDAMUserException')) {
    require_once($GLOBALS['THRIFT_ROOT'] . '/packages/Errors/Errors_types.php');
}

use EDAM\NoteStore\NoteStoreClient;
use EDAM\NoteStore\NoteFilter;
use EDAM\NoteStore\NotesMetadataResultSpec;
use EDAM\UserStore\UserStoreClient;
use EDAM\Types\NoteSortOrder;
use EDAM\Error\EDAMErrorCode;
use EDAM\Error\EDAMUserException;

/**
 * Repository class to access Evernote files.
 *
 * Throughout this class we are not using any natural ordering because the ordering provided by
 * Evernote API is not natural and therefore we keep consistency.
 *
 * @package    repository
 * @subpackage evernote
 * @copyright  2012 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_evernote extends repository {

    /**
     * URL to the API production services.
     * @var string
     */
    const API_PROD = 'https://www.evernote.com';

    /**
     * URL to the API development services.
     * @var string
     */
    const API_DEV = 'https://sandbox.evernote.com';

    /**
     * Prefix for the user preferences.
     * @var string
     */
    const SETTINGPREFIX = 'repository_evernote_';

    /**
     * URL to the API.
     * @var string
     */
    protected $api;

    /**
     * URL to the Web access of Evernote.
     * @var string
     */
    protected $manageurl = 'https://www.evernote.com/Home.action';

    /**
     * URL to the Web logout.
     * @var string
     */
    protected $logouturl = 'https://www.evernote.com/Logout.action';

    /**
     * Token received after a valid OAuth authentication.
     * @var string
     */
    protected $accesstoken = null;

    /**
     * Note store URL retrieved after successful OAuth authentication.
     * @var string
     */
    protected $notestoreurl = null;

    /**
     * Number of items to display per page.
     * TODO: When paging will be fixed, this limit should be handled for searchs, notebooks, tags and notes.
     * See EDAM_USER_NOTEBOOKS_MAX, EDAM_USER_NOTES_MAX, EDAM_USER_SAVED_SEARCHES_MAX and EDAM_USER_TAGS_MAX.
     * @var int
     */
    protected $itemsperpage = 250;

    /**
     * Enable pagination, will probably be removed after MDL-35664.
     * @var bool
     */
    protected $enablepaging = false;

    /**
     * To limit the query to notes with attachments only. This does not affect the search.
     * @var boolean
     */
    protected $noteswithattachmentsonly = true;

    /**
     * Maximum results of a search query. Must respect EDAM_USER_NOTES_MAX.
     * @var int
     */
    protected $searchmaxresults = 50;

    /**
     * Enable dynamic loading of the search results.
     * @var bool
     */
    protected $searchdynload = true;

    /**
     * Session key to store the path in, will probably be removed after MDL-35664.
     * @var string
     */
    protected $sessionkey = 'repository_evernote_path';

    /**
     * Prefix for the user preferences.
     * @var string
     * @deprecated since 1.1.0
     */
    protected $settingprefix = 'evernote_';

    /**
     * SSL Compatibility mode.
     * Some versions of OpenSSL will conflict with Evernote when validating the certificate.
     * When we can, we force SSL v3, but sometimes we might have to disable SSL complitely.
     * Using this feature is highly discouraged and not supported by Moodle < 2.3.2!
     * @see http://discussion.evernote.com/topic/26978-ssl-handshake-problems/
     * @var bool
     * @deprecated since 1.0.1
     */
    protected $sslcompatibilitymode = false;

    /**
     * Cache for the NoteStoreClient object.
     * @var NoteStoreClient
     */
    protected $notestore;

    /**
     * Cache for the UserStoreClient object.
     * @var UserStoreClient
     */
    protected $userstore;

    /**
     * Cache for the oauth_helper object.
     * @var oauth_helper
     */
    protected $oauth;

    /**
     * The requests cache store.
     * @var cache_session
     */
    protected $cachestore;

    /**
     * Constructor
     *
     * @param int $repositoryid repository instance id
     * @param int|stdClass $context a context id or context object
     * @param array $options repository options
     * @param int $readonly indicate this repo is readonly or not
     */
    function __construct($repositoryid, $context = SYSCONTEXTID, $options = array(), $readonly = 0) {
        parent::__construct($repositoryid, $context, $options, $readonly);

        $this->accesstoken = get_user_preferences(self::SETTINGPREFIX.'accesstoken', null);
        $this->notestoreurl = get_user_preferences(self::SETTINGPREFIX.'notestoreurl', null);
    }

    /**
     * Build the breadcrumb from a path.
     *
     * The path must contain information about the crumb names for them to be used.
     *
     * @param string $path to create a breadcrumb from
     * @return array containing name and path of each crumb
     */
    public function build_breadcrumb($path) {
        $breadcrumb = array(array(
            'name' => get_string('evernote', 'repository_evernote'),
            'path' => $this->build_node_path('root')
        ));
        $bread = explode('/', $path);
        $crumbtrail = '';
        foreach ($bread as $crumb) {
            list($mode, $guid, $name) = $this->explode_node_path($crumb);
            switch ($mode) {
                case 'all':
                    $breadcrumb[] = array(
                        'name' => get_string('allnotes', 'repository_evernote'),
                        'path' => $this->build_node_path($mode)
                    );
                    break;
                case 'tags':
                    $breadcrumb[] = array(
                        'name' => get_string('tags', 'repository_evernote'),
                        'path' => $this->build_node_path($mode, '', '', $crumbtrail)
                    );
                    if (!empty($name)) {
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $this->build_node_path($mode, $guid, $name, $crumbtrail)
                        );
                    }
                    break;
                case 'notebooks':
                    $breadcrumb[] = array(
                        'name' => get_string('notebooks', 'repository_evernote'),
                        'path' => $this->build_node_path($mode, '', '', $crumbtrail)
                    );
                    break;
                case 'stack':
                    $breadcrumb[] = array(
                        'name' => $guid,
                        'path' => $this->build_node_path($mode, $guid, '', $crumbtrail)
                    );
                    break;
                case 'notebook':
                    if (!empty($name)) {
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $this->build_node_path($mode, $guid, $name, $crumbtrail)
                        );
                    }
                    break;
                case 'searchs':
                    $breadcrumb[] = array(
                        'name' => get_string('savedsearchs', 'repository_evernote'),
                        'path' => $this->build_node_path($mode, '', '', $crumbtrail)
                    );
                    if (!empty($name)) {
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $this->build_node_path($mode, $guid, $name, $crumbtrail)
                        );
                    }
                    break;
                case 'note':
                    if (!empty($name)) {
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $this->build_node_path($mode, $guid, $name, $crumbtrail)
                        );
                    }
                    break;
                case 'mysearch':
                    $breadcrumb[] = array(
                        'name' => get_string('searchresults'),
                        'path' => $this->build_node_path($mode, $guid, '', $crumbtrail)
                    );
                    break;

            }
            $tmp = end($breadcrumb);
            $crumbtrail = $tmp['path'];
        }
        return $breadcrumb;
    }

    /**
     * Generates a safe path to a node.
     *
     * @param string $key of the node, should match [a-z]+
     * @param string $value of the node, will be URL encoded.
     * @param string $name of the node, will be URL encoded.
     * @param string $root to append the node on, must be a result of this function.
     * @return string path to the node.
     */
    public function build_node_path($key, $value = '', $name = '', $root = '') {
        $path = $key . ':' . urlencode($value);
        if (!empty($name)) {
            $path .= '|' . urlencode($name);
        }
        if (!empty($root)) {
            $path = trim($root, '/') . '/' . $path;
        }
        return $path;
    }

    /**
     * Build the list of resources contained in a note.
     *
     * @param Note $note object to read the information from.
     * @return array of resources and their information.
     */
    public function build_note_content($note) {
        global $OUTPUT;
        $resources = array();
        foreach ((array) $note->resources as $resource) {
            // Skip files without a valid name.
            if (empty($resource->attributes->fileName)) {
                continue;
            }
            $resources[$resource->attributes->fileName . '-' . $resource->guid] = array(
                'title' => $resource->attributes->fileName,
                'date' => $note->updated / 1000,
                'datemodified' => $note->updated / 1000,
                'datecreated' => $note->created / 1000,
                'size' => $resource->data->size,
                'source' => 'resource:' . $resource->guid . '|note:' . $note->guid,
                'thumbnail' => $OUTPUT->pix_url(file_extension_icon($resource->attributes->fileName, 64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
            );
            self::ksort($resources);
        }
        // Get rid of the keys as file picker does not support them.
        return array_values($resources);
    }

    /**
     * Build the list of notebooks from a notebook list.
     *
     * @param array $notebookslist list of Notebook objects.
     * @param string $path the path to build the list on.
     * @param string $stack limit the result to notebooks in this stack.
     * @return array of notebooks and their information.
     */
    public function build_notebooks_list($notebookslist, $path, $stack = '') {
        global $OUTPUT;
        $path = !empty($path) ? trim($path, '/') . '/' : '';
        $notebooks = array();
        foreach ($notebookslist as $notebook) {
            $notebookstack = $notebook->stack;
            if (!empty($stack) && $stack != $notebookstack) {
                // We filter per stack, and this stack is not the one we are looking for. Skipping!
                continue;
            } else if (empty($stack) && !empty($notebookstack)) {
                // If we do not filter per stack, and this notebook has a stack, we populate it in the list.
                // Collision between stacks and notebooks in unlikely to happen, but well...
                $stackcode = $notebookstack . '-=-1Make2Me3Unique4Please5-=-';
                if (isset($notebooks[$stackcode])) {
                    continue;
                }
                $notebooks[$stackcode] = array(
                    'title' => $notebookstack,
                    'path' => $path . 'stack:' . $notebookstack,
                    'path' => $this->build_node_path('stack', $notebookstack, '', $path),
                    'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64,
                    'children' => array()
                );
            } else {
                // If we arrived here, we probably want to display the notebook.
                $notebooks[$notebook->name] = array(
                    'title' => $notebook->name,
                    'path' => $this->build_node_path('notebook', $notebook->guid, $notebook->name, $path),
                    'date' => $notebook->serviceUpdated / 1000,
                    'datemodified' => $notebook->serviceUpdated / 1000,
                    'datecreated' => $notebook->serviceCreated / 1000,
                    'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64,
                    'children' => array(),
                );
            }
        }
        self::ksort($notebooks);
        return $notebooks;
    }

    /**
     * Build the list of notes from a note list.
     *
     * This method does not take care of ordering and Evernote SDK will do that for us.
     *
     * @param array $notelist list of Note or NoteMetadata objects.
     * @param string $path the path to build the list on.
     * @param string $includeresources whether to include the resources as children of the note.
     * @return array of notes and their information.
     */
    public function build_notes_list($notelist, $path, $includeresources = false) {
        global $OUTPUT;
        $path = !empty($path) ? trim($path, '/') . '/' : '';
        $notes = array();
        foreach ($notelist as $note) {
            $children = $includeresources ? $this->build_note_content($note, $path) : array();
            $notes[] = array(
                'title' => $note->title,
                'path' => $this->build_node_path('note', $note->guid, $note->title, $path),
                'date' => $note->updated / 1000,
                'datemodified' => $note->updated / 1000,
                'datecreated' => $note->created / 1000,
                'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
                'children' => $children,
            );
        }
        return $notes;
    }

    /**
     * Build the list of saved searchs.
     *
     * @param array $savedsearchs list of SavedSearch objects.
     * @param string $path the path to build the list on.
     * @return array of saved searchs and their information.
     */
    public function build_savedsearch_list($savedsearchs, $path) {
        global $OUTPUT;
        $path = !empty($path) ? trim($path, '/') . '/' : '';
        $searchs = array();
        foreach ($savedsearchs as $search) {
            $searchs[$search->name] = array(
                'title' => $search->name,
                'path' => $this->build_node_path('searchs', $search->guid, $search->name, $path),
                'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
                'children' => array(),
            );
        }
        self::ksort($searchs);
        return $searchs;
    }

    /**
     * Build the list of tags.
     *
     * @param array $tagslist of Tag objects.
     * @param string $path the path to build the list on.
     * @return array of tags and their information.
     */
    public function build_tags_list($tagslist, $path) {
        global $OUTPUT;
        $path = !empty($path) ? trim($path, '/') . '/' : '';
        // TODO handle nested tags.
        $tags = array();
        foreach ($tagslist as $tag) {
            $tags[$tag->name] = array(
                'title' => $tag->name,
                'path' => $this->build_node_path('tags', $tag->guid, $tag->name, $path),
                'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
                'children' => array(),
            );
        }
        self::ksort($tags);
        return $tags;
    }

    /**
     * Interface to read the cache.
     *
     * This is to preserve compatibility with version prior to Moodle 2.4.
     *
     * @param string $path
     * @return void
     */
    protected function cache_get($path) {
        global $CFG;
        // Cache is a feature only available from 2.4.
        if ($CFG->version < 2012120300) {
            return false;
        }
        if (!$this->cachestore) {
            require_once($CFG->dirroot . '/cache/classes/loaders.php');
            $this->cachestore = cache::make('repository_evernote', 'requests');
        }
        return $this->cachestore->get($path);
    }

    /**
     * Interface to purge the cache.
     *
     * This is to preserve compatibility with version prior to Moodle 2.4.
     *
     * @param string $path
     * @return void
     */
    protected function cache_purge() {
        $this->cache_get('Please create the cache object...');
        if (!$this->cachestore) {
            return;
        }
        // This purge doesn't seem to work, this could be a bug in the cache API.
        $this->cachestore->purge();
    }

    /**
     * Interface to write to the cache.
     *
     * This is to preserve compatibility with version prior to Moodle 2.4.
     *
     * @param string $path
     * @param mixed $data
     * @return void
     */
    protected function cache_set($path, $data) {
        if (!$this->cachestore) {
            return;
        }
        $this->cachestore->set($path, $data);
    }

    /**
     * Function called during the OAuth process as the callback URL.
     *
     * @return void
     */
    public function callback() {
        $token  = optional_param('oauth_token', '', PARAM_TEXT);
        $verifier  = optional_param('oauth_verifier', '', PARAM_TEXT);
        $secret = get_user_preferences(self::SETTINGPREFIX.'tokensecret', '');
        $access = $this->get_oauth()->get_access_token($token, $secret, $verifier);
        $notestore  = $access['edam_noteStoreUrl'];
        $userid  = $access['edam_userId'];
        $accesstoken  = $access['oauth_token'];
        set_user_preference(self::SETTINGPREFIX.'accesstoken', $accesstoken);
        set_user_preference(self::SETTINGPREFIX.'notestoreurl', $notestore);
        set_user_preference(self::SETTINGPREFIX.'userid', $userid);
    }

    /**
     * Check if the user is authenticated in this repository or not.
     *
     * @return bool true when logged in, false when not
     */
    public function check_login() {
        return !empty($this->accesstoken) && !empty($this->notestoreurl);
    }

    /**
     * Returns information about a node in a path.
     *
     * @see repository_evernote::build_node_path()
     * @param string $node to extrat information from.
     * @return array about the node.
     */
    public function explode_node_path($node) {
        list($key, $guid) = explode(':', $node, 2);
        $name = '';
        if (strpos($guid, '|') !== false) {
            list($guid, $name) = explode('|', $guid, 2);
            $name = urldecode($name);
        }
        $guid = urldecode($guid);
        return array(
            0 => $key,
            1 => $guid,
            2 => $name,
            'key' => $key,
            'guid' => $guid,
            'name' => $name
        );
    }

    /**
     * Find metadata of notes matching the filter.
     *
     * @param NoteFilter $filter of the search
     * @param int $offset of the results
     * @param int $limit number of results to get
     * @return NotesMedatadataList of search results
     */
    public function find_notes_metadata($filter, $offset, $limit) {
        if ($filter->order == null) {
            $filter->order = NoteSortOrder::TITLE;
            $filter->ascending = true;
        }
        if ($this->noteswithattachmentsonly && $filter->words == null) {
            $filter->words = 'resource:*';
        }
        $resultspec = new NotesMetaDataResultSpec(array(
            'includeTitle' => true,
            'includeCreated' => true,
            'includeUpdated' => true
        ));
        try {
            $result = $this->get_notestore()->findNotesMetadata($this->accesstoken, $filter, $offset, $limit, $resultspec);
        } catch (EDAMUserException $e) {
            if ($e->errorCode === EDAMErrorCode::PERMISSION_DENIED) {
                throw new repository_exception('nopermissiontoaccessnotes', 'repository_evernote');
            }
        }
        return $result;
    }

    /**
     * Return the API URL.
     *
     * @return string
     */
    protected function get_api_url() {
        if (empty($this->api)) {
            $this->api = self::API_PROD;
            $usedevapi = get_config('evernote', 'usedevapi');
            if (!empty($usedevapi)) {
                $this->api = self::API_DEV;
            }
        }
        return $this->api;
    }

    /**
     * Gets a file from a reference.
     *
     * @param mixed $reference to the file, {@link repository_evernote::get_file_reference()}
     * @param string $filename name to save the file to
     * @return array containing information about the file saved
     */
    public function get_file($reference, $filename = '') {
        $ref = unserialize($reference);
        $path = $this->prepare_file($filename);
        if (!empty($ref->url)) {
            // A share link has been created for that file.
            $c = new curl();
            $result = $c->download_one($ref->url, null, array('filepath' => $path,
                'timeout' => self::GETFILE_TIMEOUT, 'followlocation' => true));
            $info = $c->get_info();
            if ($result !== true || !isset($info['http_code']) || $info['http_code'] != 200) {
                // Do not do anything...
            } else {
                return array('path' => $path);
            }
        } else {
            $guid = false;
            if (isset($ref->guid)) {
                $guid = $ref->guid;
            } else if (strpos($ref->source, 'resource:') === 0) {
                list($lost, $guid) = explode(':', $ref->source, 2);
            }

            if (!empty($guid)) {
                // This file is downloaded directly from the user account. This should happen as the user is still
                // browsing the repository, meaning that we have access to his access token.
                try {
                    $resource = $this->get_notestore()->getResource($this->accesstoken, $guid, true, false, true, false);
                    $fp = fopen($path, 'wb');
                    fwrite($fp, $resource->data->body);
                    fclose($fp);
                    return array('path' => $path);
                } catch (Exception $e) {
                    // Don't do anything, the exception will be thrown at the end of this function.
                }
            }
        }
        throw new repository_exception('cannotdownload', 'repository');
    }

    /**
     * Called when synchronizing the file.
     *
     * @param string $reference Serialized reference.
     * @return array containing the filesize.
     */
    public function get_file_by_reference($reference) {
        $ref = unserialize($reference->reference);
        try {
            $c = new curl();
            $result = $c->head($ref->url);
            $curlinfo = $c->get_info();
            $return = array('filepath' => $ref->url);
            if (isset($curlinfo['http_code']) && $curlinfo['http_code'] == 200
                    && isset($curlinfo['download_content_length'])
                    && $curlinfo['download_content_length'] >= 0) {
                $return['filesize'] = $curlinfo['download_content_length'];
            }
            return (object) $return;
        } catch (Exception $e) {
            throw $e;
        }
        return null;
    }

    /**
     * Prepares the file for being a reference.
     *
     * Shares the note to get a direct link to the attachment.
     *
     * @param string $source source of the file.
     * @return string $reference serialized reference file.
     */
    public function get_file_reference($source) {
        global $USER;
        if (strpos($source, 'resource:') === 0) {
            list($resource, $note) = explode('|', $source, 2);
            list($lost, $guid) = explode(':', $resource, 2);
            list($lost, $noteid) = explode(':', $note, 2);

            $reference = new stdClass();
            $reference->source = $source;
            $reference->guid = $guid;
            $reference->noteid = $noteid;
            $reference->userid = $USER->id;
            $reference->username = fullname($USER);
            $reference->url = '';

            if (optional_param('usefilereference', false, PARAM_BOOL)) {
                try {
                    $user = $this->get_userstore()->getUser($this->accesstoken);
                    $usershareid = $user->shardId;
                    $sharekey = $this->get_notestore()->shareNote($this->accesstoken, $noteid);
                    $url = $this->get_api_url() . '/shard/' . $usershareid . '/sh/' . $noteid . '/' . $sharekey . '/res/' . $guid;
                    $reference->url = $url;
                } catch (Exception $e) {
                    throw new repository_exception('Error while sharing the note to get a download URL');
                }
            }
            return serialize($reference);
        }
        throw new coding_exception('Error while creating a reference object');
    }

    /**
     * Return information about the source of the reference.
     *
     * @param string $source
     * @return string
     */
    public function get_file_source_info($source) {
        global $USER;
        return 'Evernote (' . fullname($USER) . '): ' . $source;
    }

    /**
     * Browse the repository content.
     *
     * @param string $path we want to explore
     * @param int $page the page we are browsing
     * @return array of results
     */
    public function get_listing($path = '', $page = '') {
        global $OUTPUT, $SESSION;
        $result = array(
            'path' => array(),
            'list' => array(),
            'manage' => $this->manageurl,
            'logouturl' => $this->logouturl,
            'dynload' => true
        );
        $folders = array();
        $files = array();

        // The default path will be the following when we open the Filepicker.
        $path = empty($path) ? 'all:' : $path;

        // Paging.
        $page = empty($page) ? 1 : $page;
        $pages = 0;
        $offset = ($page - 1) * $this->itemsperpage;
        if ($page > 1) {
            // We are in paging mode, retrieve the path from the session. Remove this after MDL-35664.
            $path = $SESSION->{$this->sessionkey};
        } else {
            // Store the path in the session for later use. Remove this after MDL-35664.
            $SESSION->{$this->sessionkey} = $path;
        }

        // We analyse the path to extract what to browse.
        $trail = explode('/', $path);
        $uri = array_pop($trail);
        list($mode, $guid, $name) = $this->explode_node_path($uri);

        // Try to get the result from the cache.
        $cachekey = $uri . '@' . $page;
        if (($fromcache = $this->cache_get($cachekey)) !== false) {
            $files = $fromcache['files'];
            $folders = $fromcache['folders'];
        } else {
            // Build the path to get to what we are browsing.
            $rootpath = '';
            if (!empty($trail)) {
                $rootpath = implode('/', $trail);
            }

            switch ($mode) {
                // Display all the notes.
                case 'all':
                    $filter = new NoteFilter();
                    $notesmetadatalist = $this->find_notes_metadata($filter, $offset, $this->itemsperpage);
                    $pages = ceil($notesmetadatalist->totalNotes / $this->itemsperpage);
                    $folders = $this->build_notes_list($notesmetadatalist->notes, $path);
                    break;
                // Display the tags, or notes in the tags.
                case 'tags':
                    if (empty($guid)) {
                        $tags = $this->get_notestore()->listTags($this->accesstoken);
                        $folders = $this->build_tags_list($tags, $rootpath);
                    } else {
                        $filter = new NoteFilter(array('tagGuids' => array($guid)));
                        $notesmetadatalist = $this->find_notes_metadata($filter, $offset, $this->itemsperpage);
                        $pages = ceil($notesmetadatalist->totalNotes / $this->itemsperpage);
                        $folders = $this->build_notes_list($notesmetadatalist->notes, $path);
                    }
                    break;
                // Display the notebooks and stacks.
                case 'notebooks':
                    $notebooks = $this->get_notestore()->listNotebooks($this->accesstoken);
                    $folders = $this->build_notebooks_list($notebooks, $path);
                    break;
                // Display notebooks within a stack.
                case 'stack':
                    $notebooks = $this->get_notestore()->listNotebooks($this->accesstoken);
                    $folders = $this->build_notebooks_list($notebooks, $path, $guid);
                    break;
                // Display the notes in a notebook.
                case 'notebook':
                    $filter = new NoteFilter(array('notebookGuid' => $guid));
                    $notesmetadatalist = $this->find_notes_metadata($filter, $offset, $this->itemsperpage);
                    $pages = ceil($notesmetadatalist->totalNotes / $this->itemsperpage);
                    $folders = $this->build_notes_list($notesmetadatalist->notes, $path);
                    break;
                // Display the saved searchs.
                case 'searchs':
                    if (empty($guid)) {
                        $savedsearchs = $this->get_notestore()->listSearches($this->accesstoken);
                        $folders = $this->build_savedsearch_list($savedsearchs, $rootpath);
                    } else {
                        $search = $this->get_notestore()->getSearch($this->accesstoken, $guid);
                        $filter = new NoteFilter(array('words' => $search->query));
                        $notesmetadatalist = $this->find_notes_metadata($filter, $offset, $this->itemsperpage);
                        $pages = ceil($notesmetadatalist->totalNotes / $this->itemsperpage);
                        $folders = $this->build_notes_list($notesmetadatalist->notes, $path);
                    }
                    break;
                // This is a note.
                case 'note':
                    $note = $this->get_notestore()->getNote($this->accesstoken, $guid, false, false, false, false);
                    $files = $this->build_note_content($note);
                    break;
                // Custom search from the user.
                case 'mysearch':
                    return $this->search($guid, 0);
                    break;
                // Display the default choices.
                case 'root':
                default:
                    $options = array(
                        'all' => get_string('allnotes', 'repository_evernote'),
                        'notebooks' => get_string('notebooks', 'repository_evernote'),
                        'tags' => get_string('tags', 'repository_evernote'),
                        'searchs' => get_string('savedsearchs', 'repository_evernote')
                    );
                    foreach ($options as $key => $option) {
                        $folders[] = array(
                            'title' => $option,
                            'path' => $this->build_node_path($key),
                            'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                            'thumbnail_height' => 64,
                            'thumbnail_width' => 64,
                            'children' => array()
                        );
                    }
                    break;
            }
        }

        // Set in the cache, before filtering because the filters can be different
        // from one page to another, but the cache should remain the same.
        $this->cache_set($cachekey, array(
            'files' => $files,
            'folders' => $folders
        ));

        // Filtering the files to remove non-compatible files.
        $files = array_filter($files, array($this, 'filter'));

        $breadcrumb = $this->build_breadcrumb($path);
        $result['path'] = $breadcrumb;
        $result['list'] = array_merge($folders, $files);
        if (!empty($pages) && $this->enablepaging) {
            $result['page'] = $page;
            $result['pages'] = $pages;
        }

        return $result;
    }

    /**
     * Return the object to call Evernote's API
     *
     * @return NoteStoreClient object
     */
    protected function get_notestore() {
        if (empty($this->notestore)) {
            $parts = parse_url($this->notestoreurl);
            if (!isset($parts['port'])) {
                if ($parts['scheme'] === 'https') {
                    $parts['port'] = 443;
                } else {
                    $parts['port'] = 80;
                }
            }
            $notestorehttpclient = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $notestoreprotocol = new TBinaryProtocol($notestorehttpclient);
            $this->notestore = new NoteStoreClient($notestoreprotocol, $notestoreprotocol);
        }
        return $this->notestore;
    }

    /**
     * Get the OAuth object.
     *
     * @return oauth_helper object.
     */
    protected function get_oauth() {
        if (empty($this->oauth)) {
            $config = get_config('evernote');
            $callbackurl = new moodle_url('/repository/repository_callback.php', array(
                'callback' => 'yes',
                'repo_id' => $this->id
            ));

            $args['oauth_consumer_key'] = $config->key;
            $args['oauth_consumer_secret'] = $config->secret;
            $args['oauth_callback'] = $callbackurl->out(false);
            $args['api_root'] = $this->get_api_url();
            $args['request_token_api'] = $this->get_api_url() . '/oauth';
            $args['access_token_api'] = $this->get_api_url() . '/oauth';
            $args['authorize_url'] = $this->get_api_url() . '/OAuth.action';
            $this->oauth = new oauth_helper($args);
        }
        return $this->oauth;
    }

    /**
     * Get the references details (human readable).
     *
     * @param string $reference serialised reference.
     * @param integer $filestatus file status.
     * @return string
     */
    public function get_reference_details($reference, $filestatus = 0) {
        // Not using === on purpose.
        if ($filestatus == 0) {
            $ref = unserialize($reference);
            $a = (object) array('name' => $this->get_name(), 'fullname' => $ref->username);
            return get_string('referencedetails', 'repository_evernote', $a);
        } else {
            return get_string('lostsource', 'repository', '');
        }
    }

    /**
     * Return the list of options this repository supports.
     *
     * @return array of option names
     */
    public static function get_type_option_names() {
        $options = array('key', 'secret', 'usedevapi');
        return array_merge(parent::get_type_option_names(), $options);
    }

    /**
     * Return the User Store
     *
     * @return UserStoreClient object
     */
    protected function get_userstore() {
        if (empty($this->userstore)) {
            $parts = parse_url($this->get_api_url() . '/edam/user');
            if (!isset($parts['port'])) {
                if ($parts['scheme'] === 'https') {
                    $parts['port'] = 443;
                } else {
                    $parts['port'] = 80;
                }
            }
            $userstorehttpclient = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $userstoreprotocol = new TBinaryProtocol($userstorehttpclient);
            $this->userstore = new UserStoreClient($userstoreprotocol, $userstoreprotocol);
        }
        return $this->userstore;
    }

    /**
     * Sort an array by key.
     *
     * @param array $array
     * @return void
     */
    public static function ksort(&$array) {
        // The class collatorlib has been deprecated from 2.6.
        if (class_exists('core_collator')) {
            core_collator::ksort($array, core_collator::SORT_NATURAL);
        } else{
            collatorlib::ksort($array, collatorlib::SORT_NATURAL);
        }
    }

    /**
     * Action to perform when the user clicks the logout button.
     *
     * @return string from {@link evernote_repository::print_login()}
     */
    public function logout() {
        $this->cache_purge();
        set_user_preference(self::SETTINGPREFIX.'accesstoken', '');
        set_user_preference(self::SETTINGPREFIX.'notestoreurl', '');
        set_user_preference(self::SETTINGPREFIX.'userid', '');
        $this->accesstoken = '';
        $this->notestore = null;
        return $this->print_login();
    }

    /**
     * Returns the information for the login form.
     *
     * @return array|string information/content of the login form
     */
    public function print_login() {
        try {
            $result = $this->get_oauth()->request_token();
        } catch (Exception $e) {
            throw new repository_exception('requesttokenerror', 'repository_evernote');
        }
        set_user_preference(self::SETTINGPREFIX.'tokensecret', $result['oauth_token_secret']);
        $url = $result['authorize_url'];
        if ($this->options['ajax']) {
            $ret = array();
            $popup_btn = new stdClass();
            $popup_btn->type = 'popup';
            $popup_btn->url = $url;
            $ret['login'] = array($popup_btn);
            return $ret;
        } else {
            echo html_writer::link($url, get_string('login', 'repository'), array('target' => '_blank'));
        }
        return array();
    }

    /**
     * Generates the search form.
     *
     * @return string HTML of the search form.
     */
    public function print_search() {
        return parent::print_search();
    }

    /**
     * Perform a search and returns results as {@link repository_evernote::get_file()} does.
     *
     * @param string $search_text text to search for.
     * @param int $page page we are on.
     * @return array of results.
     */
    public function search($search_text, $page = 0) {
        $result = array();
        $path = $this->build_node_path('mysearch', $search_text);
        $offset = 0;
        $filter = new NoteFilter(array(
            'words' => $search_text,
            'ascending' => true,
            'order' => NoteSortOrder::TITLE
        ));

        if ($this->searchdynload) {
            $notelist = $this->find_notes_metadata($filter, $offset, $this->searchmaxresults);
            $results = $this->build_notes_list($notelist->notes, $path, false);
        }  else {
            $filter = new NoteFilter(array(
                'words' => $search_text,
                'ascending' => true,
                'order' => NoteSortOrder::TITLE
            ));
            $notelist = $this->get_notestore()->findNotes($this->accesstoken, $filter, $offset, $this->searchmaxresults);
            $results = $this->build_notes_list($notelist->notes, '', true);
        }

        $result['path'] = $this->build_breadcrumb($path);
        $result['list'] = $results;
        $result['dynload'] = $this->searchdynload;
        return $result;
    }

    /**
     * Repository method to serve the referenced file.
     *
     * @param stored_file $storedfile the file that contains the reference
     * @param int $lifetime Number of seconds before the file should expire from caches (default 24 hours)
     * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
     * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
     * @param array $options additional options affecting the file serving
     */
    public function send_file($storedfile, $lifetime = 86400, $filter = 0, $forcedownload = false, array $options = null) {
        $ref = unserialize($storedfile->get_reference());
        header('Location: ' . $ref->url);
        die();
    }

    /**
     * Type of files supported.
     *
     * @return int of file supported mask.
     */
    public function supported_returntypes() {
        return FILE_INTERNAL | FILE_REFERENCE;
    }

    /**
     * Populates the form which holds the admin settings.
     *
     * @param moodle_form $mform the form to populate
     * @param string $classname repository class name
     * @return void
     */
    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform, $classname);

        $mform->addElement('text', 'key', get_string('key', 'repository_evernote'), array('size' => '40'));
        $mform->setType('key', PARAM_RAW);
        $mform->addElement('text', 'secret', get_string('secret', 'repository_evernote'), array('size' => '40'));
        $mform->setType('secret', PARAM_RAW);
        $mform->addElement('selectyesno', 'usedevapi', get_string('usedevapi', 'repository_evernote'), 0);
        $mform->addElement('static', '', '', get_string('usedevapi_info', 'repository_evernote'));

        $strrequired = get_string('required');
        $mform->addRule('key', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

}
