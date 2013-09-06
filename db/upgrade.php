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
 * Upgrade logic.
 *
 * @package    repository_evernote
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_repository_evernote_upgrade($oldversion = 0) {
    global $DB;

    if ($oldversion < 2013090600) {

        // Migrating the user preferences using new prefix.
        $configs = array('tokensecret', 'accesstoken', 'notestoreurl', 'userid');
        foreach ($configs as $config) {
            try {
                $DB->set_field('user_preferences', 'name', 'repository_evernote_' . $config,
                    array('name' => 'evernote_' . $config));
            } catch (dmlwriteexception $e) {
                // Nicely catching the exception.
            }
        }

        upgrade_plugin_savepoint(true, 2013090600, 'repository', 'evernote');
    }

    return true;
}
