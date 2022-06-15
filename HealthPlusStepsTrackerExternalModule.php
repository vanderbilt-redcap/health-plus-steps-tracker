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

    function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance) {
        include_once("fitbit.php");

        function addLink($fitbit) {
            $hyperlink = $fitbit->make_auth_link($this);

            // insert js to change [fitbit_auth_link] to the actual link that will send participant to Fitbit auth page
            ?>
            <script type='text/javascript'>
                $(function() {

                    var auth_url = "<?php echo $hyperlink; ?>";
                    $('#fitbit_btn').click(function(){
                        $("[name=submit-btn-saverecord]").show();
                    });
                    $('[name=options___radio]').click(function() {
                        if( $('#opt-options_3').is(':checked') ){
                            if($("#fitbit_btn").length == 0) {
                                $('input[name=fitbit_connect]').after("<div id='fitbit_btn'>" +
                                    "<a href='" + auth_url + "' target='_blank' " +
                                    "style='color: #fff;\n" +
                                    "    background-color: #007bff;\n" +
                                    "    border-color: #007bff;display: inline-block;\n" +
                                    "    text-decoration:none;\n" +
                                    "    font-weight: 400;\n" +
                                    "    text-align: center;\n" +
                                    "    white-space: nowrap;\n" +
                                    "    vertical-align: middle;-webkit-user-select: none;\n" +
                                    "    -moz-user-select: none;\n" +
                                    "    -ms-user-select: none;\n" +
                                    "    user-select: none;\n" +
                                    "    border: 1px solid transparent;\n" +
                                    "    padding: 0.375rem 0.75rem;\n" +
                                    "    font-size: 1rem;\n" +
                                    "    line-height: 1.5;\n" +
                                    "    border-radius: 0.25rem;\n" +
                                    "    transition: color .15s ease-in-out,background-color .15s ease-in-out,border-color .15s ease-in-out,box-shadow .15s ease-in-out;'>Enable Fitbit</a></div>");
                            }
                            $('input[name=fitbit_connect]').hide();
                        }
                        else{
                            $("#fitbit_btn").remove();
                        }
                    });
                })
            </script>
            <?php
        }

        function removeLink($date) {
            ?>
            <script type='text/javascript'>
                $(function() {
                    // remove submit button
                    // $("[name=submit-btn-saverecord]").remove()

                    var date = '<?php echo($date); ?>'
                    var span1 = "<h5>Your Fitbit account is linked to REDCap.</h5>";
                    var span2 = "<span>Your Fitbit account was linked at time: " + date + "</span>";
                    $("div.rich-text-field-label > p").replaceWith("<p>" + span1 + "<br>" + span2 + "</p>")
                })
            </script><?php
        }

        if ($instrument == "registration") {
            $fitbit = new \Fitbit($record,$this,$project_id);
            if (!$fitbit->auth_timestamp) {
                addLink($fitbit);
            } else {
                $date = date("Y-m-d H:i:s (O)", $fitbit->auth_timestamp);
                removeLink($date);
            }
        }
    }

    function update_steps($cronAttributes){
        include_once("fitbit.php");
        foreach ($this->getProjectsWithModuleEnabled() as $project_id) {
            $start_date = date("Y-m-d",strtotime($this->getProjectSetting('start_date',$project_id)));
            $end_date = date("Y-m-d",strtotime($this->getProjectSetting('end_date',$project_id)));
            $end_date_seven_days_date = date("Y-m-d",strtotime("+7 days",strtotime($this->getProjectSetting('end_date',$project_id))));
            $today = date ("Y-m-d");

            $record_ids = json_decode(\REDCap::getData($project_id,'json',null,'record_id'));
            foreach($record_ids as $record) {
                $rid = $record->record_id;
                $fitbit_obj = new \Fitbit($rid, $this,$project_id);
                #If the today is in the date range OR we need to check after +7 days for updates
                if ((strtotime($today) >= strtotime($start_date) && strtotime($today) <= strtotime($end_date)) || (strtotime($today) <= strtotime($end_date_seven_days_date))) {
                    while (strtotime($start_date) <= strtotime($today)) {
                        $seven_days_date = date("Y-m-d", strtotime("+7 days", strtotime($start_date)));
                        #only check date if it's no more than +7 days
                        if ($today <= $seven_days_date) {
                            $steps = $fitbit_obj->get_activity($start_date);
                            $this->save_steps($project_id, $rid, $start_date,$steps[1]);
                        }
                        $start_date = date("Y-m-d", strtotime("+1 days", strtotime($start_date)));
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
            foreach ($datai as $instance => $instance_data){
                if($instance_data['date_fitbit'] == $date){
                    $instanceId = $instance;
                    $instance_found = true;
                    break;
                }
            }
            if(!$instance_found) {
                $instanceId = count($datai) + 1;
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