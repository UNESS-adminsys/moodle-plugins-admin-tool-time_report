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
 * Settings script.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;
require_once(dirname(__FILE__) . '/locallib.php');

if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_category('tool_time_report', new lang_string('pluginname', 'tool_time_report')));
    $settingspage = new admin_settingpage('manage_tool_time_report', new lang_string('pluginname', 'tool_time_report'));

    if ($ADMIN->fulltree) {
        // Components targets.
        $targets = get_targets();
        $defaulttargets = array_keys($targets);

        $settingspage->add(new admin_setting_configmultiselect(
            'tool_time_report/targets',
            new lang_string('settings:targets', 'tool_time_report'),
            new lang_string('settings:targets', 'tool_time_report'),
            $defaulttargets,
            $targets)
        );

        // Idle time.
        $settingspage->add(new admin_setting_configduration(
            'tool_time_report/idletime',
            new lang_string('settings:idletime', 'tool_time_report'),
            new lang_string('settings:idletime_desc', 'tool_time_report'),
            30 * MINSECS)
        );

        // Borrowed time when idle.
        $settingspage->add(new admin_setting_configduration(
            'tool_time_report/borrowedtime',
            new lang_string('settings:borrowedtime', 'tool_time_report'),
            new lang_string('settings:borrowedtime_desc', 'tool_time_report'),
            15 * MINSECS)
        );

        // Make the report available on admin profiles.
        $settingspage->add(new admin_setting_configcheckbox(
            'tool_time_report/available_on_admins',
            new lang_string('settings:available_on_admins', 'tool_time_report'),
            new lang_string('settings:available_on_admins_desc', 'tool_time_report'),
            1)
        );

        // DB driver selection.
        $drivers = \logstore_database\helper::get_drivers();
        $defaultdbdriver = get_config('logstore_database', 'dbdriver');
        if (!$defaultdbdriver) {
            $defaultdbdriver = 'native/mysqli';
        }
        $settingspage->add(new admin_setting_configselect(
            'tool_time_report/dbdriver',
            new lang_string('settings:dbdriver', 'tool_time_report'),
            new lang_string('settings:dbdriver_desc', 'tool_time_report'),
            $defaultdbdriver,
            $drivers)
        );
    }

    $ADMIN->add('reports', $settingspage);
}
