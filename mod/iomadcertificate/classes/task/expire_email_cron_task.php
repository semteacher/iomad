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
 * A scheduled task for iomadcertificate expire email cron.
 *
 * @todo MDL-44734 This job will be split up properly.
 *
 * @package    mod_iomadcertificate
 * @copyright  2017 Semenets Andriy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_iomadcertificate\task;

class expire_email_cron_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('expireemailcrontask', 'iomadcertificate');
    }

    /**
     * Run email cron.
     */
    public function execute() {
        global $CFG;
        //require_once($CFG->dirroot . '/local/email_reports/lib.php');
        //email_reports_cron();
    
        // Set some defaults.
        $runtime = time();
        $courses = array();

        mtrace("FLYEASTWOOD: Running email report cron at ".date('D M Y h:m:s', $runtime));        
        
        mtrace("FLYEASTWOOD: Sending expiry warning email to users");
        //mtrace("FLYEASTWOOD: Sending expiry warning email to $user->email");
    }

}
