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
 * Version details.
 *
 * @package    repository
 * @subpackage evernote
 * @copyright  2012 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2013113000;        // The current plugin version (Date: YYYYMMDDXX)
$plugin->requires  = 2012062502;        // Requires this Moodle version
$plugin->maturity  = MATURITY_STABLE;   // Maturity of the plugin
$plugin->release   = '1.2.2';           // Release name
$plugin->component = 'repository_evernote'; // Full name of the plugin (used for diagnostics)
