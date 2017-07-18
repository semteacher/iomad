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
 * Script to let a user create a department for a particular company.
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once('lib.php');
require_once(dirname(__FILE__) . '/../../course/lib.php');

class department_display_form extends company_moodleform {
    protected $selectedepartmentdcompany = 0;
    protected $context = null;
    protected $company = null;

    public function __construct($actionurl, $companyid, $departmentid, $output, $chosenid=0, $action=0) {
        global $CFG, $USER;

        $this->selectedcompany = $companyid;
        $this->context = context_coursecat::instance($CFG->defaultrequestcategory);
        $syscontext = context_system::instance();

        $company = new company($this->selectedcompany);
        $parentlevel = company::get_company_parentnode($company->id);
        $this->companydepartment = $parentlevel->id;
        if (iomad::has_capability('block/iomad_company_admin:edit_all_departments', $syscontext)) {
            $userhierarchylevel = $parentlevel->id;
        } else {
            $userlevel = $company->get_userlevel($USER);
            $userhierarchylevel = $userlevel->id;
        }

        $this->departmentid = $userhierarchylevel;
        $this->output = $output;
        $this->chosenid = $chosenid;
        $this->action = $action;
        parent::__construct($actionurl);
    }

    public function definition() {
        global $CFG;

        $mform =& $this->_form;
        $company = new company($this->selectedcompany);
        if (!$parentnode = company::get_company_parentnode($company->id)) {
            // Company has not been set up, possibly from before an upgrade.
            company::initialise_departments($company->id);
        }
        if (!empty($this->departmentid)) {
            $departmentslist = company::get_all_subdepartments($this->departmentid);
        } else {
            $departmentslist = company::get_all_departments($company->id);
        }

        if (!empty($this->departmentid)) {
            $department = company::get_departmentbyid($this->departmentid);
        } else {
            $department = company::get_company_parentnode($this->selectedcompany);
        }
        $subdepartmentslist = company::get_subdepartments_list($department);
        $subdepartments = company::get_subdepartments($department);

        // Create the sub department checkboxes html.
        $subdepartmenthtml = "";
        $departmenttree = company::get_all_departments_raw($company->id);
        $treehtml = $this->output->department_tree($departmenttree, optional_param('deptid', 0, PARAM_INT));

        if (!empty($subdepartmentslist)) {
            $subdepartmenthtml = "<p>".get_string('subdepartments', 'block_iomad_company_admin').
                               "</p>";
            foreach ($subdepartmentslist as $key => $value) {

                $subdepartmenthtml .= '<input type = "checkbox" name = "departmentids[]" value="'.
                                       $key.'" /> '.$value.'</br>';
            }
        }
        // Then show the fields about where this block appears.
        $mform->addElement('header', 'header',
                            get_string('companydepartment', 'block_iomad_company_admin').
                           $company->get_name());

        if (count($departmentslist) == 1) {
            $mform->addElement('html', "<h3>" . get_string('nodepartments', 'block_iomad_company_admin') . "</h3></br>");
        }
        $mform->addElement('html', '<p>' . get_string('parentdepartment', 'block_iomad_company_admin') . '</p>');
        $mform->addElement('html', $treehtml);
        //$mform->addElement('html', $subdepartmenthtml);

        // This is getting hidden anyway, so no need for label
        $mform->addElement('select', 'deptid', ' ',
                            $departmentslist, array('class' => 'iomad_department_select'));
        //$mform->disabledIf('deptid', 'action', 'eq', 1);

        $buttonarray = array();
        $buttonarray[] = $mform->createElement('submit', 'create',
                                get_string('createdepartment', 'block_iomad_company_admin'));
        if (!empty($subdepartmentslist)) {
            $buttonarray[] = $mform->createElement('submit', 'edit',
                                get_string('editdepartments', 'block_iomad_company_admin'));
            $buttonarray[] = $mform->createElement('submit', 'delete',
                                get_string('deletedepartment', 'block_iomad_company_admin'));
        }
        $mform->addGroup($buttonarray, 'buttonarray', '', array(' '), false);
    }

    public function get_data() {
        $data = parent::get_data();
        return $data;
    }

}

class department_edit_form extends company_moodleform {
    protected $selectedcompany = 0;
    protected $context = null;
    protected $company = null;
    protected $deptid = 0;
    protected $output = null;

    public function __construct($actionurl, $companyid, $departmentid, $output, $chosenid=0, $action=0) {
        global $CFG;

        $this->selectedcompany = $companyid;
        $this->context = context_coursecat::instance($CFG->defaultrequestcategory);
        $this->departmentid = $departmentid;
        $this->output = $output;
        $this->chosenid = $chosenid;
        $this->action = $action;
        parent::__construct($actionurl);
    }

    public function definition() {
        global $CFG;

        $mform =& $this->_form;
        $company = new company($this->selectedcompany);
        $departmentslist = company::get_all_departments($company->id);
        $departmenttree = company::get_all_departments_raw($company->id);
        $treehtml = $this->output->department_tree($departmenttree, $this->chosenid);
        $department = company::get_departmentbyid($this->departmentid);

        // Then show the fields about where this block appears.
        if ($this->action == 0) {
            $mform->addElement('header', 'header',
                                get_string('createdepartment', 'block_iomad_company_admin'));
        } else {
            $mform->addElement('header', 'header',
                                get_string('editdepartments', 'block_iomad_company_admin'));
        }
        $mform->addElement('hidden', 'departmentid', $this->departmentid);
        $mform->setType('departmentid', PARAM_INT);
        $mform->addElement('hidden', 'action', $this->action);
        $mform->setType('action', PARAM_INT);

        // Display department select html (create only)
        //if ($this->action == 0) {
            $mform->addElement('html', '<p>' . get_string('parentdepartment', 'block_iomad_company_admin') . '</p>');
            $mform->addElement('html', $treehtml);
        //}

        // This is getting hidden anyway, so no need for label
        $mform->addElement('select', 'deptid', ' ',
                            $departmentslist, array('class' => 'iomad_department_select'));
        
        $mform->addElement('text', 'fullname',
                            get_string('fullnamedepartment', 'block_iomad_company_admin'),
                            'maxlength = "254" size = "50"');
        $mform->addHelpButton('fullname', 'fullnamedepartment', 'block_iomad_company_admin');
        $mform->addRule('fullname',
                        get_string('missingfullnamedepartment', 'block_iomad_company_admin'),
                        'required', null, 'client');
        $mform->setType('fullname', PARAM_MULTILANG);

        $mform->addElement('text', 'shortname',
                            get_string('shortnamedepartment', 'block_iomad_company_admin'),
                            'maxlength = "100" size = "20"');
        $mform->addHelpButton('shortname', 'shortnamedepartment', 'block_iomad_company_admin');
        $mform->addRule('shortname',
                         get_string('missingshortnamedepartment', 'block_iomad_company_admin'),
                         'required', null, 'client');
        $mform->setType('shortname', PARAM_MULTILANG);

        if (!$this->departmentid) {
            $mform->addElement('hidden', 'chosenid', $this->chosenid);
        } else {
            $mform->addElement('hidden', 'chosenid', $this->departmentid);
        }
        $mform->setType('chosenid', PARAM_INT);

        $this->add_action_buttons();
    }

    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}

$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$departmentid = optional_param('deptid', 0, PARAM_INT);
$deleteids = optional_param_array('departmentids', null, PARAM_INT);
$createnew = optional_param('createnew', 0, PARAM_INT);
$deleteid = optional_param('deleteid', 0, PARAM_INT);
$confirm = optional_param('confirm', null, PARAM_ALPHANUM);

$context = context_system::instance();
require_login();

iomad::require_capability('block/iomad_company_admin:edit_departments', $context);

$urlparams = array();
if ($returnurl) {
    $urlparams['returnurl'] = $returnurl;
}
$companylist = new moodle_url('/local/iomad_dashboard/index.php', $urlparams);

$linktext = get_string('editdepartment', 'block_iomad_company_admin');

// Set the url.
$linkurl = new moodle_url('/blocks/iomad_company_admin/company_department_create_form.php');

$PAGE->set_context($context);
$PAGE->set_url($linkurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title($linktext);

// get output renderer                                                                                                                                                                                         
$output = $PAGE->get_renderer('block_iomad_company_admin');

// Set the page heading.
$PAGE->set_heading(get_string('name', 'local_iomad_dashboard') . " - $linktext");

// Build the nav bar.
company_admin_fix_breadcrumb($PAGE, $linktext, $linkurl);

// Set the companyid
$companyid = iomad::get_my_companyid($context);

// Set up the initial forms.
$mform = new department_display_form($PAGE->url, $companyid, $departmentid, $output);
$editform = new department_edit_form($PAGE->url, $companyid, $departmentid, $output);

// Delete any valid departments.
if ($deleteid && confirm_sesskey() && $confirm == md5($deleteid)) {
    // Get the list of department ids which are to be removed..
    if (!empty($deleteid)) {
        // Check if department has already been removed.
        if (company::check_valid_department($companyid, $deleteid)) {
            // If not delete it and its sub departments moving users to
            // $departmentid or the company parent id if not set (==0).
            company::delete_department_recursive($deleteid, $deleteid);
            redirect($linkurl);
        }
    }
}

if ($mform->is_cancelled()) {
    redirect($companylist);

} else if ($data = $mform->get_data()) {
    if (isset($data->create)) {
        // Javascript for fancy select.
        // Parameter is name of proper select form element. 
        $PAGE->requires->js_call_amd('block_iomad_company_admin/department_select', 'init', array('deptid', '', $departmentid));

        if (!empty($deleteids)) {
            $chosenid = $deleteids['0'];
        } else {
            $chosenid = 0;
        }
        $editform = new department_edit_form($PAGE->url, $companyid, 0, $output, $departmentid);
        $editform->set_data(array('deptid' => $departmentid));
        echo $output->header();
        // Check the department is valid.
        if (!empty($departmentid) && !company::check_valid_department($companyid, $departmentid)) {
            print_error('invaliddepartment', 'block_iomad_company_admin');
        }   

        $editform->display();
        echo $output->footer();
    } else if (isset($data->delete)) {
        // Check the department is valid.
        if (!empty($departmentid) && !company::check_valid_department($companyid, $departmentid)) {
            print_error('invaliddepartment', 'block_iomad_company_admin');
        }   

        if (empty($departmentid)) {
            echo get_string('departmentnoselect', 'block_iomad_company_admin');
        }

        echo $output->header();
        $departmentinfo = $DB->get_record('department', array('id' => $departmentid), '*', MUST_EXIST);
        echo $output->heading(get_string('deletedepartment', 'block_iomad_company_admin'));
        $optionsyes = array('deleteid' => $departmentid, 'confirm' => md5($departmentid), 'sesskey' => sesskey());
        echo $output->confirm(get_string('deletedepartmentcheckfull', 'block_iomad_company_admin', "'$departmentinfo->name'"),
                              new moodle_url('company_department_create_form.php', $optionsyes), 'company_department_create_form.php');
        echo $output->footer();
        die;
    } else if (isset($data->edit)) {
        // Editing an existing department.
        if (!empty($departmentid)) {
            $departmentrecord = $DB->get_record('department', array('id' => $departmentid));
            $editform = new department_edit_form($PAGE->url, $companyid, $departmentid, $output, $departmentrecord->parent, 1);
            $editform->set_data(array('departmentid' => $departmentrecord->id,
                                      'fullname' => $departmentrecord->name,
                                      'shortname' => $departmentrecord->shortname,
                                      'deptid' => $departmentrecord->parent));
            $PAGE->requires->js_call_amd('block_iomad_company_admin/department_select', 'init', array('deptid', '', $departmentrecord->parent));
            echo $output->header();

            // Check the department is valid.
            if (!empty($departmentid) && !company::check_valid_department($companyid, $departmentid)) {
                print_error('invaliddepartment', 'block_iomad_company_admin');
            }   

            $editform->display();

            echo $output->footer();
        } else {
            // Javascript for fancy select.
            // Parameter is name of proper select form element. 
            $PAGE->requires->js_call_amd('block_iomad_company_admin/department_select', 'init', array('deptid', '', $departmentid));

            echo $output->header();
            // Check the department is valid.
            if (!empty($departmentid) && !company::check_valid_department($companyid, $departmentid)) {
                print_error('invaliddepartment', 'block_iomad_company_admin');
            }   

            echo get_string('departmentnoselect', 'block_iomad_company_admin');
            $mform->display();
            echo $output->footer();
            die;
        }

    }
} else if ($createdata = $editform->get_data()) {
    // Create or update the department.
    if (!$createdata->action) {
        // We are creating a new department.
        company::create_department($createdata->departmentid,
                                   $companyid,
                                   $createdata->fullname,
                                   $createdata->shortname,
                                   $createdata->deptid);
    } else {
        // We are editing a current department.
        company::create_department($createdata->departmentid,
                                   $companyid,
                                   $createdata->fullname,
                                   $createdata->shortname,
                                   $createdata->deptid);
    }
    // Javascript for fancy select.
    // Parameter is name of proper select form element. 
    $PAGE->requires->js_call_amd('block_iomad_company_admin/department_select', 'init', array('deptid', '', $departmentid));

    $mform = new department_display_form($PAGE->url, $companyid, $departmentid, $output);
    // Redisplay the form.
    echo $output->header();

    // Check the department is valid.
    if (!empty($departmentid) && !company::check_valid_department($companyid, $departmentid)) {
        print_error('invaliddepartment', 'block_iomad_company_admin');
    }   

    $mform->display();

    echo $output->footer();

} else {
    // Javascript for fancy select.
    // Parameter is name of proper select form element. 
    $PAGE->requires->js_call_amd('block_iomad_company_admin/department_select', 'init', array('deptid', '', $departmentid));

    echo $output->header();
    // Check the department is valid.
    if (!empty($departmentid) && !company::check_valid_department($companyid, $departmentid)) {
        print_error('invaliddepartment', 'block_iomad_company_admin');
    }   

    $mform->display();

    echo $output->footer();
}

