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
require_once("{$CFG->dirroot}/completion/data_object.php");
require_once("{$CFG->libdir}/completionlib.php");

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

        return $this->aggregate();
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

        // Set time started
        if (!$this->timestarted) {
            $this->timestarted = $timecomplete;
        }

        // Set time complete
        $this->timecompleted = $timecomplete;

        // Save record
        if ($result = $this->_save()) {
            events_trigger('course_completed', $this->get_record_data());

            $eventdata = new stdClass();
            $eventdata->criteriatype = COMPLETION_CRITERIA_TYPE_COURSE;
            $eventdata->courseinstance = $this->course;
            $eventdata->userid = $this->userid;
            events_trigger('completion_criteria_calc', $eventdata);
        }

        return $result;
    }

    /**
     * Save course completion status
     *
     * This method creates a course_completions record if none exists
     * and also calculates the timeenrolled date if the record is being
     * created
     *
     * @access  private
     * @return  bool
     */
    private function _save() {
        // Make sure timeenrolled is not null
        if (!$this->timeenrolled) {
            $this->timeenrolled = 0;
        }

        // Save record
        if ($this->id) {
            // Update
            return $this->update();
        } else {
            // Create new
            if (!$this->timeenrolled) {
                global $DB;

                // Get earliest current enrolment start date
                // This means timeend > now() and timestart < now()
                $sql = "
                    SELECT
                        ue.timestart
                    FROM
                        {user_enrolments} ue
                    JOIN
                        {enrol} e
                    ON (e.id = ue.enrolid AND e.courseid = :courseid)
                    WHERE
                        ue.userid = :userid
                    AND ue.status = :active
                    AND e.status = :enabled
                    AND (
                        ue.timeend = 0
                     OR ue.timeend > :now
                    )
                    AND ue.timestart < :now2
                    ORDER BY
                        ue.timestart ASC
                ";
                $params = array(
                    'enabled'   => ENROL_INSTANCE_ENABLED,
                    'active'    => ENROL_USER_ACTIVE,
                    'userid'    => $this->userid,
                    'courseid'  => $this->course,
                    'now'       => time(),
                    'now2'      => time()
                );

                if ($enrolments = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE)) {
                    $this->timeenrolled = $enrolments->timestart;
                }

                // If no timeenrolled could be found, use current time
                if (!$this->timeenrolled) {
                    $this->timeenrolled = time();
                }
            }

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

        // If overall aggregation status is true, mark course complete for user
        if ($overall_status) {
            return $this->mark_complete($timecompleted);
        } else {
            return $this->_save();
        }
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


/**
 * This function is run when a user is enrolled in the course
 * and creates a completion_completion record for the user if
 * completion is enabled for this course
 *
 * @param   integer     $courseid       Course ID
 * @param   integer     $userid         User ID
 * @param   integer     $timestart      Enrolment start timestamp
 * @return  boolean
 */
function completion_start_user($courseid, $userid, $timestart) {
    global $DB;

    // Load course
    if (!$course = $DB->get_record('course', array('id' => $courseid))) {
        debugging('Could not load course id '.$courseid);
        return true;
    }

    // Create completion object
    $cinfo = new completion_info($course);

    // Check completion is enabled for this site and course
    if (!$cinfo->is_enabled()) {
        return false;
    }

    // If completion not set to start on enrollment, do nothing
    if (empty($course->completionstartonenrol)) {
        return false;
    }

    // Create completion record
    $data = array(
        'userid'    => $userid,
        'course'    => $course->id
    );
    $completion = new completion_completion($data);
    if (!$completion->timeenrolled) {
        $completion->timeenrolled = $timestart;
    }

    // Update record
    if (!empty($course->completionstartonenrol)) {
        $completion->mark_inprogress($timestart);
    } else {
        $completion->mark_enrolled();
    }

    return true;
}


/**
 * Triggered by changing course completion criteria, this function
 * bulk marks users as started in the course completion system.
 *
 * @param   integer     $courseid       Course ID
 * @return  bool
 */
function completion_start_user_bulk($courseid) {
    global $DB;

    /**
     * A quick explaination of this horrible looking query
     *
     * It's purpose is to locate all the active participants
     * of a course with course completion enabled.
     *
     * We want to record the user's enrolment start time for the
     * course. This gets tricky because there can be multiple
     * enrolment plugins active in a course, hence the fun
     * case statement.
     */
    $sql = "
        INSERT INTO
            {course_completions}
            (course, userid, timeenrolled, timestarted, reaggregate)
        SELECT
            c.id AS course,
            ue.userid AS userid,
            CASE
                WHEN MIN(ue.timestart) <> 0
                THEN MIN(ue.timestart)
                ELSE ?
            END,
            CASE
                WHEN c.completionstartonenrol = 1
                THEN ?
                ELSE 0
            END,
            0
        FROM
            {user_enrolments} ue
        INNER JOIN
            {enrol} e
         ON e.id = ue.enrolid
        INNER JOIN
            {course} c
         ON c.id = e.courseid
        LEFT JOIN
            {course_completions} crc
         ON crc.course = c.id
        AND crc.userid = ue.userid
        WHERE
            c.enablecompletion = 1
        AND crc.id IS NULL
        AND c.id = ?
        AND ue.status = ?
        AND e.status = ?
        AND (ue.timeend > ? OR ue.timeend = 0)
        GROUP BY
            c.id,
            ue.userid
    ";

    $now = time();
    $params = array(
        $now,
        $now,
        $courseid,
        ENROL_USER_ACTIVE,
        ENROL_INSTANCE_ENABLED,
        $now
    );
    $affected = $DB->execute($sql, $params, true);
}
