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
 * Course completion status for a particular user/course
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir.'/completion/data_object.php');

/**
 * Course completion status for a particular user/course
 *
 * @package core_completion
 * @category completion
 * @copyright 2009 Catalyst IT Ltd
 * @author Aaron Barnes <aaronb@catalyst.net.nz>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_completion extends data_object {

    /* @var string $table Database table name that stores completion information */
    public $table = 'course_completions';

    /* @var array $required_fields Array of required table fields, must start with 'id'. */
    public $required_fields = array('id', 'userid', 'course',
        'timeenrolled', 'timestarted', 'timecompleted', 'reaggregate');

    /* @var int $userid User ID */
    public $userid;

    /* @var int $course Course ID */
    public $course;

    /* @var int Time of course enrolment {@link completion_completion::mark_enrolled()} */
    public $timeenrolled;

    /**
     * Time the user started their course completion {@link completion_completion::mark_inprogress()}
     * @var int
     */
    public $timestarted;

    /* @var int Timestamp of course completion {@link completion_completion::mark_complete()} */
    public $timecompleted;

    /* @var int Flag to trigger cron aggregation (timestamp) */
    public $reaggregate;


    /**
     * Finds and returns a data_object instance based on params.
     *
     * @param array $params associative arrays varname = >value
     * @return data_object instance of data_object or false if none found.
     */
    public static function fetch($params) {
        return self::fetch_helper('course_completions', __CLASS__, $params);
    }

    /**
     * Return status of this completion
     *
     * @return bool
     */
    public function is_complete() {
        return (bool) $this->timecompleted;
    }

    /**
     * Mark this user as started (or enrolled) in this course
     *
     * If the user is already marked as started, no change will occur
     *
     * @param integer $timeenrolled Time enrolled (optional)
     */
    public function mark_enrolled($timeenrolled = null) {

        if ($this->timeenrolled === null) {

            if ($timeenrolled === null) {
                $timeenrolled = time();
            }

            $this->timeenrolled = $timeenrolled;
        }

        return $this->_save();
    }

    /**
     * Mark this user as inprogress in this course
     *
     * If the user is already marked as inprogress, the time will not be changed
     *
     * @param integer $timestarted Time started (optional)
     */
    public function mark_inprogress($timestarted = null) {

        $timenow = time();

        if (!$this->timestarted) {

            if (!$timestarted) {
                $timestarted = $timenow;
            }

            $this->timestarted = $timestarted;
        }

        return $this->aggregate();
    }

    /**
     * Mark this user complete in this course
     *
     * This generally happens when the required completion criteria
     * in the course are complete.
     *
     * @param integer $timecomplete Time completed (optional)
     * @return void
     */
    public function mark_complete($timecomplete = null) {

        // Never change a completion time
        if ($this->timecompleted) {
            return;
        }

        // Use current time if nothing supplied
        if (!$timecomplete) {
            $timecomplete = time();
        }

        // Set time complete
        $this->timecompleted = $timecomplete;

        // Save record
        return $this->_save();
    }

    /**
     * Save course completion status
     *
     * This method creates a course_completions record if none exists
     * @access  private
     * @return  bool
     */
    private function _save() {
        if ($this->timeenrolled === null) {
            $this->timeenrolled = 0;
        }

        // Save record
        if ($this->id) {
            return $this->update();
        } else {
            // Make sure reaggregate field is not null
            if (!$this->reaggregate) {
                $this->reaggregate = 0;
            }

            // Make sure timestarted is not null
            if (!$this->timestarted) {
                $this->timestarted = 0;
            }

            return $this->insert();
        }
    }

    /**
     * Aggregate completion
     */
    public function aggregate() {
        // Check if already complete
        if ($this->timecompleted) {
            return $this->_save();
        }

        // Cached course completion enabled and aggregation method
        static $courses;
        if (!is_array($courses)) {
            $courses = array();
        }

        if (!isset($courses[$this->course])) {
            $c = new stdClass();
            $c->id = $this->course;
            $info = new completion_info($c);
            $courses[$this->course] = new stdClass();
            $courses[$this->course]->enabled = $info->is_enabled();
            $courses[$this->course]->agg = $info->get_aggregation_method();
        }

        // No need to do this if completion is disabled
        if (!$courses[$this->course]->enabled) {
            return false;
        }

        global $DB;

        // Get user's completions
        $completion_sql = "
            SELECT
                cr.id AS criteriaid,
                cr.criteriatype,
                co.timecompleted,
                a.method AS agg_method
            FROM
                {course_completion_criteria} cr
            LEFT JOIN
                {course_completion_crit_compl} co
             ON co.criteriaid = cr.id
            AND co.userid = :userid
            LEFT JOIN
                {course_completion_aggr_methd} a
             ON a.criteriatype = cr.criteriatype
            AND a.course = cr.course
            WHERE
                cr.course = :course
        ";

        $params = array(
            'userid' => $this->userid,
            'course' => $this->course
        );

        $completions = $DB->get_records_sql($completion_sql, $params);

        // If no criteria, no need to aggregate
        if (empty($completions)) {
            return $this->_save();
        }

        // Get aggregation methods
        $agg_overall    = $courses[$this->course]->agg;

        $overall_status = null;
        $activity_status = null;
        $prerequisite_status = null;
        $role_status = null;

        // Get latest timecompleted
        $timecompleted = null;

        // Check each of the criteria
        foreach ($completions as $completion) {
            $timecompleted = max($timecompleted, $completion->timecompleted);
            $iscomplete = (bool) $completion->timecompleted;

            // Handle aggregation special cases
            switch ($completion->criteriatype) {
                case COMPLETION_CRITERIA_TYPE_ACTIVITY:
                    completion_status_aggregate($completion->agg_method, $iscomplete, $activity_status);
                    break;

                case COMPLETION_CRITERIA_TYPE_COURSE:
                    completion_status_aggregate($completion->agg_method, $iscomplete, $prerequisite_status);
                    break;

                case COMPLETION_CRITERIA_TYPE_ROLE:
                    completion_status_aggregate($completion->agg_method, $iscomplete, $role_status);
                    break;

                default:
                    completion_status_aggregate($agg_overall, $iscomplete, $overall_status);
            }
        }

        // Include role criteria aggregation in overall aggregation
        if ($role_status !== null) {
            completion_status_aggregate($agg_overall, $role_status, $overall_status);
        }

        // Include activity criteria aggregation in overall aggregation
        if ($activity_status !== null) {
            completion_status_aggregate($agg_overall, $activity_status, $overall_status);
        }

        // Include prerequisite criteria aggregation in overall aggregation
        if ($prerequisite_status !== null) {
            completion_status_aggregate($agg_overall, $prerequisite_status, $overall_status);
        }

        // If aggregation status is true, mark course complete for user
        if ($overall_status) {

            // Use current time if nothing supplied
            if (!$timecompleted) {
                $timecompleted = time();
            }

            // Set time complete
            $this->timecompleted = $timecompleted;
        } else {
            $this->timecompleted = 0;
        }

        return $this->_save();
    }
}


/**
 * Aggregate criteria status's as per configured aggregation method
 *
 * @param int $method COMPLETION_AGGREGATION_* constant
 * @param bool $data Criteria completion status
 * @param bool|null $state Aggregation state
 */
function completion_status_aggregate($method, $data, &$state) {
    if ($method == COMPLETION_AGGREGATION_ALL) {
        if ($data && $state !== false) {
            $state = true;
        } else {
            $state = false;
        }
    } elseif ($method == COMPLETION_AGGREGATION_ANY) {
        if ($data) {
            $state = true;
        } else if (!$data && $state === null) {
            $state = false;
        }
    }
}
