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
 * Contains class mod_questionnaire\privacy\provider
 *
 * @package    mod_questionnaire
 * @copyright  2018 onward Mike Churchward (mike.churchward@poetopensource.org)
 * @author     Mike Churchward
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_questionnaire\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements
    // This plugin has data.
    \core_privacy\local\metadata\provider,

    // This plugin currently implements the original plugin_provider interface.
    \core_privacy\local\request\plugin\provider {

    /**
     * Returns meta data about this system.
     *
     * @param   collection $items The collection to add metadata to.
     * @return  collection  The array of metadata
     */
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {

        // Add all of the relevant tables and fields to the collection.
        $collection->add_database_table('questionnaire_attempts', [
            'userid' => 'privacy:metadata:questionnaire_attempts:userid',
            'rid' => 'privacy:metadata:questionnaire_attempts:rid',
            'qid' => 'privacy:metadata:questionnaire_attempts:qid',
            'timemodified' => 'privacy:metadata:questionnaire_attempts:timemodified',
        ], 'privacy:metadata:questionnaire_attempts');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param   int $userid The user to search.
     * @return  contextlist   $contextlist  The list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();

        $sql = "SELECT c.id
                 FROM {context} c
           INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
           INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
           INNER JOIN {questionnaire} q ON q.id = cm.instance
            LEFT JOIN {questionnaire_attempts} qa ON qa.qid = q.id
                WHERE qa.userid = :attemptuserid
        ";

        $params = [
            'modname' => 'questionnaire',
            'contextlevel' => CONTEXT_MODULE,
            'attemptuserid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Export all user data for the specified user, in the specified contexts, using the supplied exporter instance.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT cm.id AS cmid,
                       qa.rid as responseid,
                       qa.timemodified
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {questionnaire} q ON q.id = cm.instance
            INNER JOIN {questionnaire_attempts} qa ON qa.qid = q.id
                 WHERE c.id {$contextsql}
                       AND qa.userid = :userid
              ORDER BY cm.id, qa.rid ASC";

        $params = ['modname' => 'questionnaire', 'contextlevel' => CONTEXT_MODULE, 'userid' => $user->id] + $contextparams;

        // There can be more than one attempt per instance, so we'll gather them by cmid.
        $lastcmid = 0;
        $attemptdata = [];
        $attempts = $DB->get_recordset_sql($sql, $params);
        foreach ($attempts as $attempt) {
            // If we've moved to a new choice, then write the last choice data and reinit the choice data array.
            if ($lastcmid != $attempt->cmid) {
                if (!empty($attemptdata)) {
                    $context = \context_module::instance($lastcmid);
                    // Fetch the generic module data for the questionnaire.
                    $contextdata = \core_privacy\local\request\helper::get_context_data($context, $user);
                    // Merge with attempt data and write it.
                    $contextdata = (object)array_merge((array)$contextdata, $attemptdata);
                    \core_privacy\local\request\writer::with_context($context)->export_data([], $contextdata);
                }
                $attemptdata = [];
                $lastcmid = $attempt->cmid;
            }
            $attemptdata['attempts'][] = [
                'responseid' => $attempt->responseid,
                'timemodified' => \core_privacy\local\request\transform::datetime($attempt->timemodified),
            ];
        }
        $attempts->close();

        // The data for the last activity won't have been written yet, so make sure to write it now!
        if (!empty($attemptdata)) {
            $context = \context_module::instance($lastcmid);
            // Fetch the generic module data for the questionnaire.
            $contextdata = \core_privacy\local\request\helper::get_context_data($context, $user);
            // Merge with attempt data and write it.
            $contextdata = (object)array_merge((array)$contextdata, $attemptdata);
            \core_privacy\local\request\writer::with_context($context)->export_data([], $contextdata);
        }
    }

    /**
     * Delete all personal data for all users in the specified context.
     *
     * @param context $context Context to delete data from.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {

    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {

    }
}