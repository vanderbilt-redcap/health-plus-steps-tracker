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

        $q = $this->query("SELECT value FROM ".$this->getDataTable($project_id)." WHERE project_id=? AND field_name=? AND record=?",[$project_id,'options',$record]);
        $row = $q->fetch_assoc();
		if ($instrument == 'registration' && $row['value'] == '3') {
            $fitbit = new Fitbit($record,$this,$project_id);
            if (!$fitbit->auth_timestamp) {
                $hyperlink = $fitbit->make_auth_link($this);

            }
            echo '<script>
                    parent.location.href = "'.$hyperlink .'"
                </script>';
        }
    }

    function getDataTable($project_id){
        return method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($project_id) : "redcap_data";
    }

    function update_steps($cronAttributes,$individualProject = false){
        include_once("fitbit.php");
		$today = time();
		
		foreach ($this->getProjectsWithModuleEnabled() as $project_id) {
			if($individualProject !== false && $project_id != $individualProject) {
				continue;
			}
            $start_date = date("Y-m-d",strtotime($this->getProjectSetting('start_date',$project_id)));
            $end_date = date("Y-m-d",strtotime($this->getProjectSetting('end_date',$project_id)));
	
			$seven_days_date = date("Y-m-d", strtotime("+7 days", strtotime($end_date)));
			
			if($start_date != "" && $end_date != "" &&
					$today > strtotime($start_date) &&
					($individualProject !== false || $today < strtotime($seven_days_date))) {
                $record_ids = \REDCap::getData($project_id, 'json-array', null, 'record_id');
                foreach ($record_ids as $record) {
                    $rid = $record["record_id"];
                    $fitbit_obj = new Fitbit($rid, $this, $project_id);
					if($fitbit_obj && $fitbit_obj->access_token) {
						$steps = $fitbit_obj->get_activity($start_date,$end_date);
						if($steps !== false) {
							$this->save_steps($project_id, $rid, $steps);
						}
					}
                }
            }
        }
    }

    function save_steps($project_id, $rid, $steps){
        $Proj = new \Project($project_id);
        $event_id = $Proj->firstEventId;
        $start_date = date("Y-m-d",strtotime($this->getProjectSetting('start_date',$project_id)));
	
		$array_repeat_instances = array();
		foreach($steps as $thisSteps) {
			$date = $thisSteps["dateTime"];
			$stepCount = $thisSteps["value"];
			
			#We assume the fist instance it's the starting date if not we check by saved date and/or create new entries
			if($date == $start_date){
				$instanceId = 1;
			}else{
				$instance_found = false;
				$instance_data = \REDCap::getData($project_id,'array',$rid,'redcap_repeat_instance');
				$datai = $instance_data[$rid]['repeat_instances'][$event_id]['step_tracker'];
				
				## Set default value for $datai to prevent PHP8 errors
				$datai = $datai ?: [];
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

			$aux = array();
			$aux['date_fitbit'] = $date;
			$aux['steps_1'] = $stepCount;
			
			$array_repeat_instances[$rid]['repeat_instances'][$event_id]['step_tracker'][$instanceId] = $aux;
		}
        $results = \REDCap::saveData($project_id, 'array', $array_repeat_instances,'overwrite', 'YMD', 'flat', '', true, true, true, false, true, array(), true, false, 1, false, '');
    }
}
?>