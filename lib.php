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
 * Time Report tool plugin's lib file.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

use core_user\output\myprofile\tree;

function get_enabled_logstore_name_pdf(): string|bool {
    $enabled_log_stores = explode(',', get_config('tool_log', 'enabled_stores'));
    return (!empty($enabled_log_stores)) ? $enabled_log_stores[0] : false;
}

function tool_time_report_myprofile_navigation(core_user\output\myprofile\tree $tree, stdClass $user, bool $iscurrentuser, stdClass $course): bool {
    global $CFG, $USER;

    $context = context_system::instance();
    $courseid = optional_param('courseid', 0, PARAM_INT); // User id.

    if (!array_key_exists('reports', $tree->__get('categories'))) {
        // Create a new category.
        $categoryname = get_string('time_report', 'tool_time_report');
        $category = new core_user\output\myprofile\category('time_report', $categoryname, 'time_report');
        $tree->add_category($category);
    } else {
        // Get the existing category.
        $category = $tree->__get('categories')['reports'];
    }

    if ($courseid != 0) {
        $context = context_course::instance($courseid);
    }

    if (isset($course->id)) {
        $url = new moodle_url('/admin/tool/time_report/view.php', ['userid' => $user->id, 'course' => $course->id]);
    } else {
        $url = new moodle_url('/admin/tool/time_report/view.php', ['userid' => $user->id]);
    }

    $admins = get_admins();
    $isadmin = in_array($USER->id, array_keys($admins));
    $hascapability = has_capability('tool/time_report:view', $context);

    // Add the node if the user is admin or has the capability.
    if ($isadmin || $hascapability) {
        $istargetadmin = in_array($user->id, array_keys($admins));
        $availableonadmins = get_config('tool_time_report', 'available_on_admins');
        if (($istargetadmin && $availableonadmins) || !$istargetadmin) {
            $pluginname = get_string('time_report', 'tool_time_report');
            $node = new core_user\output\myprofile\node('reports', 'tool_time_report', $pluginname, null, $url);
            $category->add_node($node);
        }
    }

    return true;
}

/**
 * Serve the files from the tool_time_report file areas
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if the file not found, just send the file otherwise and do not return anything
 */
function tool_time_report_pluginfile(stdClass|int|null $course, stdClass|null $cm, stdClass $context, string $filearea, array $args, bool $forcedownload, array $options=array()): bool {
    // Check the contextlevel is as expected - if your plugin is a block, this becomes CONTEXT_BLOCK, etc.
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    // Make sure the filearea is one of those used by the plugin.
    if ($filearea !== 'content' && $filearea !== 'time_csv') {
        return false;
    }

    // Make sure the user is logged in and has access to the module.
    require_login($course, true, $cm);

    // Check the relevant capabilities - these may vary depending on the filearea being accessed.
    if (!has_capability('tool/time_report:view', $context)) {
        return false;
    }

    // Leave this line out if you set the itemid to null in make_pluginfile_url (set $itemid to 0 instead).
    $itemid = array_shift($args); // The first item in the $args array.

    // Use the itemid to retrieve any relevant data records and perform any security checks to see if the
    // user really does have access to the file in question.

    // Extract the filename / filepath from the $args array.
    $filename = array_pop($args); // The last item in the $args array.
    if (!$args) {
        $filepath = '/';
    } else {
        $filepath = '/'.implode('/', $args).'/';
    }

    // Retrieve the file from the Files API.
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'tool_time_report', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false; // The file does not exist.
    }

    // We can now send the file back to the browser - in this case with a cache lifetime of 1 day and no filtering.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
    return true;
}
