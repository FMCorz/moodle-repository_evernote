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
require_once(dirname(__FILE__) . '/lib/evernote/lib/Thrift.php');
require_once(dirname(__FILE__) . '/lib/evernote/lib/transport/THttpClient.php');
require_once(dirname(__FILE__) . '/lib/evernote/lib/protocol/TBinaryProtocol.php');
require_once(dirname(__FILE__) . '/lib/evernote/lib/packages/NoteStore/NoteStore.php');
use EDAM\NoteStore\NoteStoreClient;
use EDAM\NoteStore\NoteFilter;
use EDAM\NoteStore\NotesMetadataResultSpec;

/**
 * Repository class to access Evernote files.
 *
 * @package    repository
 * @subpackage evernote
 * @copyright  2012 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_evernote extends repository {

    /**
     * URL to the API.
     * @var string
     */
    protected $api = 'https://www.evernote.com';

    /**
     * URL to the Web access of Evernote.
     * @var string
     */
    protected $manageurl = 'https://www.evernote.com/Home.action';

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
     * Session key to store the path in, will probably be removed after MDL-35664.
     * @var string
     */
    protected $sessionkey = 'repository_evernote_path';

    /**
     * Prefix for the user preferences.
     * @var string
     */
    protected $settingprefix = 'evernote_';

    /**
     * Disable HTTPS in Evernote SDK.
     * Some versions of OpenSSL will conflict with Evernote when validating the certificate.
     * @see http://discussion.evernote.com/topic/26978-ssl-handshake-problems/
     * @var bool
     */
    protected $usehttpsinsdk = false;

    /**
     * Cache for the NoteStoreClient object.
     * @var NoteStoreClient
     */
    protected $notestore;

    /**
     * Cache for the oauth_helper object.
     * @var oauth_helper
     */
    protected $oauth;

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

        $config = get_config('evernote');
        $this->usehttpsinsdk = !$config->nohttps;
        $this->accesstoken = get_user_preferences($this->settingprefix.'accesstoken', null);
        $this->notestoreurl = get_user_preferences($this->settingprefix.'notestoreurl', null);

        $callbackurl = new moodle_url('/repository/repository_callback.php', array(
            'callback' => 'yes',
            'repo_id' => $repositoryid
        ));

        $args = array();
        $args['oauth_consumer_key'] = $config->key;
        $args['oauth_consumer_secret'] = $config->secret;
        $args['oauth_callback'] = $callbackurl->out(false);
        $this->init_oauth($args);
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
        $breadcrumb = array(array('name' => get_string('evernote', 'repository_evernote'), 'path' => 'root:'));
        $bread = explode('/', $path);
        $crumbtrail = '';
        foreach ($bread as $crumb) {
            list($mode, $guid) = explode(':', $crumb, 2);
            switch ($mode) {
                case 'all':
                    $breadcrumb[] = array(
                        'name' => get_string('allnotes', 'repository_evernote'),
                        'path' => 'all:'
                    );
                    break;
                case 'tags':
                    $breadcrumb[] = array(
                        'name' => get_string('tags', 'repository_evernote'),
                        'path' => $crumbtrail . 'tags:'
                    );
                    if (strpos($guid, '|') !== false) {
                        list($nothing, $name) = explode('|', $guid, 2);
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $crumbtrail . 'tags:' . $guid
                        );
                    }
                    break;
                case 'notebooks':
                    $breadcrumb[] = array(
                        'name' => get_string('notebooks', 'repository_evernote'),
                        'path' => $crumbtrail . 'notebooks:'
                    );
                    break;
                case 'stack':
                    $breadcrumb[] = array(
                        'name' => $guid,
                        'path' => $crumbtrail . 'stack:' . $guid
                    );
                    break;
                case 'notebook':
                    if (strpos($guid, '|') !== false) {
                        list($nothing, $name) = explode('|', $guid, 2);
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $crumbtrail . 'notebooks:' . $guid
                        );
                    }
                    break;
                case 'searchs':
                    $breadcrumb[] = array(
                        'name' => get_string('savedsearchs', 'repository_evernote'),
                        'path' => $crumbtrail . 'searchs:'
                    );
                    if (strpos($guid, '|') !== false) {
                        list($nothing, $name) = explode('|', $guid, 2);
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $crumbtrail . 'searchs:' . $guid
                        );
                    }
                    break;
                case 'note':
                    if (strpos($guid, '|') !== false) {
                        list($nothing, $name) = explode('|', $guid, 2);
                        $breadcrumb[] = array(
                            'name' => $name,
                            'path' => $crumbtrail . 'note:' . $guid
                        );
                    }
                    break;

            }
            $tmp = end($breadcrumb);
            $crumbtrail .= trim($tmp['path'], '/') . '/';
        }
        return $breadcrumb;
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
            $resources[] = array(
                'title' => $resource->attributes->fileName,
                'date' => $note->updated / 1000,
                'datemodified' => $note->updated / 1000,
                'datecreated' => $note->created / 1000,
                'size' => $resource->data->size,
                'source' => 'resource:' . $resource->guid,
                'thumbnail' => $OUTPUT->pix_url(file_extension_icon($resource->attributes->fileName, 64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
            );
        }
        return $resources;
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
                if (isset($notebooks[$notebookstack])) {
                    continue;
                }
                $notebooks[$notebookstack] = array(
                    'title' => $notebookstack,
                    'path' => $path . 'stack:' . $notebookstack,
                    'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64,
                    'children' => array()
                );
            } else {
                // If we arrived here, we probably want to display the notebook.
                $notebooks[] = array(
                    'title' => $notebook->name,
                    'path' => $path . 'notebook:' . $notebook->guid . '|' . $notebook->name,
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
        return $notebooks;
    }

    /**
     * Build the list of notes from a note list.
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
                'path' => $path . 'note:' . $note->guid . '|' . $note->title,
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
            $searchs[] = array(
                'title' => $search->name,
                'path' => $path . 'searchs:' . $search->guid . '|' . $search->name,
                'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
                'children' => array(),
            );
        }
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
            $tags[] = array(
                'title' => $tag->name,
                'path' => $path . 'tags:' . $tag->guid . '|' . $tag->name,
                'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
                'children' => array(),
            );
        }
        return $tags;
    }

    /**
     * Function called during the OAuth process as the callback URL.
     *
     * @return void
     */
    public function callback() {
        $token  = optional_param('oauth_token', '', PARAM_TEXT);
        $verifier  = optional_param('oauth_verifier', '', PARAM_TEXT);
        $secret = get_user_preferences($this->settingprefix.'tokensecret', '');
        $access = $this->oauth->get_access_token($token, $secret, $verifier);
        $notestore  = $access['edam_noteStoreUrl'];
        $userid  = $access['edam_userId'];
        $accesstoken  = $access['oauth_token'];
        set_user_preference($this->settingprefix.'accesstoken', $accesstoken);
        set_user_preference($this->settingprefix.'notestoreurl', $notestore);
        set_user_preference($this->settingprefix.'userid', $userid);
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
     * Find metadata of notes matching the filter.
     *
     * @param NoteFilter $filter of the search
     * @param int $offset of the results
     * @param int $limit number of results to get
     * @return NotesMedatadataList of search results
     */
    public function find_notes_metadata($filter, $offset, $limit) {
        $resultspec = new NotesMetaDataResultSpec(array(
            'includeTitle' => true,
            'includeCreated' => true,
            'includeUpdated' => true
        ));
        return $this->get_notestore()->findNotesMetadata($this->accesstoken, $filter, $offset, $limit, $resultspec);
    }

    /**
     * Downloads the file to Moodle.
     *
     * @param mixed $reference to the file, {@link repository_evernote::get_file_reference()}
     * @param string $filename name to save the file to
     * @return array containing information about the file saved
     */
    public function get_file($reference, $filename = '') {
        $reference = unserialize($reference);
        if (strpos($reference->source, 'resource:') === 0) {
            // This file is downloaded directly from the user account.
            list($lost, $guid) = explode(':', $reference->source, 2);
            try {
                $resource = $this->get_notestore()->getResource($this->accesstoken, $guid, true, false, true, false);
                $path = $this->prepare_file($filename);
                $fp = fopen($path, 'wb');
                fwrite($fp, $resource->data->body);
                fclose($fp);
                return array('path' => $path);
            } catch (Exception $e) {
                // Don't do anything, the exception will be thrown at the end of this function.
            }
        }
        throw new repository_exception('cannotdownload', 'repository');
    }

    /**
     * Receive a source when a user picks a file and transforms it to a final reference.
     *
     * See repository_ajax.php when action is download.
     *
     * @param string $source returned by the file picker
     * @return string $reference to the file
     */
    public function get_file_reference($source) {
        global $USER;
        $reference = new stdClass();
        $reference->source = $source;
        $reference->userid = $USER->id;
        $reference->username = fullname($USER);
        return serialize($reference);
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
        list($mode, $guid) = explode(':', $uri, 2);

        // The name of the node can be stored in the $guid, remove it if is the case. Except for stacks which
        // guid is already its name.
        if (strpos($guid, '|') !== false && $mode != 'stack') {
            list($guid, $nothing) = explode('|', $guid, 2);
        }

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
                    // TODO handle nested tags.
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
                        'path' => $key . ':',
                        'thumbnail' => $OUTPUT->pix_url(file_folder_icon(64))->out(false),
                        'thumbnail_height' => 64,
                        'thumbnail_width' => 64,
                        'children' => array()
                    );
                }
                break;
        }

        $breadcrumb = $this->build_breadcrumb($path);
        $result['path'] = $breadcrumb;
        $result['list'] = array_merge($folders, $files);
        if (!empty($pages) && $this->enablepaging) {
            $result['page'] = $page;
            $result['pages'] = $pages;
        }
        fb($result);
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
            if (!$this->usehttpsinsdk) {
                $parts['port'] = 80;
                $parts['scheme'] = 'http';
            }
            $notestorehttpclient = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $notestoreprotocol = new TBinaryProtocol($notestorehttpclient);
            $this->notestore = new NoteStoreClient($notestoreprotocol, $notestoreprotocol);
        }
        return $this->notestore;
    }

    /**
     * Return the list of options this repository supports.
     *
     * @return array of option names
     */
    public static function get_type_option_names() {
        $options = array('key', 'secret', 'nohttps');
        return array_merge(parent::get_type_option_names(), $options);
    }

    /**
     * Initialise the OAuth object.
     *
     * @param array $args parameters to pass to {@link oauth_helper::__construct()}
     * @return void
     */
    protected function init_oauth($args) {
        $args['api_root'] = $this->api;
        $args['request_token_api'] = $this->api . '/oauth';
        $args['access_token_api'] = $this->api . '/oauth';
        $args['authorize_url'] = $this->api . '/OAuth.action';
        $this->oauth = new oauth_helper($args);

        // Forcing the SSL version will prevent some random behaviours with OpenSSL.
        $this->oauth->setup_oauth_http_options(array('CURLOPT_SSLVERSION' => 3));
    }

    /**
     * Action to perform when the user clicks the logout button.
     *
     * @return string from {@link evernote_repository::print_login()}
     */
    public function logout() {
        set_user_preference($this->settingprefix.'accesstoken', '');
        set_user_preference($this->settingprefix.'notestoreurl', '');
        set_user_preference($this->settingprefix.'userid', '');
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
        $result = $this->oauth->request_token();
        set_user_preference($this->settingprefix.'tokensecret', $result['oauth_token_secret']);
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
     * Type of files supported.
     *
     * @return int of file supported mask.
     */
    public function supported_returntypes() {
        return FILE_INTERNAL;
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
        $key    = get_config('evernote', 'key') || '';
        $secret = get_config('evernote', 'secret') || '';
        $nohttps  = get_config('evernote', 'nohttps') || 0;

        $mform->addElement('text', 'key', get_string('key', 'repository_evernote'),
                array('value' => $key, 'size' => '40'));
        $mform->addElement('text', 'secret', get_string('secret', 'repository_evernote'),
                array('value' => $secret, 'size' => '40'));
        $mform->addElement('checkbox', 'nohttps', get_string('nohttps', 'repository_evernote'));
        $mform->setDefault('nohttps', $nohttps);
        $mform->addElement('static', 'nohttpshelp', '', get_string('nohttps_help', 'repository_evernote'));

        $strrequired = get_string('required');
        $mform->addRule('key', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

}
