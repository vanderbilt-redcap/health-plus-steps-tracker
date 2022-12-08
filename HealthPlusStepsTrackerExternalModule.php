<?php
namespace Vanderbilt\HealthPlusStepsTrackerExternalModule;

use REDCap;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class HealthPlusStepsTrackerExternalModule extends AbstractExternalModule
{

    public function __construct()
    {
        parent::__construct();
    }

    function redcap_survey_acknowledgement_page($project_id, $record, $instrument, $event_id){
        include_once("fitbit.php");

        $q = $this->query("SELECT value FROM redcap_data WHERE project_id=? AND field_name=? AND record=?",[$project_id,'options',$record]);
        $row = $q->fetch_assoc();
		if ($instrument == 'registration' && $row['value'] == '3') {
            $fitbit = new \Fitbit($record,$this,$project_id);
            if (!$fitbit->auth_timestamp) {
                $hyperlink = $fitbit->make_auth_link($this);

            }
            echo '<script>
                    parent.location.href = "'.$hyperlink .'"
                </script>';
        }
    }

    function update_steps($cronAttributes){
        include_once("fitbit.php");
        foreach ($this->getProjectsWithModuleEnabled() as $project_id) {
            $start_date = date("Y-m-d",strtotime($this->getProjectSetting('start_date',$project_id)));
            $end_date = date("Y-m-d",strtotime($this->getProjectSetting('end_date',$project_id)));
            $end_date_seven_days_date = date("Y-m-d",strtotime("+7 days",strtotime($this->getProjectSetting('end_date',$project_id))));
            $today = date ("Y-m-d");

            if($start_date != "" && $end_date != "") {
                $record_ids = json_decode(\REDCap::getData($project_id, 'json', null, 'record_id'));
                foreach ($record_ids as $record) {
                    $rid = $record->record_id;
                    $fitbit_obj = new \Fitbit($rid, $this, $project_id);
					
					if($fitbit_obj && $fitbit_obj->access_token) {
						#If the today is in the date range OR we need to check after +7 days for updates
						if ((strtotime($today) >= strtotime($start_date) && strtotime($today) <= strtotime($end_date)) || (strtotime($today) <= strtotime($end_date_seven_days_date))) {
							while (strtotime($start_date) <= strtotime($today)) {
								$seven_days_date = date("Y-m-d", strtotime("+7 days", strtotime($start_date)));
								#only check date if it's no more than +7 days
								if ($today <= $seven_days_date) {
									$steps = $fitbit_obj->get_activity($start_date);
									if($steps[0] && $steps[1]) {
										$this->save_steps($project_id, $rid, $start_date, $steps[1]);
									}
								}
								$start_date = date("Y-m-d", strtotime("+1 days", strtotime($start_date)));
							}
						}
					}
                }
            }
        }
    }

    function save_steps($project_id, $rid, $date, $steps){
        $Proj = new \Project($project_id);
        $event_id = $Proj->firstEventId;
        $start_date = date("Y-m-d",strtotime($this->getProjectSetting('start_date',$project_id)));

        #We asumen the fist instance it's the starting date if not we check by saved date and/or create new entries
        if($date == $start_date){
            $instanceId = 1;
        }else{
            $instance_found = false;
            $instance_data = \REDCap::getData($project_id,'array',$rid,'redcap_repeat_instance');
            $datai = $instance_data[$rid]['repeat_instances'][$event_id]['step_tracker'];
			
			## Set default value for $datai to prevent PHP8 errors
			$datai ??= [];
            foreach ($datai as $instance => $instance_data){
                if($instance_data['date_fitbit'] == $date){
                    $instanceId = $instance;
                    $instance_found = true;
                    break;
                }
            }
            if(!$instance_found) {
                $instanceId = datediff($date,$start_date,"d") + 1;
            }
        }

        $array_repeat_instances = array();
        $aux = array();
        $aux['date_fitbit'] = $date;
        if($steps[1] != null && $steps[1] != "") {
            $aux['steps_1'] = $steps;
        }else{
            $aux['steps_1'] = 0;
        }
        $array_repeat_instances[$rid]['repeat_instances'][$event_id]['step_tracker'][$instanceId] = $aux;
        $results = \REDCap::saveData($project_id, 'array', $array_repeat_instances,'overwrite', 'YMD', 'flat', '', true, true, true, false, true, array(), true, false, 1, false, '');
    }
}
?>