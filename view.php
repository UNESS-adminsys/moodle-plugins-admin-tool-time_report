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
 * Time Report tool plugin's view.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/locallib.php');

require_login();

global $PAGE, $USER;

$id = required_param('userid', PARAM_INT);
$user = $DB->get_record('user', array('id' => $id), '*', MUST_EXIST);
$currentuser = ($user->id == $USER->id);

$personalcontext = context_user::instance($user->id);
if (!has_capability('tool/time_report:view', $personalcontext)) {
    redirect("$CFG->wwwroot/user/profile.php?id=?$user->id");
}

$systemcontext = context_system::instance();
$usercontext   = context_user::instance($user->id, IGNORE_MISSING);
$strprofile    = get_string('personalprofile');
$headerinfo    = array('heading' => fullname($user), 'user' => $user, 'usercontext' => $usercontext);
$fullname      = fullname($user);

$PAGE->set_url('/admin/tool/time_report/view.php', array('userid' => $user->id));
$PAGE->set_context($usercontext);
$PAGE->add_body_class('path-user');
$PAGE->set_title("$strprofile: $fullname");
$PAGE->set_heading("$strprofile: $fullname");
$PAGE->set_pagelayout('standard');
$PAGE->set_other_editing_capability('moodle/course:manageactivities');

echo $OUTPUT->header();

// Disallow the view page on admin accounts.
$admins = get_admins();
$isadmin = in_array($user->id, array_keys($admins));
$availableonadmins = get_config('tool_time_report', 'available_on_admins');

if ($isadmin && !$availableonadmins) {
    redirect("$CFG->wwwroot/user/profile.php?id=$user->id");
}

// Rendering.
$context = \context_system::instance();
$reportfiles = get_reports_urls($context->id, $user->id);
$renderable = new \tool_time_report\output\get_report($USER->id, $user->id, fullname($user), $context->id, $reportfiles);
$output = $PAGE->get_renderer('tool_time_report');

echo $output->render($renderable);
echo $OUTPUT->footer();
